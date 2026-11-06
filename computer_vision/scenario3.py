import argparse
import os
import re
import sqlite3
import time
import uuid
from collections import defaultdict

try:
    from .plate_utils import normalize_license_plate, prompt_for_license_plate
except ImportError:
    from plate_utils import normalize_license_plate, prompt_for_license_plate

# Recorded-video playback is the default for repeatable demos. Set this to True
# to use a camera unless --recorded-video or --entry-video is supplied.
USE_LIVE_CAMERA = False
CAMERA_SOURCE = 0
LIVE_TIMEOUT_SEC = 60
OCR_EVERY_N_FRAMES = 3
USE_GPU = False

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(os.path.dirname(SCRIPT_DIR), "backend", "database", "parking.db")

def init_db():
    if not os.path.isfile(DB_PATH):
        raise FileNotFoundError(f"Project database not found: {DB_PATH}")

def open_capture(source):
    """Opens a video file path, a camera index, or an IP camera URL."""
    import cv2

    is_camera_index = isinstance(source, int)

    # CAP_DSHOW avoids slow startup / black frames on Windows webcams
    if is_camera_index and os.name == "nt":
        cap = cv2.VideoCapture(source, cv2.CAP_DSHOW)
    else:
        cap = cv2.VideoCapture(source)

    if is_camera_index and cap.isOpened():
        # Request a resolution (the camera may ignore this)
        cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)
        # Keep the buffer small so we always see the latest frame
        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

    return cap

