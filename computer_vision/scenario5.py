import sqlite3
import random
import uuid
import time
import cv2
import easyocr
import re
from collections import Counter, defaultdict
from ultralytics import YOLO

# Absolute path pointing directly to the XAMPP directory
DB_PATH = r"D:\xampp\htdocs\CAPSTONE\parking.db"

def init_db():
    conn = sqlite3.connect(DB_PATH, timeout=10)
    cursor = conn.cursor()
    
    cursor.executescript('''
        CREATE TABLE IF NOT EXISTS vehicles (
            vehicle_id INTEGER PRIMARY KEY AUTOINCREMENT,
            plate_number TEXT NOT NULL UNIQUE,
            owner_name TEXT,
            vehicle_type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS spatial_allocation (
            zone_id TEXT PRIMARY KEY,
            max_capacity INTEGER NOT NULL,
            active_parked_count INTEGER DEFAULT 0,
            active_reservation_count INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS parking_slots (
            slot_id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot_number TEXT NOT NULL UNIQUE,
            zone TEXT NOT NULL,
            is_occupied INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS reservations (
            reservation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicle_id INTEGER,
            slot_id INTEGER,
            reservation_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            expiry_time DATETIME,
            status TEXT DEFAULT 'ACTIVE',
            token_id INTEGER, 
            start_date datetime, 
            end_date datetime,
            FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
            FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
        );
        CREATE TABLE IF NOT EXISTS parking_sessions (
            session_id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicle_id INTEGER,
            slot_id INTEGER,
            entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            exit_time DATETIME,
            total_fee REAL DEFAULT 0,
            FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
            FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
        );
    ''')
    
    cursor.execute("SELECT COUNT(*) FROM spatial_allocation")
    if cursor.fetchone()[0] == 0:
        zones = ['A', 'B', 'C', 'D', 'E']
        for zone in zones:
            cursor.execute("INSERT INTO spatial_allocation (zone_id, max_capacity) VALUES (?, 10)", (zone,))
            for i in range(1, 4):
                cursor.execute("INSERT INTO parking_slots (slot_number, zone, is_occupied) VALUES (?, ?, 0)", (f"{zone}-0{i}", zone))
    
    conn.commit()
    conn.close()

def process_video_feed(video_path, model_path, window_name, feed_type="exit"):
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
            # Adjusted to match the blue box (lower center/right)
            ymin, ymax = int(height * 0.30), int(height * 0.95)
            xmin, xmax = int(width * 0.20), int(width * 0.95) 
        else:
            # Original exit ROI on the right side of the screen
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
                                cleaned_text = re.sub(r'[^A-Z0-9]', '', text.upper())
                                
                                # Validate string format (e.g. ABC1234)
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

def run_scenario_5_closed_loop():
    print("=================================================================")
    print("      RUNNING SCENARIO 5: FULL CLOSED-LOOP CYCLE SIMULATION     ")
    print("=================================================================\n")
    
    init_db()
    
    entrance_weights = r"R:\Capstone\entrance-feed-best1.pt" 
    exit_weights = r"R:\Capstone\best.pt"
    
    entrance_video = r"R:\Capstone\nightSC5.mp4" 
    exit_video = r"R:\Capstone\nightSC5exit.mp4"
    
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
                entry_plate = res[0]
                print(f"[SYSTEM]: Reservation confirmed via token. Plate linked: {entry_plate}")
            else:
                print("[ERROR]: Invalid or expired Reservation Token.")
                conn.close()
                return
        else:
            entry_plate = input("[MANUAL OVERRIDE]: Enter License Plate manually for Walk-in: ").strip().upper()
            if not entry_plate:
                print("[ERROR]: No plate provided.")
                conn.close()
                return

    # 1. Register or fetch vehicle
    cursor.execute("SELECT vehicle_id FROM vehicles WHERE plate_number = ?", (entry_plate,))
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
        print("      ✅ RESERVATION CONFIRMED ✅        ")
    else:
        print("          🎫 TICKET DISPENSED 🎫         ")
        
    print(f"      TICKET/SESSION ID : {session_id}")
    print(f"      SLOT              : {slot_number}")
    print("="*40 + "\n")
    
    print(f"Public Lot Entry logged into DB (Session ID: {session_id}).")
    print("Entrance barrier relay actuated -> VEHICLE PARKED.\n")
    time.sleep(1)
    
    # --- PHASE 2: FIXED FEE CLEARANCE ---
    print("--- [PHASE 2: STAY & PAYMENT CLEARANCE] ---")
    print("Driver settles the fixed parking fee.")
    
    cursor.execute("UPDATE parking_sessions SET total_fee = 50.0 WHERE session_id = ?", (session_id,))
    conn.commit()
    print(f"Payment Status for Session #{session_id} marked as CLEARED.\n")
    
    # --- SIMULATION CONTROL ---
    while True:
        user_input = input("[SIMULATION CONTROL]: Press 'Y' to exit Parking Ltd.: ").strip().upper()
        if user_input == 'Y':
            break

    # --- PHASE 3: EXIT VERIFICATION ---
    print("\n--- [PHASE 3: EXIT VERIFICATION & CLEARANCE] ---")
    exit_plate = process_video_feed(exit_video, exit_weights, 'Scenario 5: Exit Feed', feed_type="exit")
    
    active_session = None

    if not exit_plate:
        print("\n[SYSTEM]: Vision system failed to read license plate at exit.")
        print("Attendant switching to manual validation via QR code scanning...")
        session_input = input("[MANUAL OVERRIDE]: Customer scans Exit Ticket. Enter Session ID: ").strip()
        
        if session_input:
            cursor.execute('''
                SELECT session_id, slot_id, total_fee, vehicle_id 
                FROM parking_sessions 
                WHERE session_id = ? AND exit_time IS NULL
            ''', (session_input,))
            active_session = cursor.fetchone()
            if not active_session:
                print("[ERROR]: Invalid Ticket or no active parking session found.")
                conn.close()
                return
        else:
            print("[ERROR]: No ticket provided.")
            conn.close()
            return
    else:
        cursor.execute("SELECT vehicle_id FROM vehicles WHERE plate_number = ?", (exit_plate,))
        exit_vehicle = cursor.fetchone()
        if exit_vehicle:
            cursor.execute('''
                SELECT session_id, slot_id, total_fee, vehicle_id 
                FROM parking_sessions 
                WHERE vehicle_id = ? AND exit_time IS NULL
            ''', (exit_vehicle[0],))
            active_session = cursor.fetchone()

    # Process Final Clearance
    if active_session:
        active_session_id, active_slot_id, total_fee, exit_vehicle_id = active_session
        if total_fee > 0:
            cursor.execute("UPDATE parking_sessions SET exit_time = CURRENT_TIMESTAMP WHERE session_id = ?", (active_session_id,))
            cursor.execute("UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ?", (active_slot_id,))
            cursor.execute('''
                UPDATE spatial_allocation 
                SET active_parked_count = active_parked_count - 1 
                WHERE zone_id = (SELECT zone FROM parking_slots WHERE slot_id = ?)
            ''', (active_slot_id,))
            conn.commit()
            print("\n>>> SUCCESS: FULL CLOSED-LOOP LIFECYCLE COMPLETED <<<")
            print("Exit barrier relay actuated -> VEHICLE EXITED.")
        else:
            print("FAILED: Fixed parking fee has not been cleared.")
    else:
        print("FAILED: Unregistered vehicle or no active parking session found.")
        
    conn.close()

if __name__ == "__main__":
    run_scenario_5_closed_loop()