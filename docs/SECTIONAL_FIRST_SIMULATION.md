# Parking simulation guide

`computer_vision/full_simulation.py` runs either recorded entrance/exit clips or live VIGI gate channels with a recorded sectional clip. `computer_vision/scenario5.py` is an alias for its `recorded` mode. Recorded zone cases now replay only the selected zone's snapshot into the main `backend/database/parking.db` before entrance. Entry, payment, occupancy, alerts, and exit records remain in that database and appear in the regular `http://127.0.0.1:8080/frontend/` app after refresh. Evidence files remain under `backend/database/simulation_runs/<scenario-id>/`. A scenario ID is generated automatically if omitted.

| Mode | Entrance | Sectional | Exit |
| --- | --- | --- | --- |
| `recorded` | Recorded clip | Recorded clip | Recorded clip |
| `live-gates` | VIGI channel 1 | Recorded clip | VIGI channel 13 |

## Prepare

Run commands from the repository root in `.venv-cv`. Required files include `computer_vision/best.pt` for the gate clips, `yolov8n.pt` for vehicle occupancy, the uploaded bay model at `computer_vision/models/sectional_occupancy/weights/best.pt`, the chosen videos, and their calibrated region JSON. The camera event token comes from `PARKING_EXIT_EVENT_TOKEN` or `backend/database/exit_camera_token`; if it is missing, create it with `php backend/database/create_exit_camera_token.php`.

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

1. Analyzes the opening sectional frames and prints every bay with a reliable occupied/vacant observation, plus the number of active bays still unknown. The snapshot does not filter or validate a requested bay. It pauses before entry.
   Each accepted sectional observation updates `parking_slots.is_occupied` and the zone occupied count in the main database. These observations and slot changes persist after the run. A vacant camera observation cannot free a bay with an open session; the output reports that conflict. Bays outside the calibrated or confidently model-located view remain unverified. The snapshot-only command is `python computer_vision/recorded_test_cases/sync_snapshots.py`.
2. Reads the entrance plate, then evaluates the requested bay against the completed baseline and the main database. It pauses before parking replay.
3. Continues the sectional clip after the baseline segment and stops once the assigned bay has been continuously occupied for 10 video seconds. If that does not happen before the clip ends, parking is not confirmed and the run stops. It then pauses for payment/validation before exit.
4. Reads the exit plate and requests clearance. If denied, it offers a retry after staff/cashier action.

With a desktop display, the pre-entry baseline and parking replay each open a **Sectional Occupancy** video window, with calibrated bay outlines, observed occupied/vacant labels, sampled vehicle boxes, and the clip time. The entrance and exit clips keep their own preview windows. Press `q` in the sectional window to stop that stage and the run. Use `--no-preview` to run without any video windows; JSONL events and evidence images are still saved.

`--preferred-slot E-05` explicitly requests a bay during allocation. Without it, `--clip-slot` is used as the preferred bay. Pass `--clip-slot ""` and omit `--preferred-slot` to allocate the first eligible observed vacant bay. The recorded zone profiles now use their dedicated `ZONE <letter> SNAPSHOT.mp4` clip for the opening three seconds, sampled every 0.5 video seconds. The parking clip starts separately from its first frame. `--baseline-seconds` and `--baseline-sample-seconds` can be changed for another pre-entry view. `--parked-seconds` controls the 10-second hold; `--auto-advance` skips the first two stage prompts.

## Five recorded clips

Pass three separate sectional clips alongside the entrance and exit clips. All three sectional options must be supplied together:

```sh
python computer_vision/full_simulation.py recorded \
  --scenario-id white_pickup_five_clips_01 \
  --baseline-video path/to/section_e_before_entry.mp4 \
  --entry-video path/to/entrance.mp4 \
  --parking-video path/to/section_e_parking.mp4 \
  --departure-video path/to/section_e_leaving_bay.mp4 \
  --exit-video path/to/exit_camera.mp4 \
  --plate LAC7863 --clip-plate LAC7863 --clip-slot E-05
```

The runner displays the opening `--baseline-seconds` of the first clip, reads the entrance plate, then plays the parking clip from its first frame until E-05 has been continuously occupied for 10 video seconds. The departure clip also starts from its first frame and must show E-05 occupied before it becomes vacant. Its first confirmed vacant observation ends that feed. The exit camera clip then confirms the same plate and requests payment clearance. Manual Enter controls separate the video stages; `--auto-advance` skips those stage prompts but the payment/validation pause remains. If parking or departure is not confirmed, the run stops with the session open for staff review or admin simulation recovery.

The three sectional clips must show the same physical bay from the configured `--camera-id` and `--regions` view. The supplied `--clip-plate` is a test label, not independent sectional plate OCR. Event indexes advance between clips so the later vacant observation replaces the earlier occupied observation; evidence retains each clip's source frame index. This five-clip mode currently uses recorded entrance and exit feeds. The existing `--sectional-video` command remains the simpler one-clip mode.

