import sqlite3
import cv2
import easyocr
import re
from collections import Counter, defaultdict
from ultralytics import YOLO

import os

# Dynamic path resolution (cross-platform compatible with fallback)
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(SCRIPT_DIR)
DB_PATH = os.path.join(BASE_DIR, "backend", "database", "parking.db")
if not os.path.exists(DB_PATH):
    DB_PATH = os.path.join(BASE_DIR, "parking.db")
    if not os.path.exists(DB_PATH):
        DB_PATH = r"D:\xampp\htdocs\CAPSTONE\parking.db"

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
            ymin, ymax = int(height * 0.30), int(height * 0.95)
            xmin, xmax = int(width * 0.20), int(width * 0.95) 
        else:
            # Original exit ROI on the right side of the screen
            ymin, ymax = int(height * 0.05), int(height * 0.70)
            xmin, xmax = int(width * 0.60), int(width * 0.95)
            
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
                                
                                # Validate string format
                                if re.match(r'^[A-Z]{3}\d{3,4}$', cleaned_text):
                                    plate_history[track_id].append(cleaned_text)
                                    
                                    # --- CONSECUTIVE CHECK LOGIC ---
                                    if len(plate_history[track_id]) >= 3:
                                        last_three = plate_history[track_id][-3:]
                                        
                                        if last_three[0] == last_three[1] == last_three[2]:
                                            confirmed_plate = last_three[0]
                                            print(f"[VISION PIPELINE]: Extracted Exit Plate '{confirmed_plate}' (3 Consecutive Matches)")
                                            
                                            cap.release()
                                            cv2.destroyAllWindows()
                                            return confirmed_plate

        cv2.imshow(window_name, frame)
        if cv2.waitKey(25) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()
    return None

def run_scenario_4():
    print("=================================================================")
    print("      RUNNING SCENARIO 4: EXIT CAMERA & PAYMENT CLEARANCE        ")
    print("=================================================================\n")
    
    # Dynamic resolution for model weights & exit video
    model_weights = os.path.join(SCRIPT_DIR, "best.pt")
    if not os.path.exists(model_weights):
        model_weights = r"R:\Capstone\best.pt"
    
    video_dir = os.path.join(SCRIPT_DIR, "simulation_videos")
    exit_video = os.path.join(video_dir, "nissanExit.mp4")
    if not os.path.exists(exit_video):
        exit_video = r"R:\Capstone\nissanExit.mp4"
    
    conn = sqlite3.connect(DB_PATH, timeout=10)
    cursor = conn.cursor()
    
    exit_plate = process_video_feed(exit_video, model_weights, 'Scenario 4: Exit Feed', feed_type="exit")
    active_session = None
    
    # --- MANUAL OVERRIDE LOGIC ---
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
        # --- VISION SYSTEM SUCCESSFUL ---
        cursor.execute("SELECT vehicle_id FROM vehicles WHERE plate_number = ?", (exit_plate,))
        exit_vehicle = cursor.fetchone()
        if exit_vehicle:
            cursor.execute('''
                SELECT session_id, slot_id, total_fee, vehicle_id 
                FROM parking_sessions 
                WHERE vehicle_id = ? AND exit_time IS NULL
                ORDER BY session_id DESC LIMIT 1
            ''', (exit_vehicle[0],))
            active_session = cursor.fetchone()

    # --- PROCESS FINAL CLEARANCE ---
    if active_session:
        active_session_id, active_slot_id, total_fee, exit_vehicle_id = active_session
        
        print("\n[DECISION MATRIX EVALUATION]:")
        if exit_plate:
            print(f"  • Extracted Plate  : {exit_plate}")
        print(f"  • Session ID       : {active_session_id}")
        print(f"  • Financial Status : {'CLEARED' if total_fee > 0 else 'UNPAID'}")
        
        if total_fee > 0:
            print("  • Verification     : MATCH CONFIRMED (PASSED)")
            
            cursor.execute("UPDATE parking_sessions SET exit_time = CURRENT_TIMESTAMP WHERE session_id = ?", (active_session_id,))
            cursor.execute("UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ?", (active_slot_id,))
            cursor.execute('''
                UPDATE spatial_allocation 
                SET active_parked_count = active_parked_count - 1 
                WHERE zone_id = (SELECT zone FROM parking_slots WHERE slot_id = ?)
            ''', (active_slot_id,))
            conn.commit()
            
            print(f"  • Database State   : Closed Session #{active_session_id} and freed spatial allocations.")
            print("\n>>> SUCCESS: EXIT CLEARED <<<")
            print("[MICROCONTROLLER RELAY]: Signal sent. EXIT BARRIER OPEN.")
        else:
            print("  • Verification     : REJECTED (Unpaid fixed fee).")
            print("FAILED: Fixed parking fee has not been cleared.")
    else:
        if exit_plate:
            print(f"\n[DECISION MATRIX]: Unregistered vehicle '{exit_plate}' or no active session found. Barrier CLOSED.")
        else:
            print("\n[DECISION MATRIX]: No active parking session found. Barrier CLOSED.")
        
    conn.close()

if __name__ == "__main__":
    run_scenario_4()