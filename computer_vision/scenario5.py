import sqlite3
import time
import math
import queue
import threading
from datetime import datetime, timedelta, timezone

import os
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

try:
    from .video_args import parse_video_args
except ImportError:
    from video_args import parse_video_args

try:
    from .plate_utils import normalize_license_plate, prompt_for_license_plate
except ImportError:
    from plate_utils import normalize_license_plate, prompt_for_license_plate

try:
    from .live_vigi import PlateDetector
except ImportError:
    from live_vigi import PlateDetector

DB_PATH = os.path.join(os.path.dirname(SCRIPT_DIR), "backend", "database", "parking.db")

EXIT_CLEARED_STATUSES = {"PAID", "BYPASSED", "PAID_OVERNIGHT", "PAID_RESERVATION"}
VENDOR_EXIT_GRACE_PERIOD = timedelta(minutes=20)


def is_exit_payment_cleared(payment_status, vendor_scan_time=None):
    """Mirror the application's allowed exit payment states."""
    status = str(payment_status or "UNPAID").strip().upper()
    if status in EXIT_CLEARED_STATUSES:
        return True
    if status != "PAID_VENDOR" or not vendor_scan_time:
        return False

    try:
        scanned_at = datetime.fromisoformat(str(vendor_scan_time).replace("Z", "+00:00"))
        if scanned_at.tzinfo is None:
            # SQLite CURRENT_TIMESTAMP values are UTC but have no timezone suffix.
            scanned_at = scanned_at.replace(tzinfo=timezone.utc)
        age = datetime.now(timezone.utc) - scanned_at.astimezone(timezone.utc)
        return timedelta(0) <= age <= VENDOR_EXIT_GRACE_PERIOD
    except (TypeError, ValueError):
        return False

def init_db():
    if not os.path.isfile(DB_PATH):
        raise FileNotFoundError(f"Project database not found: {DB_PATH}")

def _play_recording_at_source_rate(cap, cv2, frames, latest, lock, stop, finished):
    """Read at the clip's frame rate while recognition runs independently."""
    fps = cap.get(cv2.CAP_PROP_FPS)
    if not math.isfinite(fps) or not 1 <= fps <= 120:
        fps = 30.0
    started = time.monotonic()
    frame_number = 0
    try:
        while not stop.is_set():
            due = started + frame_number / fps
            if stop.wait(max(0.0, due - time.monotonic())):
                break
            ok, frame = cap.read()
            if not ok:
                break
            captured_at = time.monotonic()
            with lock:
                latest["frame"] = frame
                latest["captured_at"] = captured_at
            item = (captured_at, frame)
            try:
                frames.put_nowait(item)
            except queue.Full:
                # Bound latency if the CPU cannot keep up with the camera.
                try:
                    frames.get_nowait()
                except queue.Empty:
                    pass
                frames.put_nowait(item)
            frame_number += 1
    finally:
        finished.set()


def _recognize_recording(frames, detector, feed_type, latest, lock, stop,
                         finished, errors):
    """Process buffered frames without delaying the video preview."""
    while not stop.is_set() and (not finished.is_set() or not frames.empty()):
        try:
            captured_at, frame = frames.get(timeout=0.02)
        except queue.Empty:
            continue
        if time.monotonic() - captured_at > 2.0:
            continue
        with lock:
            if feed_type == "exit" and "plate" in latest:
                continue
        try:
            annotated, events = detector.process(frame)
        except Exception as exc:
            errors.append(exc)
            stop.set()
            return
        with lock:
            latest["annotated"] = annotated
            latest["annotated_at"] = captured_at
            for _role, plate in events:
                if "plate" not in latest:
                    latest["plate"] = plate
                    print(
                        f"[VISION PIPELINE]: Recognized {feed_type} plate "
                        f"'{plate}' using live camera rules.", flush=True
                    )
                    if feed_type == "entrance":
                        stop.set()
                    break


