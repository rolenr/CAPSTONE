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
php backend/database/seed_demo_accounts.php
export LOCAL_PAYMENTS_ENABLED=1
php -S 127.0.0.1:8080 backend/router.php
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
  * Features: Live 69-slot map availability, digital QR pass wallet, and prepaid booking.
* **Cashier Portal**:
  * Email: `cashier@parking.test`
  * Password: `cashier123`
  * Features: Session lookup and parking payment.
* **Tenant Vendor Portal (Joey's Restaurant)**:
  * Email: `vendor@joeys.test`
  * Password: `vendor123`
  * Features: Customer parking validation and departure grace.

These credentials are for the local demo quick-fill controls. The PHP router and API enforce role access; administrators can open all portals, while other accounts are limited to their assigned portal and actions.

The live map uses `frontend/images/parking-lot-mapv2.png`. Active slot counts are A=9, B=7, C=6, D=7, E=10, and F=30. For an existing database created before map v2, run `python3 backend/database/update_map_v2_slots.py`. The migration preserves historical sessions by retiring nine old slot rows and moves active assignments from A-10 to A-09 and B-09 to B-07. It can be run again safely when the map inventory changes.

Reservation checkout uses a **local payment simulator**. It holds a slot for 10 minutes and issues a demo pass only after the server records the successful simulation. No real card or funds are involved. Follow [Local Simulated Payments](docs/LOCAL_SIMULATED_PAYMENTS.md) for setup, test scenarios, and expected output.

### 3. Run Computer Vision Simulations
Ensure Python 3.9+ is installed with the required dependencies (`opencv-python`, `easyocr`, `ultralytics`). Run the current sectional-first simulation with the recorded business-rule commands in the guides below. `scenario5.py` remains available for its separate closed-loop test when supplied with matching gate clips.

The simulations use `backend/database/parking.db` and change parking records. For the current sectional-first recorded and live-gates flows, use the **[Parking Simulation Guide](docs/SECTIONAL_FIRST_SIMULATION.md)**.
For the 26 recorded business-rule runs, use the **[Recorded Business-Rule Commands](docs/RECORDED_BUSINESS_RULE_RUNS.md)**.
For physical VIGI NVR channel setup and live gate operation, use the **[Live VIGI Setup Guide](docs/LIVE_VIGI_SETUP.md)**.

---

## Project Status & Tracking
For a detailed status breakdown of all components, API endpoints, and upcoming sprint milestones, refer to:  
👉 **[PROJECT_TRACKER.md](PROJECT_TRACKER.md)**
