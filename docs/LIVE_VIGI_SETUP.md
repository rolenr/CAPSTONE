# Live VIGI entrance and exit setup

This guide connects the VIGI NVR to the parking app. Entrance is **NVR channel 1** and exit is **NVR channel 13**. Both use `computer_vision/live_vigi.py` and the same `PlateDetector` class that reads recorded entrance and exit clips in `computer_vision/scenario5.py` and `computer_vision/full_simulation.py`. The gate detector uses `computer_vision/best.pt`, EasyOCR, motion and repeated-read confirmation, and `yolov8n.pt` for supporting vehicle color. The live feeds publish confirmed gate events to `backend/api/endpoints.php`.

## Choose the run mode

| Mode | Entrance | Sectional occupancy | Exit | Command |
| --- | --- | --- | --- | --- |
| Direct live gates | VIGI channel 1 | None in this command | VIGI channel 13 | `python computer_vision/live_vigi.py` |
| Combined simulation | VIGI channel 1 | Recorded sectional snapshot and parking replay | VIGI channel 13 | `python computer_vision/full_simulation.py live-gates ...` |

The combined simulation first observes the selected sectional zone, then waits for a live entrance session, replays parking, and waits for live exit clearance. It uses the **main** `backend/database/parking.db`, so sessions, payments, occupancy, and audit events appear in the regular frontend. The current combined live mode supports one recorded sectional clip; the five separate sectional clips belong to recorded-gates mode.

## 1. Prepare the computer and NVR

1. Use the NVR address and account already configured for this project. Confirm this computer can reach its local IP and RTSP port. Entrance is channel 1 and exit is channel 13. The code builds `rtsp://<user>:<password>@<host>:<port>/live/<channel>/1/avm`; confirm that this path works with the installed NVR firmware and account.
2. Confirm the existing NVR account is permitted to view both channels over RTSP. Avoid printing or saving an RTSP URL with credentials in a log or screenshot.
   On the current setup, the NVR is `192.168.31.2`. Before starting recognition, make sure the Mac is connected to the NVR's `192.168.31.x` network or has a route to it. For example, a Mac address of `192.168.18.x` with no route to `192.168.31.x` will time out before any video or LPR reaches Python.
   Check the network and RTSP port from the Mac:

   ```sh
   ipconfig getifaddr en0
   nc -G 3 -vz 192.168.31.2 554
   ```

   `nc` must report a successful connection. If it times out, connect to the NVR network or have the network administrator provide routing between the two subnets; then recheck the NVR IP and RTSP port. A password or recognition change cannot fix an unreachable TCP port.
3. From the repository root, activate the computer vision environment and check the local assets:

   ```sh
   source .venv-cv/bin/activate
   python -c "import cv2, easyocr, ultralytics; print('Vision dependencies ready')"
   ls -l computer_vision/best.pt yolov8n.pt backend/database/parking.db
   ```

   If the Python import fails, install the missing package in `.venv-cv` (`opencv-python`, `easyocr`, or `ultralytics`). The live process also needs a graphical desktop for preview windows; `--no-preview` is available for headless detection.

4. Create the camera event secret once, if it does not exist:

   ```sh
   php backend/database/create_exit_camera_token.php
   ```

   The backend and detector read `backend/database/exit_camera_token` by default. Keep this file private. If you use `PARKING_EXIT_EVENT_TOKEN` instead, give the **same value of at least 32 characters** to both the PHP server and the detector.

## 2. Start the app and set VIGI credentials

In terminal 1, from the repository root:

```sh
export LOCAL_PAYMENTS_ENABLED=1
php -S 127.0.0.1:8080 backend/router.php
```

Open `http://127.0.0.1:8080/frontend/` and sign in. The Cashier POS is at `/frontend/cashier.html`; Admin is at `/frontend/admin.html`. This server and the vision process must use the same database.

In terminal 2, from the same repository root:

```sh
source .venv-cv/bin/activate
export VIGI_NVR_IP="192.168.31.2"
export VIGI_NVR_USERNAME="admin"
read -s 'VIGI_NVR_PASSWORD?NVR password: '
export VIGI_NVR_PASSWORD
echo
# Optional if your RTSP port differs from 554:
# export VIGI_NVR_RTSP_PORT="554"
```

These variables apply only to terminal 2. Do not put the password on a command line or commit it to the repository. The default event URL is `http://127.0.0.1:8080/backend/api/endpoints.php?action=exit_camera_event`; the entrance URL is derived from it. If the local API uses a different permitted URL, set `PARKING_EXIT_EVENT_URL` and `PARKING_ENTRY_EVENT_URL` explicitly. The code accepts local HTTP or HTTPS for event URLs.

## 3. Check both feeds without changing parking sessions

```sh
python computer_vision/live_vigi.py --detection-only
```

Look for `[entrance] Stream connected.` and `[exit] Stream connected.` and two preview windows. Move a vehicle through each camera view. A confirmed plate prints as `ENTRANCE plate: ...` or `EXIT plate: ...`. Detection-only mode does **not** send gate events to the API, allocate a bay, issue an entry QR, or close a session. Press `q` in a preview window or Ctrl+C to stop.

If a stream does not connect, check its NVR channel, credentials, RTSP permission, IP, port, and network path. The process retries interrupted streams. If video connects but a plate is not confirmed, inspect camera focus, angle, motion, lighting, and whether the plate stays visible through several frames. A single plausible OCR frame is intentionally insufficient. `--no-preview` still prints detections but gives no visual feedback.

## 4. Run direct live entrance and exit

```sh
python computer_vision/live_vigi.py
```