def process_video_feed(video_path, model_path, window_name, feed_type="exit", preview=True):
    """Analyze recorded frames in order, independent of CPU inference speed."""
    import cv2
    import easyocr
    from ultralytics import YOLO

    if feed_type not in ("entrance", "exit"):
        raise ValueError("feed_type must be 'entrance' or 'exit'")
    print(f"[VISION PIPELINE]: Analyzing recorded {feed_type} feed: {video_path}...", flush=True)
    detector = PlateDetector(feed_type, YOLO(model_path), easyocr.Reader(["en"]), cv2)
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        cap.release()
        raise RuntimeError(f"Could not open recorded video: {video_path}")
    fps = cap.get(cv2.CAP_PROP_FPS)
    if not math.isfinite(fps) or fps <= 0:
        fps = 25.0
    stride = max(1, round(fps / 5))
    frame_index = 0
    analyzed = 0
    confirmed = None
    try:
        while True:
            ok, frame = cap.read()
            if not ok:
                break
            if frame_index % stride:
                frame_index += 1
                continue
            annotated, events = detector.process(frame, now=frame_index / fps)
            analyzed += 1
            if preview:
                cv2.imshow(window_name, annotated)
                if cv2.waitKey(1) & 0xFF == ord("q"):
                    break
            for role, plate in events:
                if role == feed_type:
                    confirmed = plate
                    print(f"[VISION PIPELINE]: Confirmed {feed_type} plate '{plate}' at video frame {frame_index}.", flush=True)
                    break
            if confirmed:
                break
            frame_index += 1
    finally:
        cap.release()
        if preview:
            cv2.destroyAllWindows()
    if not confirmed:
        print(f"[VISION PIPELINE]: No confirmed {feed_type} plate after {analyzed} analyzed frames "
              f"({frame_index} decoded frames).", flush=True)
    return confirmed


def check_and_assign_slot(cursor, vehicle_id):
    cursor.execute('''
        SELECT r.reservation_id, r.slot_id, p.slot_number, p.zone, r.payment_state
        FROM reservations r
        JOIN parking_slots p ON r.slot_id = p.slot_id
        WHERE r.vehicle_id = ? AND r.status = 'ACTIVE'
          AND r.payment_state IN ('PAID', 'ADMIN_EXEMPT') AND p.is_active = 1
    ''', (vehicle_id,))
    
    reservation = cursor.fetchone()

    if reservation:
        res_id, slot_id, slot_number, zone, payment_state = reservation
        print(f"\n[SYSTEM]: Prior reservation recognized! Assigning reserved slot.")
        cursor.execute("UPDATE reservations SET status = 'FULFILLED' WHERE reservation_id = ?", (res_id,))
        return slot_id, slot_number, zone, payment_state
    else:
        cursor.execute("SELECT 1 FROM reservations WHERE vehicle_id=? AND status IN ('PENDING_PAYMENT','ACTIVE') AND payment_state NOT IN ('PAID','ADMIN_EXEMPT') LIMIT 1", (vehicle_id,))
        if cursor.fetchone():
            return None, None, None, 'UNPAID_BOOKING'
        print("\n[SYSTEM]: No prior reservation found. Walk-in detected. Finding available slot...")
        cursor.execute("""SELECT s.slot_id, s.slot_number, s.zone FROM parking_slots s
            WHERE s.is_active = 1 AND s.is_occupied = 0 AND NOT EXISTS (
              SELECT 1 FROM reservations r WHERE r.slot_id=s.slot_id
              AND r.status IN ('ACTIVE','PENDING_PAYMENT')) LIMIT 1""")
        slot = cursor.fetchone()
        
        if not slot:
            return None, None, None, None
            
        slot_id, slot_number, zone = slot
        return slot_id, slot_number, zone, None

