# Sectional Occupancy and No-Parking Monitoring

`computer_vision/sectional_occupancy.py` loads the saved sectional checkpoint at `content/runs/detect/train/weights/best.pt`. The checkpoint has **40 bay-ID classes in Zones A–E**, not 69 occupied/vacant classes. A sample sectional video and draft bay regions are included, but the repository has no calibrated roadway polygons or general vehicle weights. The script therefore needs camera-specific regions and a vehicle model before it can report vacancy or enforce the no-parking rule.

The proposal describes live overhead monitoring, reservation integrity, restricted driveways and fire lanes, and evidence-backed anomaly alerts (PDF pages 25–26 and 82). The **10-minute allowance in restricted areas is a new policy supplied for this implementation**. This script emits reviewable JSON Lines events and evidence images. It does not identify a vehicle's plate, charge a fine, or change the parking database.

## Prepare the environment

From the repository root, activate the computer-vision environment used by the gate scripts:

```sh
source .venv-cv/bin/activate
python -m pip install opencv-python ultralytics
python computer_vision/sectional_occupancy.py --help
```

No general vehicle checkpoint is bundled. Obtain a COCO-style YOLO model with `car`, `truck`, `bus`, and/or `motorcycle` classes and pass its **local file path** with `--vehicle-model`. For example, on a machine where Ultralytics can download pretrained weights:

```sh
python -c 'from ultralytics import YOLO; YOLO("yolov8n.pt")'
```

Check that `yolov8n.pt` now exists in the working directory before using it below. The sectional model alone cannot see cars stopped in an unmarked roadway.

## Calibrate one sectional camera

First, save a reference image from a recorded clip or RTSP feed:

```sh
python computer_vision/sectional_occupancy.py \
  --source "path/to/section-a.mp4" \
  --save-reference-frame "section-a-reference.jpg"
```

Generate a region template from the checkpoint's bay names:

```sh
python computer_vision/sectional_occupancy.py \
  --write-config-template "computer_vision/sectional_regions.json"
```

Edit `computer_vision/sectional_regions.json`. Replace each empty `[]` with the corners of that bay or restricted area, in image order. Coordinates are normalized: divide pixel `x` by image width and pixel `y` by image height. For a 1920×1080 image, pixel `(960, 540)` becomes `[0.5, 0.5]`. Example shape only; **the coordinates below are illustrative and must be measured on your own camera**:

```json
{
  "version": 1,
  "cameras": {
    "section_a": {
      "slots": {
        "A-01": [[0.10, 0.30], [0.22, 0.30], [0.23, 0.62], [0.09, 0.62]]
      },
      "no_parking_zones": {
        "roadway_1": [[0.30, 0.60], [0.90, 0.60], [0.90, 0.90], [0.30, 0.90]]
      }
    }
  }
}
```

Give every monitored camera its own ID, slot polygons, and explicitly mapped `no_parking_zones`. Do not treat every pixel outside a bay as a violation: pedestrian paths, inaccessible areas, and unmonitored portions need separate decisions. A moving or repositioned camera requires new calibration. Slots must use IDs present in the checkpoint. Empty polygons are rejected during inference.

## Run on a recording

```sh
python computer_vision/sectional_occupancy.py \
  --source "path/to/section-a.mp4" \
  --camera-id section_a \
  --config "computer_vision/sectional_regions.json" \
  --vehicle-model "yolov8n.pt" \
  --output "sectional_events.jsonl" \
  --evidence-dir "sectional_evidence"
```

To reproduce the Zone D sample check from the repository root:

```sh
python computer_vision/sectional_occupancy.py \
  --source computer_vision/simulation_videos/SectOcc_Sample1.mp4 \
  --camera-id section_d_sample1 \
  --config computer_vision/sectocc_sample1_regions.json \
  --output sectional_sample1_events.jsonl \
  --sample-seconds 1 \
  --imgsz 960
```

The sample is about 72 seconds long at 3840×2160. With the bundled bay checkpoint and no generic vehicle model, the tested run emitted four `OCCUPIED` observations: D-04 near 2 seconds, D-01 near 23 seconds, D-05 near 62 seconds, and D-09 near 67 seconds. D-05 corresponds to the white pickup entering a bay. The detections are **not an accurate occupancy count**: several visible parked vehicles were missed, and the D-01 observation needs visual review. At `--imgsz 1280`, the run instead observed D-10, D-09, D-01, and D-04, but missed the pickup's D-05 arrival. Higher resolution alone does not solve this checkpoint's misses. The draft polygons need on-site calibration before operational use.

The sample configuration intentionally has no `no_parking_zones`. This short clip cannot test the 600-second restricted-area timer, and no generic vehicle model is bundled for roadway detection. Add verified roadway polygons and local generic vehicle weights before testing enforcement. A longer annotated recording is needed to measure false alerts, missed bays, vacancy transitions, and ten-minute stays.

The default sampling interval is one second and the no-parking allowance is **600 seconds**. The timer starts with the first observed vehicle in the region and raises one review alert only after more than 600 seconds of continuously tracked presence. A vehicle missing from the region for more than three seconds ends that stay. Use `--sample-seconds` to change sampling. `--grace-seconds` exists for controlled tests; leave it at 600 for the stated policy. A recording shorter than ten minutes cannot demonstrate an overstay.

For an RTSP source, use `--source 'rtsp://...'` with the same options. Its timer uses wall-clock time. The event source is recorded as `camera:section_a` so RTSP credentials are not written to the event log. Stop the process with `Ctrl+C`.

## Expected output

After three stable sampled observations, a slot state change appears as JSON, for example:

```json
{"event_type":"sectional.slot.observed","slot_number":"A-01","occupancy_state":"OCCUPIED","confidence":0.91,"method":"bay_or_vehicle_detection"}
```

A vacant observation has `"confidence": null`: no detector can assign a calibrated confidence to the absence of a vehicle. The actual line also contains an event UUID, timestamp, and source video/frame. The script does not emit a vacant state from missing bay-ID detections alone; it needs the generic vehicle model and a calibrated bay polygon.

After a vehicle remains in a configured restricted region beyond ten minutes, the output includes an alert resembling:

```json
{"event_type":"parking.no_parking.overstay","region":"roadway_1","duration_seconds":601.0,"review_required":true,"evidence":"sectional_evidence/section_a_roadway_1_601.jpg"}
```

The actual line also includes an event UUID, timestamp, source, local track ID, and detector confidence. The evidence image marks the vehicle box, slot boundaries, and restricted region. A local track ID is **not** a license plate or parking session ID. A staff member must review the evidence and identify the vehicle before a fine can be associated with an account.

## Current coverage and limits

- The checkpoint names 40 bays across A–E; the database contains 69 active slots. Some checkpoint labels correspond to retired bays after the map-v2 inventory update. The sample Zone D regions still reflect the older ten-bay recording and must not be used to update live slot inventory. Unmodeled slots, including Zone F, need separate mapping or a new model.
- The saved checkpoint detects bay IDs. Vacancy uses calibrated polygons plus a separate vehicle detector, so camera calibration and validation footage are required before operational use.
- This program does not cross-check reserved slots or assigned plates because the sectional view does not recognize plates. The proposal's reservation mismatch flow needs a reliable identity link before it can generate a specific accusation.
- JSON Lines output is not yet connected to the application's event ingestion API or dashboard. It is a local detection and review feed, not an automatic database update.