On a confirmed entrance plate, the detector sends the plate, observed entrance color, channel 1, and event time to `camera_entry_event`. The backend allocates an eligible vacant bay, opens a parking session, stores the color, and issues an entry QR. The terminal prints the scannable QR **and its token** and saves a PNG under `backend/database/simulation_runs/<live-scenario-id>/`. A readable plate still receives this QR for an unreadable exit.

If the entrance plate cannot be confirmed, show **exactly one vehicle** in the entrance preview and press `u`. This requests a QR-only walk-in entry for that visible vehicle, records its observed color (or `UNKNOWN` if uncertain), and prints its QR and token. The QR is the credential for its exit; the internal `QR_ONLY_...` identifier is not a plate. The `u` control needs the preview window, so it is unavailable with `--no-preview`.

For an unreadable plate with an **active paid reservation**, show exactly one vehicle and press `r`, then scan its reservation QR token into the hidden terminal prompt. The backend resolves that token to the reserved plate and bay. The entry ticket prints the same token for exit fallback. The token is not printed as part of a denied API response.

A QR-only walk-in has no verified VIP identity, so ordinary zone eligibility still applies. For a reserved or VIP-only bay, use a credentialed reservation or staff workflow rather than treating the anonymous QR as proof of eligibility.

Before exit, complete grace handling, payment, tenant validation, and any required staff review in the app. A confirmed channel 13 plate requests exit clearance through `exit_camera_event`; the backend closes and releases the session only when its rules allow it. A denied live exit prints the API reason and is written to the Admin audit trail. For an unreadable exit plate or a QR-only entry, use **Cashier POS → Fallback: Entry QR Token**: look up and settle the session, compare its entrance color as supporting evidence, then scan the issued QR to record exit. Color alone never authorizes exit. Direct live `u` creates an anonymous walk-in session; use `r` for an active paid reservation.

## 5. Run live gates with recorded sectional occupancy

Use a sectional recording whose target bay is visibly vacant at the start and then becomes occupied. Configure a calibrated `--regions` file and camera ID for that zone. This example uses the existing Zone E sample; replace the paths and bay with the recording being demonstrated:

```sh
python computer_vision/full_simulation.py live-gates \
  --sectional-video computer_vision/simulation_videos/SectOcc_Sample1.mp4 \
  --regions computer_vision/sectocc_sample1_regions.json \
  --camera-id section_e_sample1 \
  --clip-slot E-05 \
  --clip-plate LAC7863 \
  --plate LAC7863
```

`--plate` checks the expected live plate; omit it when testing the unreadable-entrance QR path. Set `--clip-plate` only when the staged vehicle and sectional recording deliberately represent the same car. The runner generates a scenario ID if omitted, captures the pre-entry sectional baseline, then starts the VIGI process. After entry, press Enter when prompted to replay the sectional parking portion. The clip must confirm the assigned bay occupied for the configured hold (`--parked-seconds`, default 10 video seconds). Settle the session in the frontend; the runner then waits for live exit clearance. For QR-only entry, scan the issued QR in Cashier POS to close the session.

If you want a particular bay, `--clip-slot` is the recorded target and is used as the preferred allocation unless `--preferred-slot` overrides it. The bay must be observed vacant in the baseline and be available under the backend's reservation and eligibility rules. An unrelated clip can report occupancy, but it must not be labeled as a plate-to-bay match. The live process is started by the runner; do not start a second `live_vigi.py` process for the same channels and scenario.

## Recognition and verification

Both modes use the same entrance and exit detector as the recorded simulation: `PlateDetector` in `live_vigi.py`. It tracks plate boxes with ByteTrack, checks approach or cross-frame movement, reads padded and enlarged plate crops with EasyOCR, normalizes plausible plates, and requires repeated agreement before publishing a gate event. The entrance view uses a wider search area and lower detector confidence than exit to handle the recorded entrance angles. `GateColorObserver` records a simple color label after at least three agreeing frame readings; if the color is uncertain it records `UNKNOWN`. Color is supporting evidence, not an exit credential.

After a test, refresh Cashier POS, Live Map, and Admin. Check the entrance session, assigned bay, entry QR, stored color, sectional observation if used, payment status, exit method, released slot, zone count, and audit log. The QR PNG and sectional evidence are in `backend/database/simulation_runs/<scenario-id>/`. A stopped run can leave an open session; resolve it through the normal cashier/exit flow or Admin → Simulation Sessions recovery with a reason. Freeing a slot flag alone does not close its session.

## Quick troubleshooting

| Symptom | Check |
| --- | --- |
| `Stream unavailable; retrying` with a five-second FFmpeg timeout | First run `nc -G 3 -vz 192.168.31.2 554`. If it times out, fix the network path before checking credentials, channels, or LPR. |
| RTSP port connects but the stream still fails | Confirm RTSP is enabled, account permissions, channel numbers 1 and 13, and the NVR live-stream URL format. |
| Connected video, no plate event | Focus, plate size and angle, lighting, motion across multiple frames; inspect preview |
| `API unavailable` | PHP server on port 8080, event URL, and whether both processes use the same host |
| HTTP 403 camera authorization | Shared camera event token or token file; at least 32 characters |
| HTTP 409 on direct live entry | Existing open session, reservation, bay availability, or zone eligibility; no sectional baseline is required |
| Exit denied | Payment, grace/tenant window, outstanding reviewed fine, or entrance/exit color review |
| QR-only vehicle remains parked | Scan the **issued** entry QR in Cashier POS after settlement; internal ID and color are not credentials |

For the recorded-only and five-clip commands, see [Parking simulation guide](SECTIONAL_FIRST_SIMULATION.md). For zone profiles, see [Recorded test cases](../computer_vision/recorded_test_cases/README.md).
