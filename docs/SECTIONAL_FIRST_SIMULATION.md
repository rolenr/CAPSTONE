# Parking simulation guide

`computer_vision/full_simulation.py` runs either recorded entrance/exit clips or live VIGI gate channels with a recorded sectional clip. `computer_vision/scenario5.py` is an alias for its `recorded` mode; it has no separate parking workflow. Both modes use the current `backend/database/parking.db` and the same entry, allocation, payment, enforcement, and exit rules. Each `--scenario-id` creates a run-scoped sectional baseline in `slot_observations` and keeps event/evidence files under `backend/database/simulation_runs/<scenario-id>/`. No second database is created. Use a new scenario ID for each run.

| Mode | Entrance | Sectional | Exit |
| --- | --- | --- | --- |
| `recorded` | Recorded clip | Recorded clip | Recorded clip |
| `live-gates` | VIGI channel 1 | Recorded clip | VIGI channel 13 |

## Prepare

Run commands from the repository root in `.venv-cv`. Required files include `computer_vision/best.pt` for the recorded gate clips, `computer_vision/entrance-feed-best1.pt` for live entrance, `yolov8n.pt` for vehicle occupancy, the uploaded bay model at `content/runs/detect/train/weights/best.pt`, the chosen videos, and their calibrated region JSON. The camera event token comes from `PARKING_EXIT_EVENT_TOKEN` or `backend/database/exit_camera_token`; if it is missing, create it with `php backend/database/create_exit_camera_token.php`.

The runner starts the local PHP API on port 8080 if needed. To keep the web app open independently for reservations, cashier actions, and admin review, start it in another terminal:

```sh
LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php
```

Before each run, finish any open session for the test plate in the application. For the white pickup, check without changing the database:

```sh
sqlite3 backend/database/parking.db "SELECT ps.session_id,s.slot_number,ps.payment_status FROM parking_sessions ps JOIN vehicles v ON v.vehicle_id=ps.vehicle_id JOIN parking_slots s ON s.slot_id=ps.slot_id WHERE ps.exit_time IS NULL AND UPPER(REPLACE(REPLACE(v.plate_number,' ',''),'-',''))='LAC7863';"
```

If a simulation stopped before its exit feed, open `http://127.0.0.1:8080/frontend/admin.html` and select **Operations → Simulation Sessions**. An administrator can close that open simulation session with a writtn reason after payment or validation and violation fees are cleared. This records `SIMULATION_RECOVERY`, releases the assigned bay even if a prior manual override marked it free, and reconciles the zone count. It does **not** claim the exit camera observed a departure. For a normal completed run, use the paid/validated exit feed. Slot Overrides cannot close a session and now reject an override on a bay used by an open session.

To exercise a reservation, sign in as an administrator at `http://127.0.0.1:8080/frontend/reserve.html`, select Zone E and **Specific bay E-05** for `LAC7863`, then complete local demo payment or select the audited admin payment exemption. Confirm that the reservation is ACTIVE before entry. A walk-in can use the observed vacant bay without a reservation; an active reservation for a different bay blocks this labeled E-05 run.

## Recorded gates

The initial three seconds of `SectOcc_Sample1.mp4` show the pickup's target bay vacant. The camera mapping is `section_e_sample1`; its exact filename is case sensitive. The E-05 bay number is a provisional mapping of the pickup's position and should be checked against physical bay markings.

```sh
source .venv-cv/bin/activate
python computer_vision/full_simulation.py recorded \
  --scenario-id recorded_white_pickup_e_02 \
  --entry-video computer_vision/simulation_videos/ENTRANCE-PU-WHITE.mp4 \
  --sectional-video computer_vision/simulation_videos/SectOcc_Sample1.mp4 \
  --exit-video computer_vision/simulation_videos/EXIT-PU-WHITE.mp4 \
  --plate LAC7863 --clip-plate LAC7863 --clip-slot E-05
```

The runner:

1. Analyzes the opening sectional clip and prints vacant/occupied active bays. It pauses before entry.
2. Reads the entrance plate, then assigns E-05 only if the baseline says vacant and the bay is free in the main database. It pauses before parking replay.
3. Continues the sectional clip after the baseline segment and stops once the assigned bay has been continuously occupied for 10 video seconds. If that does not happen before the clip ends, parking is not confirmed and the run stops. It then pauses for payment/validation before exit.
4. Reads the exit plate and requests clearance. If denied, it offers a retry after staff/cashier action.

