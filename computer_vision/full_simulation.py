"""Run recorded gates or live VIGI gates with the same sectional replay and API.

This runner writes the observed camera and simulation events to parking.db.
It starts a local PHP server when one is not running.
Cashier and staff actions remain available while the runner waits at exit.
"""

import argparse
from contextlib import contextmanager
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
VIDEO = ROOT / "computer_vision/simulation_videos"
DB = ROOT / "backend/database/parking.db"
DEFAULT_API = "http://127.0.0.1:8080/backend/api/endpoints.php"


def api_reachable(url):
    """An unauthenticated session request returning 401 still proves API health."""
    try:
        with urlopen(f"{url}?action=session", timeout=2) as response:
            return response.headers.get_content_type() == "application/json"
    except HTTPError as error:
        return (error.code in (401, 403) and
                error.headers.get_content_type() == "application/json")
    except (URLError, TimeoutError):
        return False


@contextmanager
def local_api_server(url):
    if api_reachable(url):
        print(f"Using running parking API at {url}", flush=True)
        yield
        return
    parsed = urlsplit(url)
    if (parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost")
            or parsed.path != "/backend/api/endpoints.php" or not parsed.port):
        raise RuntimeError(f"Parking API is unavailable at {url}; start its server before running")
    php = shutil.which("php")
    if not php:
        raise RuntimeError("PHP is unavailable; install it or start the parking API separately")
    bind = f"{parsed.hostname}:{parsed.port}"
    env = os.environ.copy()
    env.setdefault("LOCAL_PAYMENTS_ENABLED", "1")
    with tempfile.TemporaryFile(mode="w+t") as log:
        process = subprocess.Popen([php, "-S", bind, "backend/router.php"],
                                   cwd=ROOT, env=env, stdout=log, stderr=log)
        try:
            for _ in range(40):
                if api_reachable(url):
                    payment_note = "enabled" if env["LOCAL_PAYMENTS_ENABLED"] == "1" else "disabled"
                    print(f"Started local parking API at {url} (local simulated payments {payment_note}).", flush=True)
                    break
                if process.poll() is not None:
                    break
                time.sleep(.1)
            if not api_reachable(url):
                log.seek(0)
                detail = log.read()[-600:].strip()
                raise RuntimeError(f"Could not start local parking API at {bind}: {detail or 'no response'}")
            yield
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


def post_camera(url, token, action, payload):
    request = Request(f"{url}?action={action}", data=json.dumps(payload).encode(),
                      headers={"Content-Type": "application/json", "X-Exit-Event-Token": token},
                      method="POST")
    try:
        with urlopen(request, timeout=10) as response:
            result = json.load(response)
    except HTTPError as error:
        try:
            detail = json.load(error).get("error", error.reason)
        except (ValueError, OSError):
            detail = error.reason
        raise RuntimeError(f"{action}: HTTP {error.code}: {detail}") from error
    except URLError as error:
        raise RuntimeError(f"{action}: cannot reach parking API: {error.reason}") from error
    if not result.get("success"):
        raise RuntimeError(f"{action}: {result.get('error', result)}")
    return result


def current_session(plate, since_id=0, scenario=None):
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        db.row_factory = sqlite3.Row
        return db.execute("""SELECT ps.session_id,ps.payment_status,ps.exit_time,
                  ps.location_status,s.slot_number,s.zone,v.plate_number
                  FROM parking_sessions ps JOIN parking_slots s ON s.slot_id=ps.slot_id
                  JOIN vehicles v ON v.vehicle_id=ps.vehicle_id
                  LEFT JOIN simulation_sessions ss ON ss.session_id=ps.session_id
                  WHERE UPPER(REPLACE(REPLACE(v.plate_number,' ',''),'-',''))=?
                    AND ps.session_id>? AND (? IS NULL OR ss.scenario_id=?)
                  ORDER BY ps.session_id DESC LIMIT 1""",
                          (plate, since_id, scenario, scenario)).fetchone()


def latest_scenario_session(scenario, since_id):
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        db.row_factory = sqlite3.Row
        return db.execute("""SELECT ps.session_id,ps.payment_status,ps.entry_token,
                  ps.vehicle_color,ps.location_status,s.slot_number,s.zone,
                  v.plate_number,v.vehicle_type
                  FROM simulation_sessions ss JOIN parking_sessions ps ON ps.session_id=ss.session_id
                  JOIN parking_slots s ON s.slot_id=ps.slot_id
                  JOIN vehicles v ON v.vehicle_id=ps.vehicle_id
                  WHERE ss.scenario_id=? AND ps.session_id>?
                  ORDER BY ps.session_id DESC LIMIT 1""", (scenario, since_id)).fetchone()


def newest_session_id():
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        return db.execute("SELECT COALESCE(MAX(session_id),0) FROM parking_sessions").fetchone()[0]