def process_video_feed(source, model_path, window_name, feed_type="entrance",
                       is_live=False, timeout_sec=LIVE_TIMEOUT_SEC):
    import cv2
    import easyocr
    from ultralytics import YOLO

    print(f"[VISION PIPELINE]: Initializing YOLOv8 and EasyOCR on source: {source}...")
    model = YOLO(model_path)
    reader = easyocr.Reader(['en'], gpu=USE_GPU)
    cap = open_capture(source)

    if not cap.isOpened():
        print(f"[ERROR]: Could not open video/camera source: {source}")
        print("         Check the camera index, or whether another app is using the camera.")
        return None

    previous_positions = {}
    approaching_vehicles = set()
    plate_history = defaultdict(list)

    frame_count = 0
    failed_reads = 0
    MAX_FAILED_READS = 30  # tolerate brief camera hiccups on live feeds
    start_time = time.time()

    if is_live:
        print(f"[VISION PIPELINE]: Live mode. Press 'q' to cancel. Timeout in {timeout_sec}s.")

    while cap.isOpened():
        # Live feeds never end, so enforce a timeout to trigger the manual fallback
        if is_live and (time.time() - start_time) > timeout_sec:
            print("[VISION PIPELINE]: Timeout reached with no confirmed plate.")
            break

        ret, frame = cap.read()
        if not ret:
            if is_live:
                failed_reads += 1
                if failed_reads >= MAX_FAILED_READS:
                    print("[ERROR]: Lost connection to camera.")
                    break
                time.sleep(0.05)
                continue
            break  # end of video file
        failed_reads = 0
        frame_count += 1

        height, width, _ = frame.shape

        # --- DYNAMIC ROI LOGIC ---
        if feed_type == "entrance":
            # Adjusted to match the specific entrance camera framing
            ymin, ymax = int(height * 0.10), int(height * 0.95)
            xmin, xmax = int(width * 0.20), int(width * 0.70)
        else:
            ymin, ymax = int(height * 0.05), int(height * 0.70)
            xmin, xmax = int(width * 0.40), int(width * 0.95)

        cv2.rectangle(frame, (xmin, ymin), (xmax, ymax), (0, 255, 0), 2)

        results = model.track(
            frame, persist=True, tracker='bytetrack.yaml', conf=0.35,
            verbose=False, device=0 if USE_GPU else "cpu"
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

                    if track_id in previous_positions:
                        if cy >= previous_positions[track_id]:
                            approaching_vehicles.add(track_id)
                    else:
                        approaching_vehicles.add(track_id)

                    previous_positions[track_id] = cy

                    # OCR is expensive, so sample frames in both live and file modes.
                    if frame_count % OCR_EVERY_N_FRAMES != 0:
                        continue

                    if (xmin <= cx <= xmax and ymin <= cy <= ymax) and (track_id in approaching_vehicles):
                        plate_crop = frame[y1:y2, x1:x2]
                        if plate_crop.size > 0:
                            gray = cv2.cvtColor(plate_crop, cv2.COLOR_BGR2GRAY)
                            resized = cv2.resize(gray, (0, 0), fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
                            contrast = cv2.convertScaleAbs(resized, alpha=1.5, beta=10)

                            ocr_results = reader.readtext(contrast)
                            for bbox, text, prob in ocr_results:
                                cleaned_text = normalize_license_plate(text)

                                # Validate string format
                                if re.match(r'^[A-Z]{3}\d{3,4}$', cleaned_text):
                                    plate_history[track_id].append(cleaned_text)

                                    # --- CONSECUTIVE CHECK LOGIC ---
                                    if len(plate_history[track_id]) >= 3:
                                        last_three = plate_history[track_id][-3:]

                                        if last_three[0] == last_three[1] == last_three[2]:
                                            confirmed_plate = last_three[0]
                                            print(f"[VISION PIPELINE]: Extracted Plate '{confirmed_plate}' (3 Consecutive Matches)")

                                            cap.release()
                                            cv2.destroyAllWindows()
                                            return confirmed_plate

        cv2.imshow(window_name, frame)
        # Short wait for live feeds; 25ms paces file playback
        if cv2.waitKey(1 if is_live else 25) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()
    return None

def check_and_assign_slot(cursor, vehicle_id):
    """
    Checks if the vehicle has an active reservation.
    Returns: (slot_id, slot_number, zone, verified_payment_state).
    """
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

def run_scenario_3(entrance_video=None, live_camera=None, camera_source=None,
                   live_timeout=LIVE_TIMEOUT_SEC):
    if entrance_video is None and live_camera is None and camera_source is None:
        video_dir = os.path.join(SCRIPT_DIR, "simulation_videos")
        parser = argparse.ArgumentParser(
            description="Scenario 3: entrance plate recognition and walk-in slot assignment."
        )
        mode = parser.add_mutually_exclusive_group()
        mode.add_argument("--live-camera", action="store_true", help="Use a live camera or stream.")
        mode.add_argument("--recorded-video", action="store_true", help="Use a recorded video (the default).")
        parser.add_argument("--entry-video", help="Recorded entrance video path.")
        parser.add_argument("--camera-source", default=str(CAMERA_SOURCE), help="Camera index or stream URL.")
        parser.add_argument("--live-timeout", type=int, default=LIVE_TIMEOUT_SEC)
        args = parser.parse_args()

        if args.live_camera and args.entry_video:
            parser.error("--entry-video cannot be used with --live-camera")
        entrance_video = args.entry_video or os.path.join(
            video_dir, "Entry Fortuner - Day (walk-in).mp4"
        )
        live_camera = args.live_camera or (
            USE_LIVE_CAMERA and not args.recorded_video and args.entry_video is None
        )
        camera_source = args.camera_source
        live_timeout = args.live_timeout
    elif live_camera is None:
        live_camera = USE_LIVE_CAMERA if entrance_video is None else False

    if live_camera:
        source = camera_source if camera_source is not None else CAMERA_SOURCE
        if isinstance(source, str) and source.isdecimal():
            source = int(source)
    else:
        source = entrance_video
        if not source or not os.path.isfile(source):
            raise FileNotFoundError(f"Recorded entrance video not found: {source}")

    print("=================================================================")
    print("      RUNNING SCENARIO 3: ENTRANCE CAMERA & SLOT ASSIGNMENT      ")
    print("=================================================================\n")

    init_db()

    # Model weights with dynamic resolution and fallback
    model_weights = os.path.join(SCRIPT_DIR, "entrance-feed-best1.pt")
    if not os.path.exists(model_weights):
        model_weights = r"C:\xampp\htdocs\campstone\CAPSTONE\computer_vision\entrance-feed-best1.pt"

    entry_plate = process_video_feed(
        source, model_weights, 'Scenario 3: Entrance Feed', feed_type="entrance",
        is_live=live_camera, timeout_sec=live_timeout
    )

    conn = sqlite3.connect(DB_PATH, timeout=10)
    cursor = conn.cursor()

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

    # 2. Call the Slot Assignment Function
    slot_id, slot_number, zone, payment_state = check_and_assign_slot(cursor, vehicle_id)
    is_reserved = payment_state is not None

    if not slot_id:
        print("[ERROR]: Booking payment is unconfirmed; entry denied." if payment_state == 'UNPAID_BOOKING' else "[ERROR]: Public parking lot is completely full.")
        conn.close()
        return

    qr_token = f"QR_{uuid.uuid4().hex[:12].upper()}"

    # Print Terminal QR / Slot Dispenser
    print("\n" + "="*40)
    if is_reserved:
        print("      [ RESERVATION CONFIRMED ]        ")
    else:
        print("          [ TICKET DISPENSED ]         ")

    print(f"      QR TOKEN : {qr_token}")
    print(f"      SLOT     : {slot_number}")
    print("="*40 + "\n")

    # 3. Create session and update spatial constraints
    initial_payment = 'PAID_RESERVATION' if payment_state == 'PAID' else ('BYPASSED' if payment_state == 'ADMIN_EXEMPT' else 'UNPAID')
    cursor.execute("INSERT INTO parking_sessions (vehicle_id, slot_id, payment_status) VALUES (?, ?, ?)", (vehicle_id, slot_id, initial_payment))
    session_id = cursor.lastrowid

    cursor.execute("UPDATE parking_slots SET is_occupied = 1 WHERE slot_id = ?", (slot_id,))
    cursor.execute("UPDATE spatial_allocation SET active_parked_count = active_parked_count + 1 WHERE zone_id = ?", (zone,))

    conn.commit()
    conn.close()

    print(f"Public Lot Entry logged into DB (Session ID: {session_id}).")
    print("\n[MICROCONTROLLER RELAY]: Signal sent. ENTRANCE BARRIER OPEN.")

if __name__ == "__main__":
    run_scenario_3()