With a desktop display, the pre-entry baseline and parking replay each open a **Sectional Occupancy** video window, with calibrated bay outlines, observed occupied/vacant labels, sampled vehicle boxes, and the clip time. The entrance and exit clips keep their own preview windows. Press `q` in the sectional window to stop that stage and the run. Use `--no-preview` to run without any video windows; JSONL events and evidence images are still saved.

`--preferred-slot E-05` explicitly chooses a vacant bay. Without it, `--clip-slot` is used as the preferred bay. Pass `--clip-slot ""` and omit `--preferred-slot` to allocate the first eligible vacant bay in the observed section. `--baseline-seconds` controls how much of the clip establishes the initial snapshot (default 3); `--parked-seconds` controls the 10-second hold; `--auto-advance` skips the first two stage prompts.

Use `http://127.0.0.1:8080/frontend/cashier.html` for payment or grace handling and `http://127.0.0.1:8080/frontend/admin.html` for enforcement review. The runner waits before exit. An unpaid exit remains denied until the session is cleared; it offers a retry.

The sample sectional recording ends with E-05 occupied; it does not show that vehicle leaving the bay. The exit camera can close the parking session, but the last physical observation will still say occupied until a later sectional feed shows vacancy. The `--clip-plate` label is a test binding, not plate OCR evidence.

## Live gates

Set `VIGI_NVR_IP`, `VIGI_NVR_USERNAME`, `VIGI_NVR_PASSWORD`, and optionally `VIGI_NVR_RTSP_PORT` in the terminal environment. On macOS `zsh`, enter the password without echoing it:

```sh
export VIGI_NVR_IP="192.168.1.100"       # replace with your NVR address
export VIGI_NVR_USERNAME="your_username"  # replace with your NVR user
read -s 'VIGI_NVR_PASSWORD?NVR password: '
export VIGI_NVR_PASSWORD
echo
```

The VIGI channel mapping is in `computer_vision/live_vigi.py`: entrance channel 1, exit channel 13. The runner captures the same baseline first, then starts both cameras. The entrance request carries the preferred bay; after the live entry session appears, the runner pauses before parking replay.

```sh
python computer_vision/full_simulation.py live-gates \
  --scenario-id live_white_pickup_e_02 --plate LAC7863 \
  --sectional-video computer_vision/simulation_videos/SectOcc_Sample1.mp4 \
  --clip-plate LAC7863 --clip-slot E-05
```

Only use a matching `--clip-plate` label if the live vehicle and recording are deliberately paired. For an unrelated recording, pass `--clip-plate "" --clip-slot ""`; it can report physical occupancy without claiming the live vehicle is in that bay. Use `--no-preview` if OpenCV windows are unavailable.

## Sectional model and enforcement

The uploaded bay checkpoint locates slots; `yolov8n.pt` detects vehicles in each sampled frame. Three stable samples determine observed occupied/vacant state. `computer_vision/sectocc_sample1_regions.json` contains draft polygons for the Section E clip and must be calibrated before relying on accuracy. Model validation mAP for bay labels is not an occupancy accuracy measure. Zone F has no sectional camera observation and uses its allocation count. Current inactive bay labels are ignored by the API.

Optional `--sectional-plate-model path/to/plate.pt` enables sectional OCR where the view supports it. A labeled clip match without OCR is `CLIP_BOUND`, while a confirmed OCR match is `MATCH`. Wrong-slot and wrong-plate findings go to staff review; the clip label itself is a test assertion.

Only calibrated `no_parking_zones` can support enforcement. A continuously stationary vehicle in a driveway or double-parking region for at least 300 seconds creates a review alert; staff confirmation issues a ₱300 fine. The supplied sectional clip is about 72 seconds and cannot demonstrate that rule.

## Scenario checklist

Use a fresh scenario ID for each case and finish an active session before replaying the same plate: matching E-05 reservation, walk-in allocation, 15-minute grace, local payment, tenant validation and 20-minute exit window, unpaid exit, wrong slot, unrelated clip identity, QR fallback, overnight total fee, and reviewed restricted-area alert. The five-minute alert needs a separate labeled clip of sufficient length. After each run, check the session, assigned bay, observed bay, payment status, exit result, zone count, and any alert or fine.