def exit_stage_message(session_id, case):
    """Use the current session and citation state when prompting for exit replay."""
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        row = db.execute("""SELECT ps.payment_status,ps.vendor_scan_time,
            (SELECT COUNT(*) FROM violations vi
             JOIN parking_sessions source ON source.session_id=vi.session_id
             WHERE source.vehicle_id=ps.vehicle_id AND vi.status='UNPAID'
               AND COALESCE(vi.penalty_amount,0)>0) AS unpaid_fines
            FROM parking_sessions ps WHERE ps.session_id=?""", (session_id,)).fetchone()
    if not row:
        raise RuntimeError(f"Session {session_id} was not found before exit replay")
    status, vendor_scan, unpaid_fines = row
    if unpaid_fines:
        noun = "fee" if unpaid_fines == 1 else "fees"
        return f"Settle {unpaid_fines} outstanding violation {noun} in Cashier POS, then press Enter to replay the exit camera."
    if status in ("PAID", "PAID_RESERVATION", "PAID_OVERNIGHT", "BYPASSED"):
        return "Exit payment is clear. Press Enter to replay the exit camera."
    if status == "PAID_VENDOR" and vendor_scan:
        return "Tenant validation recorded. Press Enter to replay the exit camera."
    if case == "tenant":
        return "Complete tenant validation or pay the due fare in Cashier POS, then press Enter to replay the exit camera."
    if case == "grace":
        return "Complete the grace bypass in Cashier POS, then press Enter to replay the exit camera."
    return "Pay the due fare in Cashier POS, then press Enter to replay the exit camera."


def has_ready_reservation(plate, slot):
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        return db.execute("""SELECT 1 FROM reservations r
            JOIN vehicles v ON v.vehicle_id=r.vehicle_id
            JOIN parking_slots s ON s.slot_id=r.slot_id
            WHERE UPPER(REPLACE(REPLACE(v.plate_number,' ',''),'-',''))=?
              AND s.slot_number=? AND r.status='ACTIVE'
              AND r.payment_state IN ('PAID','ADMIN_EXEMPT') LIMIT 1""",
                          (plate, slot)).fetchone() is not None


def wait_for_entry(plate, since_id, timeout, scenario, process=None):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        session = current_session(plate, since_id, scenario)
        if session and session["location_status"] == "PARKED":
            return session
        if process and process.poll() is not None:
            raise RuntimeError(f"Live VIGI detector stopped with exit code {process.returncode}")
        time.sleep(.5)
    raise TimeoutError(f"No new entry session for {plate} within {timeout} seconds")


def replay_sectional(args, scenario, session_id, assigned_slot, token, phase="parking"):
    run_dir = ROOT / "backend/database/simulation_runs" / scenario
    run_dir.mkdir(parents=True, exist_ok=True)
    source = (args.departure_video if phase == "departure" else
              args.parking_video if args.five_clips else args.sectional_video)
    output_name = "departure_events.jsonl" if phase == "departure" else "sectional_events.jsonl"
    evidence_name = "departure_evidence" if phase == "departure" else "evidence"
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        last_frame = db.execute("SELECT COALESCE(MAX(frame_index), -1) FROM sectional_events "
                                "WHERE scenario_id=? AND camera_id=?", (scenario, args.camera_id)).fetchone()[0]
    frame_offset = int(last_frame) + 1
    observed_slot = (args.clip_slot if args.case == "reserved_slot"
                     or (args.zone == "A" and assigned_slot != args.clip_slot)
                     else assigned_slot)
    if phase == "departure":
        with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
            prior = db.execute("""SELECT o.occupancy_state FROM slot_observations o
                JOIN parking_slots s ON s.slot_id=o.slot_id
                WHERE o.scenario_id=? AND o.camera_id=? AND s.slot_number=?""",
                (scenario, args.camera_id, observed_slot)).fetchone()
        if not prior or prior[0] != "OCCUPIED":
            raise RuntimeError(f"Departure needs a prior occupied observation for {observed_slot}")
    command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
               "--source", str(source), "--camera-id", args.camera_id,
               "--config", str(args.regions), "--vehicle-model", str(args.vehicle_model),
               "--scenario-id", scenario, "--session-id", str(session_id),
               "--clip-plate", args.clip_plate or "", "--clip-slot", args.clip_slot or "",
               "--event-url", f"{args.api_url}?action=sectional_event", "--event-token", token,
               "--output", str(run_dir / output_name),
               "--evidence-dir", str(run_dir / evidence_name),
               "--sample-seconds", str(args.sample_seconds),
               "--start-seconds", str(0 if args.five_clips else args.baseline_seconds),
               "--frame-offset", str(frame_offset),
               "--target-slot", observed_slot,
               "--preview-label", "BAY DEPARTURE" if phase == "departure" else "PARKING REPLAY"]
    if phase == "departure":
        command += ["--stop-on-vacant", "--initial-target-occupied", "--publish-target-only"]
    else:
        command += ["--stop-after-occupied-seconds", str(args.parked_seconds)]
    if not args.no_preview:
        command.append("--preview")
    command += ["--plate-model", str(args.sectional_plate_model or ROOT / "computer_vision/best.pt")]
    print(f"Replaying {phase} clip ({args.camera_id}) for session {session_id}: assigned bay {assigned_slot}; "
          f"clip label {args.clip_plate or 'unknown plate'} at {args.clip_slot or 'unknown bay'}.", flush=True)
    result = subprocess.run(command, cwd=ROOT)
    if result.returncode:
        expected = ("occupied then vacant" if phase == "departure" else
                    f"occupied for {args.parked_seconds:g} continuous video seconds")
        raise RuntimeError(f"{phase.capitalize()} clip did not confirm {observed_slot} {expected}; "
                           f"see {run_dir / output_name}")
    expected_state = "VACANT" if phase == "departure" else "OCCUPIED"
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        observed = db.execute("""SELECT o.occupancy_state FROM slot_observations o
            JOIN parking_slots s ON s.slot_id=o.slot_id
            WHERE o.scenario_id=? AND o.camera_id=? AND s.slot_number=?""",
            (scenario, args.camera_id, observed_slot)).fetchone()
    if not observed or observed[0] != expected_state:
        raise RuntimeError(f"{phase.capitalize()} clip finished, but API has no {expected_state} "
                           f"observation for {observed_slot}")
    return run_dir