def run_scenario_5_closed_loop(entrance_video=None, exit_video=None):
    if entrance_video is None or exit_video is None:
        video_dir = os.path.join(SCRIPT_DIR, "simulation_videos")
        videos = parse_video_args(
            "Scenario 5: recorded-video reservation entry and parking exit cycle.",
            {
                "entry_video": (
                    "Recorded video of a reserved customer arriving at the entrance.",
                    os.path.join(video_dir, "RegularCustomer - Reservation (Entry and Exit).mp4"),
                ),
                "exit_video": (
                    "Recorded video of a vehicle approaching the exit.",
                    os.path.join(video_dir, "nissanExit.mp4"),
                ),
            },
        )
        entrance_video = entrance_video or videos["entry_video"]
        exit_video = exit_video or videos["exit_video"]

    print("=================================================================")
    print("      RUNNING SCENARIO 5: FULL CLOSED-LOOP CYCLE SIMULATION     ")
    print("=================================================================\n")
    
    init_db()
    
    # Dynamic resolution for model weights & video with fallback
    entrance_weights = os.path.join(SCRIPT_DIR, "entrance-feed-best1.pt")
    if not os.path.exists(entrance_weights):
        entrance_weights = r"R:\Capstone\entrance-feed-best1.pt"
    
    exit_weights = os.path.join(SCRIPT_DIR, "best.pt")
    if not os.path.exists(exit_weights):
        exit_weights = r"R:\Capstone\best.pt"
    
    conn = sqlite3.connect(DB_PATH, timeout=10)
    cursor = conn.cursor()
    
    # --- PHASE 1: ENTRANCE LOGGING & QR DISPENSING ---
    print("--- [PHASE 1: ENTRANCE PROCESSING] ---")
    entry_plate = process_video_feed(entrance_video, entrance_weights, 'Scenario 5: Entrance Feed', feed_type="entrance")
    
    if not entry_plate:
        print("\n[SYSTEM]: Vision system failed to detect plate at entrance.")
        print("Attendant switching to manual validation via QR code scanning...")
        token_input = input("[MANUAL OVERRIDE]: Enter Reservation Token ID (or leave blank for Walk-in): ").strip()
        
        if token_input:
            cursor.execute('''
                SELECT v.plate_number 
                FROM reservations r
                JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                WHERE r.token_id = ? AND r.status = 'ACTIVE'
                  AND r.payment_state IN ('PAID', 'ADMIN_EXEMPT')
            ''', (token_input,))
            res = cursor.fetchone()
            if res:
                entry_plate = normalize_license_plate(res[0])
                print(f"[SYSTEM]: Reservation confirmed via token. Plate linked: {entry_plate}")
            else:
                print("[ERROR]: Invalid or expired Reservation Token.")
                conn.close()
                return
        else:
            entry_plate = prompt_for_license_plate(
                "[MANUAL OVERRIDE]: Enter License Plate for Walk-in (no spaces, e.g. ABC1234): "
            )
            if not entry_plate:
                print("[ERROR]: No valid plate provided.")
                conn.close()
                return

    # 1. Register or fetch vehicle
    cursor.execute("SELECT vehicle_id FROM vehicles WHERE UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?", (entry_plate,))
    vehicle = cursor.fetchone()
    if not vehicle:
        cursor.execute("INSERT INTO vehicles (plate_number, vehicle_type) VALUES (?, 'Transient')", (entry_plate,))
        vehicle_id = cursor.lastrowid
    else:
        vehicle_id = vehicle[0]

    # 2. Assign Slot
    slot_id, slot_number, zone, payment_state = check_and_assign_slot(cursor, vehicle_id)
    is_reserved = payment_state is not None
    
    if not slot_id:
        print("[ERROR]: Booking payment is unconfirmed; entry denied." if payment_state == 'UNPAID_BOOKING' else "[ERROR]: Public parking lot is completely full.")
        conn.close()
        return

    # 3. Log session and occupy slot
    initial_payment = 'PAID_RESERVATION' if payment_state == 'PAID' else ('BYPASSED' if payment_state == 'ADMIN_EXEMPT' else 'UNPAID')
    cursor.execute("INSERT INTO parking_sessions (vehicle_id, slot_id, payment_status) VALUES (?, ?, ?)", (vehicle_id, slot_id, initial_payment))
    session_id = cursor.lastrowid
    
    cursor.execute("UPDATE parking_slots SET is_occupied = 1 WHERE slot_id = ?", (slot_id,))
    cursor.execute("UPDATE spatial_allocation SET active_parked_count = active_parked_count + 1 WHERE zone_id = ?", (zone,))
    conn.commit()

    # Print Terminal QR / Slot Dispenser
    print("\n" + "="*40)
    if is_reserved:
        print("      [ RESERVATION CONFIRMED ]        ")
    else:
        print("          [ TICKET DISPENSED ]         ")
        
    print(f"      TICKET/SESSION ID : {session_id}")
    print(f"      SLOT              : {slot_number}")
    print("="*40 + "\n")
    
    print(f"Public Lot Entry logged into DB (Session ID: {session_id}).")
    print("Entry session recorded; slot assignment awaits physical parking.\n")
    
    # --- PHASE 2: CASHIER PAYMENT ---
    print("--- [PHASE 2: CASHIER PAYMENT] ---")
    print(f"Session #{session_id} for {entry_plate} is now active and {initial_payment}.")
    if initial_payment == 'UNPAID':
        print("Open the Cashier POS, search by plate or session ID, and process the displayed amount.")
    print("The simulation will read the cashier's committed payment status before deciding exit clearance.\n")
    
    # --- SIMULATION CONTROL ---
    while True:
        user_input = input("[SIMULATION CONTROL]: Press 'Y' to check exit clearance: ").strip().upper()
        if user_input == 'Y':
            break

    # --- PHASE 3: EXIT VERIFICATION ---
    print("\n--- [PHASE 3: EXIT VERIFICATION & CLEARANCE] ---")
    exit_plate = process_video_feed(exit_video, exit_weights, 'Scenario 5: Exit Feed', feed_type="exit")
    
    active_session = None
    clearance_method = None

    if not exit_plate:
        print("\n[SYSTEM]: Vision system failed to read license plate at exit.")
        print("Attendant switching to manual session lookup.")
        session_input = input("[MANUAL OVERRIDE]: Enter the parking session ID: ").strip()
        
        if session_input:
            cursor.execute('''
                SELECT session_id, slot_id, total_fee, vehicle_id, payment_status, vendor_scan_time
                FROM parking_sessions 
                WHERE session_id = ? AND exit_time IS NULL AND location_status = 'PARKED'
            ''', (session_input,))
            active_session = cursor.fetchone()
            if not active_session:
                print("[ERROR]: Invalid Ticket or no active parking session found.")
                conn.close()
                return
            clearance_method = "SESSION_ID"
        else:
            print("[ERROR]: No ticket provided.")
            conn.close()
            return
    else:
        clearance_method = "ALPR"
        cursor.execute("SELECT vehicle_id FROM vehicles WHERE UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?", (exit_plate,))
        exit_vehicle = cursor.fetchone()
        if exit_vehicle:
            cursor.execute('''
                SELECT session_id, slot_id, total_fee, vehicle_id, payment_status, vendor_scan_time
                FROM parking_sessions 
                WHERE vehicle_id = ? AND exit_time IS NULL AND location_status = 'PARKED'
                ORDER BY session_id DESC
                LIMIT 1
            ''', (exit_vehicle[0],))
            active_session = cursor.fetchone()

    # Process Final Clearance
    if active_session:
        active_session_id, active_slot_id, total_fee, exit_vehicle_id, payment_status, vendor_scan_time = active_session
        while True:
            print(f"[EXIT CHECK]: Session #{active_session_id} payment status is {payment_status or 'UNPAID'}.")
            if is_exit_payment_cleared(payment_status, vendor_scan_time):
                print(f"[EXIT CLEARANCE PASSED]: {payment_status}. The guard may open the barrier.")
                print("Review the complete exit feed and confirm only after the vehicle has fully passed the gate.")
                departure_confirmed = input(
                    "[GUARD CONFIRMATION]: Type DEPARTED after the vehicle has exited: "
                ).strip().upper()
                if departure_confirmed == "DEPARTED":
                    cursor.execute(
                        "UPDATE parking_sessions "
                        "SET exit_time = CURRENT_TIMESTAMP, location_status = 'EXITED', exit_method = ? "
                        "WHERE session_id = ? AND exit_time IS NULL AND location_status = 'PARKED'",
                        (clearance_method, active_session_id),
                    )
                    if cursor.rowcount != 1:
                        conn.rollback()
                        print("[DEPARTURE NOT RECORDED]: Session is no longer active; slot was not released.")
                        break

                    cursor.execute(
                        "UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ? AND is_occupied = 1",
                        (active_slot_id,),
                    )
                    if cursor.rowcount != 1:
                        conn.rollback()
                        print("[DEPARTURE NOT RECORDED]: Slot was not occupied; database changes were rolled back.")
                        break

                    cursor.execute('''
                        UPDATE spatial_allocation
                        SET active_parked_count = MAX(0, active_parked_count - 1)
                        WHERE zone_id = (SELECT zone FROM parking_slots WHERE slot_id = ?)
                    ''', (active_slot_id,))
                    conn.commit()
                    print("\n>>> SUCCESS: VEHICLE DEPARTURE CONFIRMED <<<")
                    print("Session closed and parking slot released.")
                else:
                    print("[DEPARTURE NOT RECORDED]: Session remains active and its slot remains occupied.")
                break

            print(
                f"[EXIT DENIED]: Payment status '{payment_status or 'UNPAID'}' is not cleared. "
                "The session remains active, the slot remains occupied, and the barrier stays closed. "
                "Process payment in the Cashier POS, then press 'R' to recheck, or Enter to stop."
            )
            retry = input("[SIMULATION CONTROL]: ").strip().upper()
            if retry != "R":
                break

            cursor.execute(
                "SELECT payment_status, vendor_scan_time FROM parking_sessions "
                "WHERE session_id = ? AND exit_time IS NULL AND location_status = 'PARKED'",
                (active_session_id,),
            )
            refreshed_session = cursor.fetchone()
            if not refreshed_session:
                print("[EXIT DENIED]: The active session could not be found; barrier stays closed.")
                break
            payment_status, vendor_scan_time = refreshed_session
    else:
        print("FAILED: Unregistered vehicle or no active parking session found.")
        
    conn.close()

if __name__ == "__main__":
    try:
        from .full_simulation import main as simulation_main
    except ImportError:
        from full_simulation import main as simulation_main
    import sys
    raise SystemExit(simulation_main(["recorded", *sys.argv[1:]]))
