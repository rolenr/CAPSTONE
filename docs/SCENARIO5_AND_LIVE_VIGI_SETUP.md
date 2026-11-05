# Scenario 5 and Live VIGI Camera Setup

Run the commands in this guide from the repository root, the directory containing `computer_vision/` and `backend/`. Scenario 5 replays two **recorded video files** and runs the entry, payment, and exit workflow. `live_vigi.py` reads **two live NVR channels**. Entrance detections are printed; confirmed exit detections are sent to the PHP API, which checks payment and closes eligible sessions automatically.

## 1. Prepare Python and the model files

Use Python 3.9 or newer and a terminal with access to a desktop display for Scenario 5's OpenCV preview windows. On macOS or Linux:

```sh
python3 -m venv .venv-cv
source .venv-cv/bin/activate
python -m pip install --upgrade pip
python -m pip install opencv-python easyocr ultralytics
```

If `.venv-cv` already exists and has these packages, activate it without recreating it. On Windows PowerShell, activate a newly created environment with `.venv-cv\Scripts\Activate.ps1` instead of `source`. Keep the following files in place:

| File | Used for |
| --- | --- |
| `computer_vision/entrance-feed-best1.pt` | Entrance plate detection |
| `computer_vision/best.pt` | Exit plate detection |
| `computer_vision/live_vigi.py` | Shared plate recognition rules and live camera program |
| `backend/database/parking.db` | Scenario 5 parking sessions and slot state |

Check the command-line options and the model files before processing video:

```sh
python computer_vision/scenario5.py --help
python computer_vision/live_vigi.py --help
ls -lh computer_vision/entrance-feed-best1.pt computer_vision/best.pt backend/database/parking.db
```

The first command should show `--entry-video PATH` and `--exit-video PATH`. The live command should show `--no-preview` and `--detection-only`. The `ls` command should list the two model files and database. The first model/OCR initialization may take a while, especially on CPU.

## 2. Select Scenario 5 entrance and exit recordings

Pass one entrance file and one exit file. Quote each path because filenames can contain spaces. These commands use shell variables so the same invocation can be reused with another pair of clips:

```sh
ENTRY_VIDEO="computer_vision/simulation_videos/ENTRANCE-PU-WHITE.mp4"
EXIT_VIDEO="computer_vision/simulation_videos/EXIT-PU-WHITE.mp4"

python computer_vision/scenario5.py \
  --entry-video "$ENTRY_VIDEO" \
  --exit-video "$EXIT_VIDEO"
```

To use different recordings, change only the two assignments above. For example:

```sh
ENTRY_VIDEO="computer_vision/simulation_videos/RegularCustomer - Reservation (Entry and Exit).mp4"
EXIT_VIDEO="computer_vision/simulation_videos/nissanExit.mp4"

python computer_vision/scenario5.py \
  --entry-video "$ENTRY_VIDEO" \
  --exit-video "$EXIT_VIDEO"
```

You can also provide absolute paths to recordings stored elsewhere. If a path does not exist, the script reports which option is wrong before it opens the database or loads the models. Running without either option uses the script's default clips; supplying just one option uses the default for the other, which may pair different vehicles. **The entrance and exit clips should show the same plate** for the complete workflow.

### White pickup command without variables

```sh
python computer_vision/scenario5.py \
  --entry-video "computer_vision/simulation_videos/ENTRANCE-PU-WHITE.mp4" \
  --exit-video "computer_vision/simulation_videos/EXIT-PU-WHITE.mp4"
```

Scenario 5 reads each recording at its source frame rate. Preview and recognition run separately, so slower inference can skip or briefly buffer frames while the clip continues at normal speed. Press `q` in a preview window to stop that feed. Run this script from an interactive terminal because it asks for cashier and guard input.

## 3. Complete Scenario 5's payment and departure steps

Scenario 5 **writes to `backend/database/parking.db`**. Recognizing an entrance plate can create a vehicle and parking session, mark a slot occupied, and update a zone count. Replaying the same entry may create another session; the script has no event deduplication. Use a test database/workspace if you need repeatable demonstrations without changing current parking records.

After entrance recognition, note the printed plate, session ID, and slot. To use the cashier web page, start the web server in a second terminal from the repository root:

```sh
php -S 127.0.0.1:8080 backend/router.php
```

Open `http://127.0.0.1:8080/cashier.html`, sign in with a cashier or administrator account, find the printed session or plate, and complete payment. Return to the Scenario 5 terminal and type `Y` when prompted. The script then replays the exit clip and checks the payment status stored in the database. Pressing `Y` does **not** mark a session paid.

If clearance passes, review the exit footage. Type `DEPARTED` only after the vehicle has physically passed. That confirmation closes the session and releases its slot. A recognized plate alone does not do either of those things.

### Expected Scenario 5 output

The exact session ID, slot, payment status, and timing depend on the database and run. A successful white pickup run can look like this (the plate `LAC7863` was observed in both supplied clips):

```text
--- [PHASE 1: ENTRANCE PROCESSING] ---
[VISION PIPELINE]: Recognized entrance plate 'LAC7863' using live camera rules.
[SYSTEM]: No prior reservation found. Walk-in detected. Finding available slot...
[ TICKET DISPENSED ]
TICKET/SESSION ID : <session_id>
SLOT              : <slot_number>
--- [PHASE 2: CASHIER PAYMENT] ---
[SIMULATION CONTROL]: After cashier processing, press 'Y' to check exit clearance: Y
--- [PHASE 3: EXIT VERIFICATION & CLEARANCE] ---
[VISION PIPELINE]: Recognized exit plate 'LAC7863' using live camera rules.
[EXIT CHECK]: Session #<session_id> payment status is PAID.
[EXIT CLEARANCE PASSED]: PAID. The guard may open the barrier.
[GUARD CONFIRMATION]: Type DEPARTED after the vehicle has exited: DEPARTED
>>> SUCCESS: VEHICLE DEPARTURE CONFIRMED <<<
Session closed and parking slot released.
```