def check_recorded_zone_a_priority(args, scenario, session_id, run_dir, token):
    """Use the confirmed parking replay and its target-bay image for staff review."""
    if args.zone != "A":
        return
    target = args.clip_slot
    evidence = ""
    for line in (run_dir / "sectional_events.jsonl").read_text().splitlines():
        event = json.loads(line)
        if (event.get("event_type") == "sectional.slot.observed"
                and event.get("slot_number") == target
                and event.get("occupancy_state") == "OCCUPIED"):
            evidence = event.get("evidence", "")
    result = post_camera(args.api_url, token, "simulation_zone_a_priority_check", {
        "scenario_id": scenario, "session_id": session_id,
        "camera_id": args.camera_id, "slot_number": target, "evidence": evidence,
        "clip_plate": args.clip_plate or "",
    })
    if result["result"] == "PENDING_REVIEW":
        print(f"Zone A VIP / PWD priority alert #{result['alert_id']}: review the parked vehicle "
              "in Admin > Violations Log. Confirmed alerts add ₱200; rejected alerts add no fee. "
              "The simulation continues.", flush=True)
    elif result["result"] == "IDENTITY_UNKNOWN":
        print("Zone A priority status cannot be verified from an unreadable entrance plate; "
              "review the issued QR and vehicle identity with staff.", flush=True)
    else:
        print("Zone A VIP / PWD priority eligibility verified.", flush=True)


def advance_simulation_clock(args, scenario, session_id, token, stage_name):
    result = post_camera(args.api_url, token, "simulation_clock", {
        "scenario_id": scenario, "session_id": session_id, "stage": stage_name,
    })
    print(f"Simulation-only time adjustment recorded for {stage_name}: "
          f"{result['effective_timestamp']}.", flush=True)


def remind_if_grace_expired(session_id):
    """Warn only when an unpaid grace test has passed 15 minutes since entry."""
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        row = db.execute("""SELECT payment_status,
            (julianday('now')-julianday(entry_time))*1440 AS elapsed_minutes
            FROM parking_sessions WHERE session_id=?""",(session_id,)).fetchone()
    if row and row[0] == "UNPAID" and row[1] is not None and row[1] > 15:
        print("Grace reminder: this session has passed 15 minutes since entrance; "
              "the ₱0 bypass may no longer be available. Check the cashier bill.", flush=True)
        return True
    return False


def run_violation_clip(args, scenario, session_id, token, plate):
    run_dir = ROOT / "backend/database/simulation_runs" / scenario
    run_dir.mkdir(parents=True, exist_ok=True)
    command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
               "--source", str(args.violation_video), "--camera-id", args.violation_camera_id,
               "--config", str(args.violation_regions), "--vehicle-model", str(args.vehicle_model),
               "--plate-model", str(args.sectional_plate_model or ROOT / "computer_vision/best.pt"),
               "--scenario-id", scenario, "--session-id", str(session_id),
               "--clip-plate", plate, "--clip-slot", args.clip_slot,
               "--event-url", f"{args.api_url}?action=sectional_event", "--event-token", token,
               "--output", str(run_dir / "violation_events.jsonl"),
               "--evidence-dir", str(run_dir / "violation_evidence"),
               "--sample-seconds", str(args.sample_seconds), "--grace-seconds", "20",
               "--max-observation-gap", str(args.sample_seconds * 1.5),
               "--max-seconds", str(args.violation_seconds),
               "--preview-label", "ZONE C 20-SECOND ENFORCEMENT TEST"]
    if not args.no_preview:
        command.append("--preview")
    print("Replaying the recorded Zone C restricted-area clip with the 20-second enforcement threshold.", flush=True)
    result = subprocess.run(command, cwd=ROOT)
    if result.returncode:
        raise RuntimeError(f"Zone C violation clip did not produce a 20-second alert; "
                           f"see {run_dir / 'violation_events.jsonl'}")
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        alert = db.execute("""SELECT alert_id FROM sectional_alerts WHERE scenario_id=?
            AND session_id=? AND violation_type IN ('DRIVEWAY','DOUBLE_PARKING')
            AND status='PENDING_REVIEW' ORDER BY alert_id DESC LIMIT 1""",
                           (scenario, session_id)).fetchone()
    if not alert:
        raise RuntimeError("Zone C clip ended without a pending enforcement review alert")
    print(f"Staff review required for sectional alert {alert[0]} before exit.", flush=True)
    return int(alert[0])


