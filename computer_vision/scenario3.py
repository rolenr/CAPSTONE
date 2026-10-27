import sqlite3
import random
import uuid
import time
import re
from collections import Counter, defaultdict
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

def init_db():
    if not os.path.isfile(DB_PATH):
        raise FileNotFoundError(f"Project database not found: {DB_PATH}")

def process_video_feed(video_path, model_path, window_name, feed_type="entrance"):
    import cv2
    import easyocr
    from ultralytics import YOLO

    print(f"[VISION PIPELINE]: Initializing YOLOv8 and EasyOCR on {video_path}...")
    model = YOLO(model_path)
    reader = easyocr.Reader(['en'])
    cap = cv2.VideoCapture(video_path)

    previous_positions = {}
    approaching_vehicles = set()
    plate_history = defaultdict(list)

    while cap.isOpened():
        ret, frame = cap.read()
        if not ret:
            break

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

        results = model.track(frame, persist=True, tracker='bytetrack.yaml', conf=0.35, verbose=False, device="cpu")

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
        if cv2.waitKey(25) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()
    return None

def check_and_assign_slot(cursor, vehicle_id):
    """
    Checks if the vehicle has an active reservation.
    Returns: (slot_id, slot_number, zone, is_reserved_boolean)
    """
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

def run_scenario_3(entrance_video=None):
    if entrance_video is None:
        video_dir = os.path.join(SCRIPT_DIR, "simulation_videos")
        entrance_video = parse_video_args(
            "Scenario 3: entrance plate recognition and walk-in slot assignment.",
            {
                "entry_video": (
                    "Recorded video of a walk-in vehicle arriving at the entrance.",
                    os.path.join(video_dir, "Entry Fortuner - Day (walk-in).mp4"),
                )
            },
        )["entry_video"]

    print("=================================================================")
    print("      RUNNING SCENARIO 3: ENTRANCE CAMERA & SLOT ASSIGNMENT      ")
    print("=================================================================\n")
    
    init_db()
    
    # Model weights & video with dynamic resolution and fallback
    model_weights = os.path.join(SCRIPT_DIR, "entrance-feed-best1.pt")
    if not os.path.exists(model_weights):
        model_weights = r"R:\Capstone\entrance-feed-best1.pt"
    
    entry_plate = process_video_feed(entrance_video, model_weights, 'Scenario 3: Entrance Feed', feed_type="entrance")
    
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
    slot_id, slot_number, zone, is_reserved = check_and_assign_slot(cursor, vehicle_id)
    
    if not slot_id:
        print("[ERROR]: Public parking lot is completely full.")
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
    cursor.execute("INSERT INTO parking_sessions (vehicle_id, slot_id) VALUES (?, ?)", (vehicle_id, slot_id))
    session_id = cursor.lastrowid
    
    cursor.execute("UPDATE parking_slots SET is_occupied = 1 WHERE slot_id = ?", (slot_id,))
    cursor.execute("UPDATE spatial_allocation SET active_parked_count = active_parked_count + 1 WHERE zone_id = ?", (zone,))
    
    conn.commit()
    conn.close()
    
    print(f"Public Lot Entry logged into DB (Session ID: {session_id}).")
    print("\n[MICROCONTROLLER RELAY]: Signal sent. ENTRANCE BARRIER OPEN.")

if __name__ == "__main__":
    run_scenario_3()