Use `http://127.0.0.1:8080/frontend/cashier.html` for payment or grace handling and `http://127.0.0.1:8080/frontend/admin.html` for enforcement review. The runner waits before exit. An unpaid exit remains denied until the session is cleared; it offers a retry.

The sample sectional recording ends with E-05 occupied; it does not show that vehicle leaving the bay. The exit camera can close the parking session, but the last physical observation will still say occupied until a later sectional feed shows vacancy. The `--clip-plate` label is a test binding, not plate OCR evidence.

## Vehicle color and QR fallback

The entrance camera uses a generic vehicle detector and a conservative paint-color estimate. The entry session records one simple label: White, Black, Gray, Red, Blue, Green, Yellow, Brown, Other, or Unknown. Color is camera-observed at entry and is **not** requested during vehicle registration. The issued entry QR remains an opaque token; its session record holds the color, which the cashier sees when looking up the token. For an active paid or admin-exempt reservation, the same reservation QR is used as the entry token after arrival.

When recorded entrance ALPR cannot read the plate, pass `--entry-qr-token` with that reservation's issued QR token. The backend resolves the plate from the active reservation and records any reliable entrance color. At exit, the camera also estimates color when it reads a plate. A strong, clear mismatch with the entrance color is held for staff QR review; uncertain colors do not block departure. If the exit plate is unreadable, the **issued entry QR is the only fallback credential**: look it up in Cashier POS, compare the displayed entrance color with the vehicle, and scan the QR for exit clearance. Color alone never authorizes exit. Sectional feeds store color with occupied-bay observations and send strong mismatches to staff review without an automatic fine. Colors are supporting evidence, not proof of identity; camera lighting, reflections, and partial views can produce Unknown.

## Live gates

Use the NVR address and account already configured for this project. The full connection and validation steps are in the [Live VIGI Setup Guide](LIVE_VIGI_SETUP.md). Set `VIGI_NVR_IP`, `VIGI_NVR_USERNAME`, `VIGI_NVR_PASSWORD`, and optionally `VIGI_NVR_RTSP_PORT` in the terminal environment. On macOS `zsh`, enter the password without echoing it:

```sh
export VIGI_NVR_IP="192.168.31.2"
export VIGI_NVR_USERNAME="admin"
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

The uploaded bay checkpoint locates slots; `yolov8n.pt` detects vehicles in each sampled frame. Three stable samples determine observed occupied/vacant state. During a recorded snapshot, stable, unambiguous model detections add same-zone bay regions to a run-scoped `observed_regions.json`; uncertain labels are rejected. Calibrated bay polygons remain the reference for bays that the model cannot locate. Any active bay absent from both sources stays **unknown** and is not available for simulation allocation. The recorded A–E profiles now configure the active bays visible in each snapshot (A: 9, B: 7, C: 6, D: 7, E: 10). The same vehicle detector evaluates each bay, and the preview reports occupied, vacant, and unknown totals. The B–E bay-ID mappings are camera-frame estimates and must be verified against site markings before operational use. Model validation mAP for bay labels is not an occupancy accuracy measure. Zone F has no sectional camera observation and uses its allocation count. Current inactive bay labels are ignored by the API.

Parking replay also runs the plate detector on vehicle crops inside observed bays, stores a plate only after repeated confident OCR agreement, and saves simple color observations separately. The clip's labeled plate is not a camera OCR reading. A plate too small or unclear in the sectional video remains unknown; color is supporting evidence only. The recorded grace case uses the actual entry timestamp with no presentation countdown. Normal fare and expired tenant cases adjust only their identified simulation session timestamps in the main database to test the appropriate boundary; those changes are audited.

Sectional snapshots and parking replay use `computer_vision/best.pt` for plate OCR by default; `--sectional-plate-model path/to/plate.pt` overrides those weights. OCR results are recorded only when the view supports a confident repeated read. A labeled clip match without OCR is `CLIP_BOUND`, while a confirmed OCR match is `MATCH`. Wrong-slot and wrong-plate findings go to staff review; the clip label itself is a test assertion.

The 20-second stationary-vehicle enforcement rule applies to calibrated driveway and double-parking regions in Zones A–E. Each zone keeps its normal bay polygons; roadway vehicles are evaluated separately so overlap in the camera view does not mark a bay occupied. Staff must review timed evidence before a ₱200 fine is issued; rejected alerts issue no fine. Only Zone C currently has a recorded violation clip for a full simulation run. The other zones' roadway polygons are conservative camera-frame estimates and need site review before unattended enforcement.

## Scenario checklist

Use a fresh scenario ID for each case and finish an active session before replaying the same plate: matching E-05 reservation, walk-in allocation, 15-minute grace, local payment, tenant validation and 20-minute exit window, unpaid exit, wrong slot, unrelated clip identity, QR fallback, overnight total fee, and reviewed restricted-area alert. A recorded enforcement demonstration needs at least 20 continuous seconds of labeled footage. After each run, check the session, assigned bay, observed bay, payment status, exit result, zone count, and any alert or fine.