def wait_for_alert_review(scenario, alert_id, timeout=900):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
            row = db.execute("SELECT status,violation_id FROM sectional_alerts WHERE alert_id=? AND scenario_id=?",
                             (alert_id, scenario)).fetchone()
        if not row:
            raise RuntimeError(f"Sectional alert {alert_id} disappeared")
        if row[0] != "PENDING_REVIEW":
            return row[0], row[1]
        time.sleep(.5)
    raise TimeoutError(f"Staff did not review sectional alert {alert_id} within {timeout} seconds")


def capture_baseline(args, scenario, token):
    """Store a run-scoped, pre-entry sectional snapshot in slot_observations."""
    run_dir = ROOT / "backend/database/simulation_runs" / scenario
    discovered_config = run_dir / "observed_regions.json"
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        old = db.execute("SELECT 1 FROM slot_observations WHERE scenario_id=? LIMIT 1", (scenario,)).fetchone()
    if old:
        raise RuntimeError(f"Scenario {scenario} already has sectional observations; use a new --scenario-id")
    run_dir.mkdir(parents=True, exist_ok=True)
    command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
               "--source", str(args.baseline_video if args.five_clips else args.sectional_video),
               "--camera-id", args.camera_id,
               "--config", str(args.regions), "--vehicle-model", str(args.vehicle_model),
               "--plate-model", str(args.sectional_plate_model or ROOT / "computer_vision/best.pt"),
               "--scenario-id", scenario, "--max-seconds", str(args.baseline_seconds),
               "--sample-seconds", str(args.baseline_sample_seconds),
               "--discover-slots", "--discovered-config", str(discovered_config),
               "--event-url", f"{args.api_url}?action=sectional_event", "--event-token", token,
               "--output", str(run_dir / "baseline_events.jsonl"),
               "--evidence-dir", str(run_dir / "baseline_evidence"),
               "--preview-label", "PRE-ENTRY BASELINE"]
    if not args.no_preview:
        command.append("--preview")
    print(f"Capturing pre-entry sectional baseline for {scenario}...", flush=True)
    subprocess.run(command, cwd=ROOT, check=True)
    args.regions = discovered_config
    zone = args.zone or (args.clip_slot or "").split("-", 1)[0]
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        rows = db.execute("""SELECT s.slot_number,o.occupancy_state FROM slot_observations o
            JOIN parking_slots s ON s.slot_id=o.slot_id WHERE o.scenario_id=? AND s.is_active=1
            AND s.zone=? ORDER BY s.slot_number""", (scenario, zone)).fetchall()
        active_count = db.execute("SELECT COUNT(*) FROM parking_slots WHERE zone=? AND is_active=1",
                                  (zone,)).fetchone()[0]
    if not rows:
        raise RuntimeError("Baseline produced no active sectional bays; check clip, calibration, and duration")
    print("Sectional baseline: " + ", ".join(f"{slot} {state.lower()}" for slot, state in rows), flush=True)
    print(f"Observed {len(rows)} of {active_count} active Zone {zone} bays; "
          f"the remaining {active_count - len(rows)} have unknown physical occupancy.", flush=True)
    if zone == "A":
        print("Simulation assumption: vehicles already parked in the Zone A snapshot "
              "are VIP / PWD authorized. Eligibility review applies to the newly "
              "entering vehicle after its parking clip.", flush=True)
    if args.clip_slot:
        observed_state = dict(rows).get(args.clip_slot)
        if observed_state != "VACANT":
            raise RuntimeError(
                f"Recorded target {args.clip_slot} was {observed_state or 'not observed'} "
                f"in the Zone {zone} snapshot; the parking clip cannot use this bay")
        print(f"Recorded target {args.clip_slot} acknowledged as vacant by the Zone {zone} snapshot.",
              flush=True)
    return run_dir


