import sqlite3
import random
import uuid
import time
import re
from collections import Counter, defaultdict, deque
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

def process_video_feed(video_path, model_path, window_name, feed_type="exit"):
    import cv2
    import easyocr
    from ultralytics import YOLO

    print(f"[VISION PIPELINE]: Initializing YOLOv8 and EasyOCR on {video_path}...")
    model = YOLO(model_path)
    reader = easyocr.Reader(['en'])
    cap = cv2.VideoCapture(video_path)

    track_history = defaultdict(lambda: {"centers": deque(maxlen=6), "areas": deque(maxlen=6)})
    plate_history = defaultdict(lambda: deque(maxlen=8))
    confirmed_plate = None

    while cap.isOpened():
        ret, frame = cap.read()
        if not ret:
            break

        # Continue showing the remainder of an exit clip after ALPR succeeds.
        # Plate recognition authorizes exit; it does not prove the vehicle left.
        if confirmed_plate:
            cv2.putText(
                frame,
                f"ALPR CLEARANCE: {confirmed_plate} - review vehicle departure",
                (24, 40),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.8,
                (0, 220, 0),
                2,
            )
            cv2.imshow(window_name, frame)
            if cv2.waitKey(25) & 0xFF == ord('q'):
                break
            continue

        height, width, _ = frame.shape
        
        # --- DYNAMIC ROI LOGIC ---
        if feed_type == "entrance":
            # Adjusted to match the blue box (lower center/right)
            ymin, ymax = int(height * 0.30), int(height * 0.95)
            xmin, xmax = int(width * 0.20), int(width * 0.95) 
        else:
            # Original exit ROI on the right side of the screen
            ymin, ymax = int(height * 0.05), int(height * 0.70)
            xmin, xmax = int(width * 0.40), int(width * 0.95)
            
        cv2.rectangle(frame, (xmin, ymin), (xmax, ymax), (0, 255, 0), 2)

        # The entrance plate is small in the wide view. Use a lower confidence
        # threshold and a larger inference size so distant plates enter tracking.
        detection_confidence = 0.20 if feed_type == "entrance" else 0.35
        inference_size = 960 if feed_type == "entrance" else 640
        results = model.track(
            frame,
            persist=True,
            tracker='bytetrack.yaml',
            conf=detection_confidence,
            imgsz=inference_size,
            verbose=False,
            device="cpu",
        )

        for r in results:
            boxes = r.boxes
            if boxes.id is not None:
                coords = boxes.xyxy.cpu().numpy()
                track_ids = boxes.id.cpu().numpy()

                for box, track_id in zip(coords, track_ids):
                    x1, y1, x2, y2 = map(int, box)
                    cx, cy = int((x1 + x2) / 2), int((y1 + y2) / 2)
                    track_id = int(track_id)
                    box_area = max(0, x2 - x1) * max(0, y2 - y1)
                    history = track_history[track_id]
                    history["centers"].append(cy)
                    history["areas"].append(box_area)

                    # In a fixed entrance camera, an approaching vehicle's
                    # plate generally grows in the image and/or moves lower in
                    # the frame. Require a trend across several observations;
                    # a first detection alone does not prove entry direction.
                    approaching = False
                    if feed_type == "entrance" and len(history["areas"]) >= 3:
                        old_area = max(history["areas"][0], 1)
                        area_growth = box_area >= old_area * 1.12
                        downward_motion = history["centers"][-1] - history["centers"][0] >= max(8, int(height * 0.015))
                        approaching = area_growth or downward_motion
                    elif feed_type != "entrance":
                        approaching = True

                    inside_roi = xmin <= cx <= xmax and ymin <= cy <= ymax
                    if confirmed_plate is None and inside_roi and approaching:
                        plate_crop = frame[y1:y2, x1:x2]
                        if plate_crop.size > 0:
                            gray = cv2.cvtColor(plate_crop, cv2.COLOR_BGR2GRAY)
                            resized = cv2.resize(gray, (0, 0), fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
                            contrast = cv2.convertScaleAbs(resized, alpha=1.5, beta=10)

                            ocr_results = reader.readtext(
                                contrast,
                                allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
                                paragraph=False,
                            )
                            for bbox, text, prob in ocr_results:
                                if prob < 0.20:
                                    continue
                                cleaned_text = normalize_license_plate(text)
                                if re.fullmatch(r'[A-Z]{3}\d{3,4}', cleaned_text):
                                    plate_history[track_id].append(cleaned_text)

                                    # Allow occasional OCR errors between good
                                    # frames; three matching reads in the recent
                                    # track window confirm the plate.
                                    plate, matches = Counter(plate_history[track_id]).most_common(1)[0]
                                    if matches >= 3:
                                        confirmed_plate = plate
                                        print(
                                            f"[VISION PIPELINE]: Recognized {feed_type} vehicle "
                                            f"track {track_id}; plate '{plate}' confirmed "
                                            f"({matches} matching reads)."
                                        )
                                        if feed_type == "entrance":
                                            cap.release()
                                            cv2.destroyAllWindows()
                                            return plate

                    if feed_type == "entrance":
                        direction_label = "APPROACHING" if approaching else "checking direction"
                        cv2.putText(
                            frame,
                            f"Track {track_id}: {direction_label}",
                            (x1, max(20, y1 - 8)),
                            cv2.FONT_HERSHEY_SIMPLEX,
                            0.55,
                            (0, 220, 0) if approaching else (0, 200, 255),
                            2,
                        )

        cv2.imshow(window_name, frame)
        if cv2.waitKey(25) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()
    return confirmed_plate

def check_and_assign_slot(cursor, vehicle_id):
    cursor.execute('''
        SELECT r.reservation_id, r.slot_id, p.slot_number, p.zone 
        FROM reservations r
        JOIN parking_slots p ON r.slot_id = p.slot_id
        WHERE r.vehicle_id = ? AND r.status = 'ACTIVE'
    ''', (vehicle_id,))
    
    reservation = cursor.fetchone()

    if reservation:
        res_id, slot_id, slot_number, zone = reservation
        print(f"\n[SYSTEM]: Prior reservation recognized! Assigning reserved slot.")
        cursor.execute("UPDATE reservations SET status = 'FULFILLED' WHERE reservation_id = ?", (res_id,))
        return slot_id, slot_number, zone, True
    else:
        print("\n[SYSTEM]: No prior reservation found. Walk-in detected. Finding available slot...")
        cursor.execute("SELECT slot_id, slot_number, zone FROM parking_slots WHERE is_occupied = 0 LIMIT 1")
        slot = cursor.fetchone()
        
        if not slot:
            return None, None, None, False
            
        slot_id, slot_number, zone = slot
        return slot_id, slot_number, zone, False

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
    slot_id, slot_number, zone, is_reserved = check_and_assign_slot(cursor, vehicle_id)
    
    if not slot_id:
        print("[ERROR]: Public parking lot is completely full.")
        conn.close()
        return

    # 3. Log session and occupy slot
    cursor.execute("INSERT INTO parking_sessions (vehicle_id, slot_id) VALUES (?, ?)", (vehicle_id, slot_id))
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
    print("Entrance barrier relay actuated -> VEHICLE PARKED.\n")
    time.sleep(1)
    
    # --- PHASE 2: CASHIER PAYMENT ---
    print("--- [PHASE 2: CASHIER PAYMENT] ---")
    print(f"Session #{session_id} for {entry_plate} is now active and UNPAID.")
    print("Open the Cashier POS, search by plate or session ID, and process the displayed amount.")
    print("The simulation will read the cashier's committed payment status before deciding exit clearance.\n")
    
    # --- SIMULATION CONTROL ---
    while True:
        user_input = input("[SIMULATION CONTROL]: After cashier processing, press 'Y' to check exit clearance: ").strip().upper()
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
    run_scenario_5_closed_loop()