If payment is still `UNPAID`, expect `[EXIT DENIED]`; settle payment in the cashier page and type `R` to recheck. If the entrance plate is not read, the script asks for a reservation token or a manual walk-in plate. If the exit plate is not read, it asks for an active parking session ID. These fallback prompts mean automatic recognition did not succeed for that feed.

## 4. Connect the live VIGI NVR feeds

The live program requires an NVR reachable from this computer, valid NVR credentials, RTSP access, and the PHP server connected to the same parking database. Its current channel mapping in `computer_vision/live_vigi.py` is:

| Role | NVR channel | Model |
| --- | ---: | --- |
| Entrance | 1 | `entrance-feed-best1.pt` |
| Exit | 13 | `best.pt` |

Those channel numbers are set in the `CAMERAS` constant near the top of `live_vigi.py`. They are **not** command-line options. If your NVR uses different channel numbers, edit that mapping and save the file before starting the program. The stream URL format is built by the script as `rtsp://<user>:<password>@<host>:<port>/live/<channel>/1/avm`.

Create the local shared camera-event token once, then start the PHP server in a second terminal from the same project directory:

```sh
php backend/database/create_exit_camera_token.php
php -S 127.0.0.1:8080 backend/router.php
```

The token is stored in the git-ignored `backend/database/exit_camera_token` file and is never printed. The PHP API and live vision program read this same file. If they run on different computers, set the same `PARKING_EXIT_EVENT_TOKEN` environment variable on both, and set `PARKING_EXIT_EVENT_URL` on the vision computer to the PHP API URL over HTTPS. The default API URL is `http://127.0.0.1:8080/backend/api/endpoints.php?action=exit_camera_event`. Keep the computers' clocks in sync; the API accepts only recent exit detections.

In a macOS `zsh` terminal, set the NVR host and username and enter the password without echoing it or putting it in shell history:

```sh
export VIGI_NVR_IP="192.168.1.100"      # replace with your NVR IP or hostname
export VIGI_NVR_USERNAME="your_username" # replace with your NVR username
read -s 'VIGI_NVR_PASSWORD?NVR password: '
export VIGI_NVR_PASSWORD
echo

# Only if your NVR uses a port other than 554:
export VIGI_NVR_RTSP_PORT="554"

python computer_vision/live_vigi.py
```

The port variable is optional; the default is `554`. The variables apply only to this terminal session. On another shell, set the same four variable names using that shell's normal environment-variable syntax. Do not print or paste the resulting credential-bearing RTSP URL into logs or screenshots.

For a machine without a desktop preview, use:

```sh
python computer_vision/live_vigi.py --no-preview
```

With previews, the program opens windows titled **VIGI Entrance - Channel 1** and **VIGI Exit - Channel 13**. Press `q` in either preview window or `Ctrl+C` in the terminal to stop. With `--no-preview`, use `Ctrl+C`.

### Expected live output

When both channels are available, the connection messages should resemble:

```text
[entrance] Stream connected.
[exit] Stream connected.
Watching entrance channel 1 and exit channel 13. Press q or Ctrl+C to stop.
```

When a moving plate is confirmed, the program prints an event with the local timestamp, for example:

```text
[2026-11-03T10:30:00+08:00] ENTRANCE plate: LAC7863
[2026-11-03T10:40:00+08:00] EXIT plate: LAC7863
```

Those timestamps and detections are examples. A confirmed exit plate is sent to the PHP API. If it matches an active parked session with `PAID`, `PAID_RESERVATION`, `PAID_OVERNIGHT`, `BYPASSED`, or a current `PAID_VENDOR` grace period, the API records the departure, releases the slot, and updates the zone count. The live terminal then prints a `Session ... closed; slot ... released` message. Unpaid, unknown, and expired vendor statuses remain parked. Entrance detections still do not create sessions. If the NVR cannot be opened, expect `[entrance] Stream unavailable; retrying.` or the corresponding exit message. Check the host, port, credentials, RTSP settings, network reachability, and channel mapping.

The cashier's **Check Plate Clearance** button checks payment status without closing a session. Scanning the issued entry QR token in the cashier page validates the token and closes an eligible session immediately. The QR is never prefilled by a lookup. Neither path operates the physical barrier. To run the earlier print-only camera behavior explicitly, use `python computer_vision/live_vigi.py --detection-only`.

## 5. Quick checks when output differs

| Symptom | Check |
| --- | --- |
| `ModuleNotFoundError` | Activate the virtual environment and install the three Python packages above. |
| Missing `.pt` model | Confirm both weight files are in `computer_vision/`. |
| Missing video error | Quote the path and use an existing file; check `--entry-video` and `--exit-video` separately. |
| Preview cannot open | Run Scenario 5 on a graphical desktop. For live detection without windows, use `--no-preview`. |
| Entrance and exit plates differ | Confirm the two recordings show the same vehicle. |
| No plate event | Check whether the plate is visible, sharp, inside the camera's recognition region, and moving through the view. Manual fallback in Scenario 5 does not count as an automatic read. |
| Exit denied | Complete payment for the active session; `Y` only advances to the check. |
| Live stream retries | Check the NVR network, RTSP access, credentials, port, and channel numbers. |

For broader notes on the other recorded scenarios, see [Recorded-Video Vision Demo Guide](RECORDED_VIDEO_DEMO.md).