def capture_all_zone_baselines(args, token):
    """Persist A-E snapshot observations before the recorded entrance feed."""
    from computer_vision.recorded_test_cases.profiles import ZONES, REGIONS
    for zone, profile in ZONES.items():
        scenario = args.scenario_id if zone == args.zone else f"snapshot_{zone.lower()}_{uuid.uuid4().hex[:10]}"
        keep_active = zone == args.zone
        post_camera(args.api_url, token, "simulation_occupancy_start", {
            "scenario_id": scenario, "zone": zone, "persist_results": True,
        })
        try:
            if zone == args.zone:
                capture_baseline(args, scenario, token)
            else:
                run_dir = ROOT / "backend/database/simulation_runs" / scenario
                run_dir.mkdir(parents=True, exist_ok=True)
                discovered = run_dir / "observed_regions.json"
                command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
                           "--source", str(profile["baseline"]),
                           "--camera-id", profile["camera"],
                           "--config", str(REGIONS / f"zone_{zone.lower()}.json"),
                           "--vehicle-model", str(args.vehicle_model),
                           "--plate-model", str(args.sectional_plate_model or ROOT / "computer_vision/best.pt"),
                           "--scenario-id", scenario,
                           "--max-seconds", "3", "--sample-seconds", ".5",
                           "--discover-slots", "--discovered-config", str(discovered),
                           "--event-url", f"{args.api_url}?action=sectional_event",
                           "--event-token", token,
                           "--output", str(run_dir / "baseline_events.jsonl"),
                           "--evidence-dir", str(run_dir / "baseline_evidence"),
                           "--preview-label", f"ZONE {zone} SNAPSHOT"]
                if not args.no_preview:
                    command.append("--preview")
                print(f"Capturing Zone {zone} snapshot...", flush=True)
                subprocess.run(command,cwd=ROOT,check=True)
            with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
                observed = db.execute("""SELECT COUNT(*) FROM slot_observations o
                    JOIN parking_slots s ON s.slot_id=o.slot_id
                    WHERE o.scenario_id=? AND s.zone=? AND s.is_active=1""",(scenario,zone)).fetchone()[0]
                total = db.execute("SELECT COUNT(*) FROM parking_slots WHERE zone=? AND is_active=1",
                                   (zone,)).fetchone()[0]
                conflicts = db.execute("""SELECT COUNT(*) FROM slot_observations o
                    JOIN parking_slots s ON s.slot_id=o.slot_id
                    WHERE o.scenario_id=? AND s.zone=? AND o.occupancy_state='VACANT'
                    AND EXISTS (SELECT 1 FROM parking_sessions ps WHERE ps.slot_id=s.slot_id
                        AND ps.exit_time IS NULL AND ps.location_status='PARKED')""",
                    (scenario,zone)).fetchone()[0]
            print(f"Zone {zone}: {observed}/{total} active bays observed; "
                  f"{total-observed} unverified; {conflicts} occupied-session conflicts.",flush=True)
        except BaseException:
            post_camera(args.api_url, token, "simulation_occupancy_finish",
                        {"scenario_id": scenario})
            raise
        else:
            if not keep_active:
                post_camera(args.api_url, token, "simulation_occupancy_finish",
                            {"scenario_id": scenario})


def stage(args, message):
    if not args.auto_advance:
        input(message + " Press Enter to continue (Ctrl+C to stop).")


