# Parking Ltd. — Automated Parking Management System
**Centralized Facility Operations, Computer Vision Telemetry & Transaction Ledger**  
*Capstone Project: CAP-2520-IT*

---

## Architecture Overview

The system is organized into modular tiers for clear separation of concerns:

* **`frontend/`**: Presentation layer featuring the Admin Operations Console, Customer Live Map, Advance Slot Reservations, and Violations Tracking. Designed with a clean, responsive enterprise design system (zero emojis, tabular typography, slate/navy palette).
* **`backend/`**: Server-side REST API controller (`api/endpoints.php`) and persistent SQLite relational database (`database/parking.db`).
* **`computer_vision/`**: Edge optical inference pipelines running YOLOv8 vehicle detection, EasyOCR automatic license plate recognition (ALPR), and entrance/exit gate simulations.
* **`docs/`**: Capstone proposal and project specifications.
* **`PROJECT_TRACKER.md`**: Living status matrix tracking every module, endpoint, and table.

---

## Quickstart & Local Setup

### 1. Launch Web Server
From the project root directory, run the built-in PHP development server:
```bash
php -S 127.0.0.1:8080
```
Open **`http://127.0.0.1:8080`** in any web browser. You will be automatically directed to the sign-in portal.

### 2. Default Access Credentials
* **Administrator Portal**:
  * Email: `rorocruz@gmail.com`
  * Password: `admin123`
  * Features: Gross revenue BI, 30-day timeline, tariff matrix, zone heatmap, 24h influx, violations resolution, ALPR telemetry, slot overrides, and audit logs.
* **Customer Driver Portal**:
  * Email: `driver1@gmail.com`
  * Password: `password123`
  * Features: Live 78-slot map availability, digital QR pass wallet, and prepaid booking.

### 3. Run Computer Vision Simulations
Ensure Python 3.9+ is installed with the required dependencies (`opencv-python`, `easyocr`, `ultralytics`):
```bash
# Scenario 3: Entrance detection & dynamic slot assignment
python3 computer_vision/scenario3.py

# Scenario 4: Exit detection & payment clearance
python3 computer_vision/scenario4.py

# Scenario 5: Full closed-loop cycle simulation
python3 computer_vision/scenario5.py
```

---

## Project Status & Tracking
For a detailed status breakdown of all components, API endpoints, and upcoming sprint milestones, refer to:  
👉 **[PROJECT_TRACKER.md](PROJECT_TRACKER.md)**