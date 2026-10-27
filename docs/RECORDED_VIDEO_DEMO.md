# Recorded-Video Vision Demo Guide

This guide covers the existing recorded-video scenarios in `computer_vision/`. It describes current script behavior and expected code paths; it does not claim that a clip was successfully recognized or that the system supports live cameras.

## Requirements

- Python 3.9 or later, as listed in the repository README.
- A desktop environment that can display OpenCV windows. The scripts call `cv2.imshow`; a headless shell may not be able to show the video window.
- Python packages: `opencv-python`, `easyocr`, and `ultralytics`. There is no pinned computer-vision requirements file, so package versions are not locked by this repository.
- The model weights in `computer_vision/`: `entrance-feed-best1.pt` for entrance scenarios and `best.pt` for exit scenarios.
- The recorded clips in `computer_vision/simulation_videos/`, unless another clip is passed on the command line.

Create and activate a virtual environment, then install the direct Python dependencies:

```text
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install opencv-python easyocr ultralytics
```

On Windows, activate the environment with `.venv\Scripts\activate` instead of the `source` command. The packages install their own numerical and inference dependencies. The scripts request CPU inference; first-time package/model initialization may take time.

Run commands from the repository root. Check the available options without processing video:

```text
python3 computer_vision/scenario3.py --help
python3 computer_vision/scenario4.py --help
python3 computer_vision/scenario5.py --help
```

## Database and replay

All three scenarios use the existing `backend/database/parking.db`, the same SQLite file as the web application. The scripts require that file to exist and never create or reset it. Entry and confirmed departure change real sessions, slots, reservations, and zone counts. Use the cashier web page to settle a Scenario 5 session before exit clearance. Replaying an entry clip can create another active session because event deduplication is not implemented; inspect the current records before replaying.

## Run a scenario

The checked-in default clip is used when the matching option is omitted. To choose another existing video, provide a path; paths may be absolute or relative to the repository root.

```text
# Scenario 3: walk-in entrance and slot assignment
python3 computer_vision/scenario3.py
python3 computer_vision/scenario3.py --entry-video "computer_vision/simulation_videos/Entry Fortuner -Day2 (walk-in).mp4"

# Scenario 4: exit plate and payment clearance
python3 computer_vision/scenario4.py --exit-video "computer_vision/simulation_videos/mitsubishiExit.mp4"

# Scenario 5: entry, cashier payment, and exit flow
python3 computer_vision/scenario5.py \
  --entry-video "computer_vision/simulation_videos/RegularCustomer - Reservation (Entry and Exit).mp4" \
  --exit-video "computer_vision/simulation_videos/nissanExit.mp4"
```

The scripts validate selected video paths before opening the database or loading models. A missing path prints the option, the resolved file path, and a correction hint. During processing, press `q` in the OpenCV video window to stop that feed. Some paths ask for terminal input, so run them in an interactive terminal.

## Expected outputs and prerequisites

These are expected outcomes from reading the current script logic. Actual plate detections, matching clips, timing, and visual quality have not been verified by running the videos.

| Scenario | Expected behavior when prerequisites are met | Important prerequisite or limitation |
| --- | --- | --- |
| **3 — Entrance / walk-in** | Recognizes a plate, finds or creates its vehicle record, selects a free slot, creates an active session, and marks the slot occupied. It prints a ticket/session and slot summary. | Uses existing real vehicles and reservations. If plate recognition fails, the script asks for a reservation token or a plate in the terminal. It writes directly to the project database. |
| **4 — Exit / payment check** | Finds an active parked session for the recognized plate. Checks its payment status, then asks for physical departure confirmation before closing the session and freeing the slot. | Requires an active matching session with an eligible payment status. A positive fee alone does not authorize exit. |
| **5 — Closed-loop script** | Creates an entry session, waits for cashier payment, processes the exit clip, checks payment status, and asks for physical departure confirmation before closing the session. | The entry and exit clips must identify the same vehicle. Cashier payment must be committed to the project database before exit clearance. |

When ALPR does not return a plate, Scenarios 3-5 may prompt for manual values such as a plate, reservation token, or session ID. These are fallback paths in the scripts, not proof of camera accuracy.

## Scope and limitations

- This is recorded-file processing only. VIGI cameras, the DJI drone, DJI RC, and physical barrier controllers are not connected.
- Messages mentioning a relay or an open barrier are printed simulation text. The scripts do not actuate a physical boom barrier.
- Scenarios 4 and 5 read the application's payment status from SQLite, but they do not call the US-01 clearance endpoint. Physical departure is confirmed in the terminal.
- Scenario 5 writes sessions directly to the project database; the cashier web page can process the same session.
- No occupancy-only video or section occupancy detector is configured. Slot selection in Scenarios 3 and 5 uses database availability, not detections from the overhead camera/drone.
- The current scripts do not emit the versioned event payload in [DETECTION_EVENT_SCHEMA.md](DETECTION_EVENT_SCHEMA.md), and their outputs are not integrated into the web dashboard.
- The default model paths are the model files in `computer_vision/`. Legacy Windows fallback paths remain in the scripts; if a local model file is missing, provide the expected file before starting the scenario.

For the clip-by-clip inventory and script mapping, see the TASK02.1 section in [USER_STORIES_AND_TASKS.md](USER_STORIES_AND_TASKS.md).