def recorded(args, scenario, token):
    try:
        from .scenario5 import process_video_feed
    except ImportError:
        from scenario5 import process_video_feed
    entry_view = process_video_feed(str(args.entry_video), str(ROOT / "computer_vision/best.pt"),
                                    "Recorded entrance", "entrance", preview=not args.no_preview,
                                    vehicle_model_path=args.vehicle_model, return_observation=True)
    plate = entry_view["plate"]
    if not plate and args.case == "reservation" and not args.entry_qr_token:
        raise RuntimeError("Entrance plate unreadable; use the reservation's issued QR with --entry-qr-token to link its paid bay")
    qr_only = not plate and not args.entry_qr_token
    if plate and args.plate and plate != args.plate:
        raise RuntimeError(f"Entrance read {plate}, expected {args.plate}; session was not created")
    if args.case == "reservation" and plate and not has_ready_reservation(plate, args.clip_slot):
        raise RuntimeError(f"Create and pay the {args.clip_slot} reservation for {plate} in the frontend before starting this case")
    entry = post_camera(args.api_url, token, "camera_entry_event", {
        "plate_number": plate or "", "qr_token": args.entry_qr_token or "",
        "qr_only": qr_only,
        "vehicle_color": entry_view["vehicle_color"],
        "color_confidence": entry_view["color_confidence"],
        "channel": 1, "scenario_id": scenario,
        "preferred_slot": "" if args.case == "reserved_slot" else (args.preferred_slot or args.clip_slot),
        "allocation_zone": args.zone if args.case == "reserved_slot" else "",
        "reserved_bay_test": args.case == "reserved_slot",
        "clip_slot": args.clip_slot,
        "observed_at": datetime.now(timezone.utc).isoformat()})
    plate = entry["plate_number"]
    qr_only = bool(entry.get("qr_only", qr_only))
    if not qr_only:
        args.clip_plate = args.clip_plate or plate
    if args.plate and plate != args.plate and not qr_only:
        raise RuntimeError(f"Entry QR matched {plate}, expected {args.plate}; session was created and needs staff review")
    session_id = int(entry["session_id"])
    print(f"Entry: {plate}; session {session_id}; assigned {entry['slot_number']}; payment {entry['payment_status']}.", flush=True)
    if entry.get("duplicate"):
        raise RuntimeError("Plate already has an active session; use a fresh test plate or close the existing session")
    try:
        from .qr_ticket import print_entry_ticket
    except ImportError:
        from qr_ticket import print_entry_ticket
    print_entry_ticket(entry["entry_token"], session_id, entry["slot_number"],
                       entry.get("vehicle_color", "UNKNOWN"),
                       ROOT / "backend/database/simulation_runs" / scenario)
    if qr_only:
        print("Entrance plate unreadable: this session requires its issued QR at exit.", flush=True)
    stage(args, "Entry allocated. Begin the parking portion of the sectional clip.")
    run_dir = replay_sectional(args, scenario, session_id, entry["slot_number"], token)
    print(f"Sectional events and evidence: {run_dir}", flush=True)
    check_recorded_zone_a_priority(args, scenario, session_id, run_dir, token)
    violation_alert = None
    if args.case == "enforcement":
        stage(args, "Parking confirmed. Begin the Zone C violation feed.")
        violation_alert = run_violation_clip(args, scenario, session_id, token, plate)
    if args.five_clips and args.case != "enforcement":
        stage(args, "Parking confirmed. Begin the clip showing the vehicle leaving its bay.")
        replay_sectional(args, scenario, session_id, entry["slot_number"], token,
                         phase="departure")
        print(f"Bay departure confirmed: {args.clip_slot} is vacant.", flush=True)
    if args.case:
        from importlib import import_module
        rule = import_module(f"computer_vision.recorded_test_cases.{args.case}")
        rule.apply_business_rule(args, scenario, session_id, token, entry,
                                 violation_alert=violation_alert)
    print(exit_stage_message(session_id, args.case), flush=True)
    input()
    if args.case == "grace":
        remind_if_grace_expired(session_id)
    while True:
        exit_view = process_video_feed(str(args.exit_video), str(ROOT / "computer_vision/best.pt"),
                                       "Recorded exit", "exit", preview=not args.no_preview,
                                       vehicle_model_path=args.vehicle_model, return_observation=True)
        exit_plate = exit_view["plate"]
        if qr_only:
            print("QR-only entry: scan the printed entry QR in Cashier POS to record exit. "
                  f"Exit-camera color: {exit_view['vehicle_color']}.", flush=True)
            input("Press Enter after the QR exit is recorded (Ctrl+C to stop).")
            state = current_session(plate, scenario=scenario)
            if state and state["location_status"] == "EXITED":
                print(f"QR exit cleared: session {state['session_id']}; slot released.", flush=True)
                break
            raise RuntimeError("QR fallback has not closed the active session")
        if not exit_plate:
            print(f"Exit plate unreadable. Observed color: {exit_view['vehicle_color']}. "
                  "Scan the issued entry QR in Cashier POS for staff fallback.", flush=True)
            input("Press Enter after the QR exit is recorded (Ctrl+C to stop).")
            state = current_session(plate, scenario=scenario)
            if state and state["location_status"] == "EXITED":
                print(f"QR exit cleared: session {state['session_id']}; slot released.", flush=True)
                break
            raise RuntimeError("QR fallback has not closed the active session")
        if exit_plate != plate:
            raise RuntimeError(f"Exit read {exit_plate}, but entry was {plate}")
        try:
            departure = post_camera(args.api_url, token, "exit_camera_event", {
                "plate_number": plate, "channel": 13,
                "vehicle_color": exit_view["vehicle_color"],
                "color_confidence": exit_view["color_confidence"],
                "observed_at": datetime.now(timezone.utc).isoformat()})
            print(f"Exit cleared: session {departure['session_id']}; slot released.", flush=True)
            break
        except RuntimeError as error:
            print(f"Exit denied: {error}", flush=True)
            if input("Resolve in cashier/staff UI, then press Enter to retry (q to stop): ").strip().lower() == "q":
                raise


