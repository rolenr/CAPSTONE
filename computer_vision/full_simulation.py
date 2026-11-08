"""Run recorded gates or live VIGI gates with the same sectional replay and API.

This runner uses the existing parking.db and the same camera endpoints as
production detection. It starts a local PHP server when one is not running.
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


def newest_session_id():
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        return db.execute("SELECT COALESCE(MAX(session_id),0) FROM parking_sessions").fetchone()[0]


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


def replay_sectional(args, scenario, session_id, assigned_slot, token):
    run_dir = ROOT / "backend/database/simulation_runs" / scenario
    run_dir.mkdir(parents=True, exist_ok=True)
    command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
               "--source", str(args.sectional_video), "--camera-id", args.camera_id,
               "--config", str(args.regions), "--vehicle-model", str(args.vehicle_model),
               "--scenario-id", scenario, "--session-id", str(session_id),
               "--clip-plate", args.clip_plate or "", "--clip-slot", args.clip_slot or "",
               "--event-url", f"{args.api_url}?action=sectional_event", "--event-token", token,
               "--output", str(run_dir / "sectional_events.jsonl"),
               "--evidence-dir", str(run_dir / "evidence"),
               "--sample-seconds", str(args.sample_seconds),
               "--start-seconds", str(args.baseline_seconds),
               "--target-slot", assigned_slot,
               "--stop-after-occupied-seconds", str(args.parked_seconds),
               "--preview-label", "PARKING REPLAY"]
    if not args.no_preview:
        command.append("--preview")
    if args.sectional_plate_model:
        command += ["--plate-model", str(args.sectional_plate_model)]
    print(f"Replaying sectional clip ({args.camera_id}) for session {session_id}: assigned bay {assigned_slot}; "
          f"clip label {args.clip_plate or 'unknown plate'} at {args.clip_slot or 'unknown bay'}.", flush=True)
    result = subprocess.run(command, cwd=ROOT)
    if result.returncode:
        raise RuntimeError(f"Parking was not confirmed in {assigned_slot} for {args.parked_seconds:g} "
                           f"continuous video seconds; see {run_dir / 'sectional_events.jsonl'}")
    return run_dir


def capture_baseline(args, scenario, token):
    """Store a run-scoped, pre-entry sectional snapshot in slot_observations."""
    run_dir = ROOT / "backend/database/simulation_runs" / scenario
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        old = db.execute("SELECT 1 FROM slot_observations WHERE scenario_id=? LIMIT 1", (scenario,)).fetchone()
    if old:
        raise RuntimeError(f"Scenario {scenario} already has sectional observations; use a new --scenario-id")
    run_dir.mkdir(parents=True, exist_ok=True)
    command = [sys.executable, str(ROOT / "computer_vision/sectional_occupancy.py"),
               "--source", str(args.sectional_video), "--camera-id", args.camera_id,
               "--config", str(args.regions), "--vehicle-model", str(args.vehicle_model),
               "--scenario-id", scenario, "--max-seconds", str(args.baseline_seconds),
               "--sample-seconds", str(args.sample_seconds),
               "--event-url", f"{args.api_url}?action=sectional_event", "--event-token", token,
               "--output", str(run_dir / "baseline_events.jsonl"),
               "--evidence-dir", str(run_dir / "baseline_evidence"),
               "--preview-label", "PRE-ENTRY BASELINE"]
    if not args.no_preview:
        command.append("--preview")
    print(f"Capturing pre-entry sectional baseline for {scenario}...", flush=True)
    subprocess.run(command, cwd=ROOT, check=True)
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        rows = db.execute("""SELECT s.slot_number,o.occupancy_state FROM slot_observations o
            JOIN parking_slots s ON s.slot_id=o.slot_id WHERE o.scenario_id=? AND s.is_active=1
            ORDER BY s.slot_number""", (scenario,)).fetchall()
    if not rows:
        raise RuntimeError("Baseline produced no active sectional bays; check clip, calibration, and duration")
    print("Sectional baseline: " + ", ".join(f"{slot} {state.lower()}" for slot, state in rows), flush=True)
    preferred = args.preferred_slot or args.clip_slot
    if preferred and (preferred, "VACANT") not in rows:
        raise RuntimeError(f"Requested bay {preferred} is not vacant in the baseline")
    return run_dir


def stage(args, message):
    if not args.auto_advance:
        input(message + " Press Enter to continue (Ctrl+C to stop).")


def recorded(args, scenario, token):
    try:
        from .scenario5 import process_video_feed
    except ImportError:
        from scenario5 import process_video_feed
    plate = process_video_feed(str(args.entry_video), str(ROOT / "computer_vision/best.pt"),
                               "Recorded entrance", "entrance", preview=not args.no_preview)
    if not plate:
        raise RuntimeError("Recorded entrance did not produce a confirmed plate")
    if args.plate and plate != args.plate:
        raise RuntimeError(f"Entrance read {plate}, expected {args.plate}; session was not created")
    entry = post_camera(args.api_url, token, "camera_entry_event", {
        "plate_number": plate, "channel": 1, "scenario_id": scenario,
        "preferred_slot": args.preferred_slot or args.clip_slot,
        "observed_at": datetime.now(timezone.utc).isoformat()})
    session_id = int(entry["session_id"])
    print(f"Entry: {plate}; session {session_id}; assigned {entry['slot_number']}; payment {entry['payment_status']}.", flush=True)
    if entry.get("duplicate"):
        raise RuntimeError("Plate already has an active session; use a fresh test plate or close the existing session")
    stage(args, "Entry allocated. Begin the parking portion of the sectional clip.")
    run_dir = replay_sectional(args, scenario, session_id, entry["slot_number"], token)
    print(f"Sectional events and evidence: {run_dir}", flush=True)
    if entry["payment_status"] in ("PAID", "PAID_RESERVATION", "PAID_OVERNIGHT", "BYPASSED"):
        print("Entry is already cleared for exit. Press Enter to replay the exit clip.", flush=True)
    else:
        print("Complete cashier payment, tenant validation, or grace handling in the app. Press Enter to replay exit.", flush=True)
    input()
    while True:
        exit_plate = process_video_feed(str(args.exit_video), str(ROOT / "computer_vision/best.pt"),
                                        "Recorded exit", "exit", preview=not args.no_preview)
        if not exit_plate:
            raise RuntimeError("Recorded exit did not produce a confirmed plate")
        if exit_plate != plate:
            raise RuntimeError(f"Exit read {exit_plate}, but entry was {plate}")
        try:
            departure = post_camera(args.api_url, token, "exit_camera_event", {
                "plate_number": plate, "channel": 13,
                "observed_at": datetime.now(timezone.utc).isoformat()})
            print(f"Exit cleared: session {departure['session_id']}; slot released.", flush=True)
            break
        except RuntimeError as error:
            print(f"Exit denied: {error}", flush=True)
            if input("Resolve in cashier/staff UI, then press Enter to retry (q to stop): ").strip().lower() == "q":
                raise


def live_gates(args, scenario, token):
    if not args.plate:
        raise ValueError("--plate is required in live-gates mode")
    if args.clip_plate and args.clip_plate != args.plate:
        print("Clip plate differs from live plate; sectional identity will remain unknown.", flush=True)
    since_id = newest_session_id()
    env = os.environ.copy()
    env["PARKING_SCENARIO_ID"] = scenario
    env["PARKING_PREFERRED_SLOT"] = args.preferred_slot or args.clip_slot or ""
    env["PARKING_EXIT_EVENT_TOKEN"] = token
    env["PARKING_EXIT_EVENT_URL"] = f"{args.api_url}?action=exit_camera_event"
    command = [sys.executable, str(ROOT / "computer_vision/live_vigi.py")]
    if args.no_preview:
        command.append("--no-preview")
    process = subprocess.Popen(command, cwd=ROOT, env=env)
    try:
        session = wait_for_entry(args.plate, since_id, args.entry_timeout, scenario, process)
        session_id = session["session_id"]
        print(f"Live entry: session {session_id}, assigned {session['slot_number']}.", flush=True)
        stage(args, "Live entry allocated. Begin the parking portion of the sectional clip.")
        run_dir = replay_sectional(args, scenario, session_id, session["slot_number"], token)
        print(f"Sectional events and evidence: {run_dir}", flush=True)
        print("Complete payment/validation in the app, then drive past exit channel 13.", flush=True)
        deadline = time.monotonic() + args.exit_timeout
        while time.monotonic() < deadline:
            state = current_session(args.plate, since_id, scenario)
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
    parser.add_argument("--scenario-id", default="sim-" + uuid.uuid4().hex[:12])
    parser.add_argument("--plate", help="Expected test plate; required for live gates")
    parser.add_argument("--clip-plate", default="LAC7863", help="Labeled vehicle in sectional recording; blank means unknown")
    parser.add_argument("--clip-slot", default="E-05", help="Labeled bay in sectional recording; blank means unknown")
    parser.add_argument("--entry-video", type=Path, default=VIDEO / "RegularCustomer - Reservation (Entry and Exit).mp4")
    parser.add_argument("--sectional-video", type=Path, default=VIDEO / "SectOcc_Sample1.mp4")
    parser.add_argument("--exit-video", type=Path, default=VIDEO / "nissanExit.mp4")
    parser.add_argument("--regions", type=Path, default=ROOT / "computer_vision/sectocc_sample1_regions.json")
    parser.add_argument("--camera-id", default="section_e_sample1")
    parser.add_argument("--vehicle-model", type=Path, default=ROOT / "yolov8n.pt")
    parser.add_argument("--sectional-plate-model", type=Path)
    parser.add_argument("--sample-seconds", type=float, default=1.0)
    parser.add_argument("--baseline-seconds", type=float, default=3.0,
                        help="Seconds from start of sectional clip used for pre-entry snapshot")
    parser.add_argument("--parked-seconds", type=float, default=10.0,
                        help="Continuous occupied video seconds required to confirm parking")
    parser.add_argument("--preferred-slot", help="Vacant bay to allocate for this simulation; defaults to clip slot")
    parser.add_argument("--auto-advance", action="store_true", help="Skip manual stage controls")
    parser.add_argument("--api-url", default=DEFAULT_API)
    parser.add_argument("--no-preview", action="store_true")
    parser.add_argument("--entry-timeout", type=int, default=600)
    parser.add_argument("--exit-timeout", type=int, default=1800)
    args = parser.parse_args(argv)
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", args.scenario_id):
        parser.error("scenario ID must be 1-64 letters, digits, underscores, or hyphens")
    for field in ("plate", "clip_plate"):
        value = getattr(args, field)
        if value and not re.fullmatch(r"[A-Z]{3}[0-9]{3,4}", value):
            parser.error(f"--{field.replace('_','-')} must be a normalized plate")
    if args.mode == "live-gates" and not args.plate:
        parser.error("--plate is required for live gates")
    if args.preferred_slot and not re.fullmatch(r"[A-F]-[0-9]{2}", args.preferred_slot):
        parser.error("--preferred-slot must look like E-05")
    for path in (args.sectional_video, args.regions, args.vehicle_model):
        if not path.is_file():
            parser.error(f"missing file: {path}")
    if args.mode == "recorded":
        for path in (args.entry_video, args.exit_video):
            if not path.is_file():
                parser.error(f"missing file: {path}")
    if args.sectional_plate_model and not args.sectional_plate_model.is_file():
        parser.error(f"missing sectional plate model: {args.sectional_plate_model}")
    if args.sample_seconds <= 0 or args.baseline_seconds <= 0 or args.parked_seconds <= 0 or args.entry_timeout <= 0 or args.exit_timeout <= 0:
        parser.error("sample interval and timeouts must be positive")
    if not args.api_url.startswith(("http://127.0.0.1:", "http://localhost:", "https://")):
        parser.error("API URL must be local HTTP or HTTPS")
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
            capture_baseline(args, args.scenario_id, token)
            stage(args, "Baseline saved. Begin the entrance feed.")
            (recorded if args.mode == "recorded" else live_gates)(args, args.scenario_id, token)
    except (RuntimeError, TimeoutError, subprocess.CalledProcessError, KeyboardInterrupt) as error:
        parser.exit(1, f"Simulation stopped: {error}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