def live_gates(args, scenario, token):
    if args.clip_plate and args.plate and args.clip_plate != args.plate:
        print("Clip plate differs from live plate; sectional identity will remain unknown.", flush=True)
    since_id = newest_session_id()
    env = os.environ.copy()
    env["PARKING_SCENARIO_ID"] = scenario
    env["PARKING_PREFERRED_SLOT"] = args.preferred_slot or args.clip_slot or ""
    env["PARKING_EXIT_EVENT_TOKEN"] = token
    env["PARKING_EXIT_EVENT_URL"] = f"{args.api_url}?action=exit_camera_event"
    command = [sys.executable, str(ROOT / "computer_vision/live_vigi.py"),
               "--vehicle-model", str(args.vehicle_model)]
    if args.no_preview:
        command.append("--no-preview")
    process = subprocess.Popen(command, cwd=ROOT, env=env)
    try:
        deadline = time.monotonic() + args.entry_timeout
        session = None
        while time.monotonic() < deadline:
            session = latest_scenario_session(scenario, since_id)
            if session:
                break
            if process.poll() is not None:
                raise RuntimeError("Live camera process stopped before entry")
            time.sleep(.5)
        if not session:
            raise TimeoutError("No live entrance session was created")
        if (args.plate and session["vehicle_type"] != "QR_ONLY"
                and session["plate_number"] != args.plate):
            raise RuntimeError(f"Live entrance read {session['plate_number']}, expected {args.plate}")
        session_id = session["session_id"]
        print(f"Live entry: session {session_id}, assigned {session['slot_number']}.", flush=True)
        stage(args, "Live entry allocated. Begin the parking portion of the sectional clip.")
        run_dir = replay_sectional(args, scenario, session_id, session["slot_number"], token)
        print(f"Sectional events and evidence: {run_dir}", flush=True)
        print("Complete payment/validation in the app, then drive past exit channel 13.", flush=True)
        deadline = time.monotonic() + args.exit_timeout
        while time.monotonic() < deadline:
            state = current_session(session["plate_number"], since_id, scenario)
            if state and state["session_id"] == session_id and state["location_status"] == "EXITED":
                print(f"Live exit cleared: session {session_id}; slot released.", flush=True)
                return
            if process.poll() is not None:
                raise RuntimeError(f"Live VIGI detector stopped with exit code {process.returncode}")
            time.sleep(1)
        raise TimeoutError(f"No cleared live exit for session {session_id} within {args.exit_timeout} seconds")
    finally:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["recorded", "live-gates"])
    parser.add_argument("--scenario-id")
    parser.add_argument("--zone", choices=["A", "B", "C", "D", "E"], help="Recorded test zone profile")
    parser.add_argument("--case", choices=["grace", "normal_fare", "reservation", "reserved_slot", "tenant", "enforcement"])
    parser.add_argument("--plate", help="Optional expected test plate for entrance verification")
    parser.add_argument("--entry-qr-token", help="Issued reservation QR when entrance ALPR cannot read the plate")
    parser.add_argument("--clip-plate", help="Labeled vehicle in sectional recording; defaults to entrance plate")
    parser.add_argument("--clip-slot", help="Bay shown by the sectional clip")
    parser.add_argument("--entry-video", type=Path)
    parser.add_argument("--sectional-video", type=Path)
    parser.add_argument("--baseline-video", type=Path, help="Pre-entry sectional snapshot clip")
    parser.add_argument("--parking-video", type=Path, help="Clip showing the vehicle parking in its assigned bay")
    parser.add_argument("--departure-video", type=Path, help="Clip showing that bay occupied, then vacant")
    parser.add_argument("--exit-video", type=Path)
    parser.add_argument("--violation-video", type=Path)
    parser.add_argument("--violation-seconds", type=float, default=35.0)
    parser.add_argument("--regions", type=Path)
    parser.add_argument("--violation-regions", type=Path)
    parser.add_argument("--camera-id")
    parser.add_argument("--vehicle-model", type=Path, default=ROOT / "yolov8n.pt")
    parser.add_argument("--sectional-plate-model", type=Path)
    parser.add_argument("--sample-seconds", type=float, default=1.0)
    parser.add_argument("--baseline-seconds", type=float, default=.12,
                        help="Seconds from start of sectional clip used for pre-entry snapshot")
    parser.add_argument("--baseline-sample-seconds", type=float, default=.04,
                        help="Video seconds between pre-entry occupancy samples")
    parser.add_argument("--parked-seconds", type=float, default=10.0,
                        help="Continuous occupied video seconds required to confirm parking")
    parser.add_argument("--preferred-slot", help="Vacant bay to allocate for this simulation; defaults to clip slot")
    parser.add_argument("--tenant-expired", action="store_true",
                        help="Exercise the expired tenant departure window instead of the active window")
    parser.add_argument("--auto-advance", action="store_true", help="Skip manual stage controls")
    parser.add_argument("--api-url", default=DEFAULT_API)
    parser.add_argument("--no-preview", action="store_true")
    parser.add_argument("--entry-timeout", type=int, default=600)
    parser.add_argument("--exit-timeout", type=int, default=1800)
    args = parser.parse_args(argv)
    profile = None
    if args.zone or args.case:
        if not args.zone or not args.case or args.mode != "recorded":
            parser.error("recorded case profiles require both --zone and --case")
        from computer_vision.recorded_test_cases.profiles import profile as get_profile
        try:
            profile = get_profile(args.zone, args.case)
        except ValueError as error:
            parser.error(str(error))
        args.entry_video = args.entry_video or profile["entry"]
        args.exit_video = args.exit_video or profile["exit"]
        args.parking_video = args.parking_video or profile["parking"]
        args.baseline_video = args.baseline_video or profile["baseline"]
        if args.baseline_seconds == .12:
            args.baseline_seconds = 3.0
        if args.baseline_sample_seconds == .04:
            args.baseline_sample_seconds = .5
        args.departure_video = (None if args.case == "enforcement" else
                                args.departure_video or profile["departure"])
        args.clip_slot = args.clip_slot or profile["slot"]
        args.camera_id = args.camera_id or profile["camera"]
        args.regions = args.regions or profile["regions"]
        args.violation_video = args.violation_video or profile.get("violation")
        args.violation_camera_id = profile.get("violation_camera", args.camera_id)
        args.violation_regions = args.violation_regions or profile.get("violation_regions")
        if args.case != "reserved_slot":
            args.preferred_slot = args.preferred_slot or profile["slot"]
        args.scenario_id = args.scenario_id or f"{profile['scenario_prefix']}_{uuid.uuid4().hex[:10]}"
        if not args.scenario_id.lower().startswith(profile["scenario_prefix"] + "_"):
            parser.error(f"scenario ID for this entry point must start with {profile['scenario_prefix']}_")
    else:
        args.entry_video = args.entry_video or VIDEO / "ENTRANCE_XPANDER.mov"
        args.exit_video = args.exit_video or VIDEO / "EXIT_XPANDER.mov"
        args.sectional_video = args.sectional_video or VIDEO / "SectOcc_Sample1.mp4"
        args.regions = args.regions or ROOT / "computer_vision/sectocc_sample1_regions.json"
        args.camera_id = args.camera_id or "section_e_sample1"
        args.violation_camera_id = args.camera_id
        args.violation_regions = args.regions
        args.clip_slot = args.clip_slot or "E-05"
        args.scenario_id = args.scenario_id or "sim-" + uuid.uuid4().hex[:12]
    if args.case == "enforcement" and args.zone != "C":
        parser.error("the recorded enforcement test exists only for Zone C")
    if args.case == "enforcement" and not args.violation_video:
        parser.error("Zone C enforcement clip is missing")
    required_clips = ((args.baseline_video, args.parking_video) if args.case == "enforcement"
                      else (args.baseline_video, args.parking_video, args.departure_video))
    args.five_clips = all(path is not None for path in required_clips)
    if any(path is not None for path in required_clips) and not args.five_clips:
        parser.error("recorded mode needs a baseline and parking clip; other cases also need a departure clip")
    if args.five_clips and args.mode != "recorded":
        parser.error("five-clip mode currently requires recorded entrance and exit feeds")
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", args.scenario_id):
        parser.error("scenario ID must be 1-64 letters, digits, underscores, or hyphens")
    for field in ("plate", "clip_plate"):
        value = getattr(args, field)
        if value and not re.fullmatch(r"[A-Z]{3}[0-9]{3,4}", value):
            parser.error(f"--{field.replace('_','-')} must be a normalized plate")
    if args.preferred_slot and not re.fullmatch(r"[A-F]-[0-9]{2}", args.preferred_slot):
        parser.error("--preferred-slot must look like E-05")
    for path in (args.sectional_video, args.baseline_video, args.parking_video,
                 args.departure_video, args.regions, args.violation_regions, args.vehicle_model, args.violation_video):
        if path is None:
            continue
        if not path.is_file():
            parser.error(f"missing file: {path}")
    if args.mode == "recorded":
        for path in (args.entry_video, args.exit_video):
            if not path.is_file():
                parser.error(f"missing file: {path}")
    if args.sectional_plate_model and not args.sectional_plate_model.is_file():
        parser.error(f"missing sectional plate model: {args.sectional_plate_model}")
    if args.sample_seconds <= 0 or args.baseline_sample_seconds <= 0 or args.baseline_seconds <= 0 or args.parked_seconds <= 0 or args.entry_timeout <= 0 or args.exit_timeout <= 0 or args.violation_seconds <= 0:
        parser.error("sample interval and timeouts must be positive")
    parsed_api = urlsplit(args.api_url)
    if (parsed_api.scheme != "http" or parsed_api.hostname not in ("127.0.0.1","localhost")
            or parsed_api.path != "/backend/api/endpoints.php" or not parsed_api.port):
        parser.error("persistent simulations require a local parking API URL")
    token = os.environ.get("PARKING_EXIT_EVENT_TOKEN", "")
    if not token:
        token_file = ROOT / "backend/database/exit_camera_token"
        token = token_file.read_text().strip() if token_file.is_file() else ""
    if len(token) < 32:
        parser.error("camera event token is unavailable")
    try:
        with local_api_server(args.api_url):
            if args.plate:
                active = current_session(args.plate)
                if active and active["exit_time"] is None and active["location_status"] == "PARKED":
                    raise RuntimeError(f"Plate {args.plate} already has open session {active['session_id']} in {active['slot_number']}; complete an exit or use Admin > Simulation Sessions recovery before starting a new run")
            target_started = False
            try:
                zone = args.zone or (args.clip_slot or "").split("-",1)[0]
                post_camera(args.api_url, token, "simulation_occupancy_start", {
                    "scenario_id":args.scenario_id,"zone":zone,"persist_results":True})
                target_started = True
                capture_baseline(args,args.scenario_id,token)
                stage(args, f"Zone {zone} snapshot saved in the main database. Begin the entrance feed.")
                (recorded if args.mode == "recorded" else live_gates)(args, args.scenario_id, token)
            finally:
                if target_started:
                    post_camera(args.api_url,token,"simulation_occupancy_finish",
                                {"scenario_id":args.scenario_id})
    except (RuntimeError, TimeoutError, subprocess.CalledProcessError, KeyboardInterrupt) as error:
        parser.exit(1, f"Simulation stopped: {error}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
