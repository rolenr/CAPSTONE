"""Sectional slot localization, vehicle occupancy, and timed enforcement.

The bundled checkpoint recognizes 40 bay IDs, not generic vehicles or empty
spaces. Configure normalized polygons for each visible slot and no-parking area.
A separate COCO-style vehicle model is required for vacancy and enforcement.
The bay checkpoint locates slots; a separate vehicle model decides occupancy.
Events may be published to the parking API for review and display.
"""

import argparse
import json
import math
import sys
import time
import uuid
import os
from urllib.request import Request, urlopen
from collections import defaultdict, deque
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BAY_WEIGHTS = ROOT / "content/runs/detect/train/weights/best.pt"
VEHICLE_NAMES = {"car", "truck", "bus", "motorcycle"}
GRACE_SECONDS = 300.0
MISSING_SECONDS = 3.0
MIN_STABLE_SAMPLES = 3
STATIONARY_FRACTION = 0.025


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def inside(point, polygon):
    """Ray casting for normalized image coordinates."""
    x, y = point
    hit = False
    j = len(polygon) - 1
    for i, (xi, yi) in enumerate(polygon):
        xj, yj = polygon[j]
        if (yi > y) != (yj > y):
            cut = (xj - xi) * (y - yi) / (yj - yi) + xi
            if x < cut:
                hit = not hit
        j = i
    return hit


def polygon_from_box(box):
    x1, y1, x2, y2 = box
    return [(x1, y1), (x2, y1), (x2, y2), (x1, y2)]


def overlaps_polygon(box, polygon):
    """Require the center or several footprint samples to fall in the region."""
    x1, y1, x2, y2 = box
    points = [((x1+x2)/2, (y1+y2)/2),
              ((x1+x2)/2, y1 + .75*(y2-y1)),
              (x1 + .25*(x2-x1), (y1+y2)/2),
              (x1 + .75*(x2-x1), (y1+y2)/2)]
    return sum(inside(p, polygon) for p in points) >= 2


def validate_polygon(value, label):
    if not isinstance(value, list) or len(value) < 3:
        raise ValueError(f"{label} needs at least three [x, y] points")
    for point in value:
        if (not isinstance(point, list) or len(point) != 2 or
                any(not isinstance(n, (float, int)) or isinstance(n, bool) or
                    not math.isfinite(n) or not 0 <= n <= 1 for n in point)):
            raise ValueError(f"{label} coordinates must be normalized numbers in [0, 1]")
    return value


def load_config(path, camera_id, bay_names):
    data = json.loads(Path(path).read_text())
    cameras = data.get("cameras")
    if not isinstance(cameras, dict) or camera_id not in cameras:
        raise ValueError(f"camera {camera_id!r} is missing from config cameras")
    camera = cameras[camera_id]
    slots, zones = camera.get("slots"), camera.get("no_parking_zones")
    if not isinstance(slots, dict) or not isinstance(zones, dict):
        raise ValueError("camera needs slots and no_parking_zones objects")
    unknown = set(slots) - set(bay_names)
    if unknown:
        raise ValueError(f"slot IDs absent from bay model: {sorted(unknown)}")
    for name, polygon in slots.items():
        validate_polygon(polygon, f"slot {name}")
    for name, polygon in zones.items():
        validate_polygon(polygon, f"no-parking zone {name}")
    if not slots and not zones:
        raise ValueError("configure at least one slot or no-parking polygon")
    return slots, zones


def make_template(path, names):
    payload = {"version": 1, "cameras": {"section_a": {
        "slots": {name: [] for name in sorted(names) if name.startswith("A-")},
        "no_parking_zones": {"roadway_1": []}}}}
    Path(path).write_text(json.dumps(payload, indent=2) + "\n")


def predictions(result, width, height, names, wanted=None):
    found = []
    for item in result.boxes:
        label = names[int(item.cls[0])]
        if wanted is not None and label not in wanted:
            continue
        x1, y1, x2, y2 = (float(n) for n in item.xyxy[0].tolist())
        found.append((label, float(item.conf[0]),
                      (x1/width, y1/height, x2/width, y2/height)))
    return found


class OccupancyMonitor:
    def __init__(self, slots, zones, source, grace_seconds=GRACE_SECONDS):
        self.slots, self.zones, self.source = slots, zones, source
        self.grace_seconds = grace_seconds
        self.slot_samples = defaultdict(lambda: deque(maxlen=MIN_STABLE_SAMPLES))
        self.slot_state = {}
        self.stays = {}  # region -> local track key -> first/last/center/alerted
        self.next_key = 1

    def update(self, when, frame_index, bay_hits, vehicles):
        """Return occupancy and enforcement events for one observed frame."""
        events = []
        # Bay detections locate spaces; they never prove that a car is parked.
        if vehicles is None:
            return events
        for name, polygon in self.slots.items():
            vehicle_conf = [conf for _, conf, box in vehicles
                            if overlaps_polygon(box, polygon)]
            positive = vehicle_conf
            occupied = bool(positive)
            self.slot_samples[name].append(occupied)
            samples = self.slot_samples[name]
            if len(samples) < MIN_STABLE_SAMPLES or len(set(samples)) != 1:
                continue
            state = "OCCUPIED" if occupied else "VACANT"
            if self.slot_state.get(name) == state:
                continue
            self.slot_state[name] = state
            events.append({
                "event_id": str(uuid.uuid4()),
                "event_type": "sectional.slot.observed",
                "timestamp": utc_now(),
                # No detector reports confidence for the absence of a vehicle.
                "confidence": max(positive) if positive else None,
                "source": {"video": self.source, "frame_index": frame_index},
                "slot_number": name, "occupancy_state": state,
                "method": "vehicle_in_calibrated_slot" if occupied else "no_vehicle_in_calibrated_slot",
            })
        events.extend(self._enforce(when, frame_index, vehicles))
        return events

    def _enforce(self, when, frame_index, vehicles):
        events = []
        for region, polygon in self.zones.items():
            current = [((box[0]+box[2])/2, (box[1]+box[3])/2, conf)
                       for _, conf, box in vehicles if overlaps_polygon(box, polygon)]
            tracks = self.stays.setdefault(region, {})
            used = set()
            for x, y, conf in current:
                # Associate across detector-ID changes; one vehicle can be
                # counted only once per frame. Large jumps start a new stay.
                candidates = [(math.dist((x, y), track["center"]), key)
                              for key, track in tracks.items()
                              if key not in used and when - track["last"] <= MISSING_SECONDS]
                distance, key = min(candidates, default=(float("inf"), None))
                if distance > .08:
                    key = self.next_key
                    self.next_key += 1
                    tracks[key] = {"first": when, "last": when,
                                   "center": (x, y), "anchor": (x, y), "alerted": False}
                track = tracks[key]
                used.add(key)
                if math.dist((x, y), track["anchor"]) > STATIONARY_FRACTION:
                    track["first"], track["anchor"], track["alerted"] = when, (x, y), False
                track["last"], track["center"] = when, (x, y)
                elapsed = when - track["first"]
                if elapsed >= self.grace_seconds and not track["alerted"]:
                    track["alerted"] = True
                    violation_type = "DOUBLE_PARKING" if region.lower().startswith("double") else "DRIVEWAY"
                    events.append({
                        "event_id": str(uuid.uuid4()),
                        "event_type": "parking.restricted.overstay",
                        "timestamp": utc_now(),
                        "source": {"video": self.source, "frame_index": frame_index},
                        "region": region, "track_id": str(key),
                        "violation_type": violation_type,
                        "duration_seconds": round(elapsed, 2),
                        "confidence": conf, "review_required": True,
                    })
            for key, track in list(tracks.items()):
                if key not in used and when - track["last"] > MISSING_SECONDS:
                    del tracks[key]
        return events


def annotate(frame, cv2, slots, zones, vehicles):
    height, width = frame.shape[:2]
    for name, polygon in slots.items():
        coords = [(int(x*width), int(y*height)) for x, y in polygon]
        for a, b in zip(coords, coords[1:] + coords[:1]):
            cv2.line(frame, a, b, (0, 180, 0), 2)
        cv2.putText(frame, name, coords[0], cv2.FONT_HERSHEY_SIMPLEX,
                    .5, (0, 255, 0), 1)
    for name, polygon in zones.items():
        coords = [(int(x*width), int(y*height)) for x, y in polygon]
        for a, b in zip(coords, coords[1:] + coords[:1]):
            cv2.line(frame, a, b, (0, 0, 255), 2)
        cv2.putText(frame, name, coords[0], cv2.FONT_HERSHEY_SIMPLEX,
                    .5, (0, 0, 255), 1)
    for label, confidence, box in vehicles:
        x1, y1, x2, y2 = box
        a, b = (int(x1*width), int(y1*height)), (int(x2*width), int(y2*height))
        cv2.rectangle(frame, a, b, (0, 165, 255), 2)
        cv2.putText(frame, f"{label} {confidence:.2f}", a,
                    cv2.FONT_HERSHEY_SIMPLEX, .5, (0, 165, 255), 1)
    return frame


def preview_frame(frame, cv2, slots, zones, vehicles, states, label, frame_index, fps):
    display = annotate(frame.copy(), cv2, slots, zones, vehicles)
    height, width = display.shape[:2]
    cv2.rectangle(display, (0, 0), (width, 76), (20, 20, 20), -1)
    cv2.putText(display, f"SECTIONAL  {label}  {frame_index / fps:.1f}s", (18, 30),
                cv2.FONT_HERSHEY_SIMPLEX, .7, (255, 255, 255), 2)
    occupied = sum(state == "OCCUPIED" for state in states.values())
    vacant = sum(state == "VACANT" for state in states.values())
    cv2.putText(display, f"Observed: {occupied} occupied | {vacant} vacant | q: stop", (18, 62),
                cv2.FONT_HERSHEY_SIMPLEX, .55, (255, 255, 255), 1)
    for name, polygon in slots.items():
        state = states.get(name)
        if not state:
            continue
        x, y = polygon[0]
        anchor = (int(x * width), min(height - 8, int(y * height) + 23))
        color = (0, 0, 255) if state == "OCCUPIED" else (0, 220, 0)
        cv2.putText(display, state, anchor, cv2.FONT_HERSHEY_SIMPLEX, .43, color, 2)
    return display


def publish_event(event, url, token):
    payload = json.dumps(event).encode("utf-8")
    request = Request(url, data=payload, method="POST", headers={
        "Content-Type": "application/json", "X-Exit-Event-Token": token,
    })
    with urlopen(request, timeout=8) as response:
        result = json.load(response)
    if not result.get("success"):
        raise RuntimeError(f"Sectional API rejected event: {result.get('error', 'unknown error')}")
    return result


def read_plates(frame, model, reader, cv2, slots):
    """Read visible plates inside calibrated bays; no motion gate is applied."""
    try:
        from .live_vigi import best_ocr_candidate
    except ImportError:
        from live_vigi import best_ocr_candidate
    height, width = frame.shape[:2]
    found = {}
    result = model.predict(frame, conf=.25, imgsz=1280, verbose=False)[0]
    for box in result.boxes:
        x1, y1, x2, y2 = map(int, box.xyxy[0].tolist())
        crop = frame[max(0, y1):min(height, y2), max(0, x1):min(width, x2)]
        if crop.size == 0:
            continue
        enlarged = cv2.resize(crop, None, fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
        confidence, plate = best_ocr_candidate(reader.readtext(
            enlarged, allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789", paragraph=False))
        if not plate:
            continue
        center = ((x1+x2)/(2*width), (y1+y2)/(2*height))
        for slot, polygon in slots.items():
            if inside(center, polygon) and confidence > found.get(slot, (0, ""))[0]:
                found[slot] = (confidence, plate)
    return found


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", help="Recorded video or image path")
    parser.add_argument("--camera-id", default="section_a")
    parser.add_argument("--config", default="computer_vision/sectional_regions.json")
    parser.add_argument("--vehicle-model", default="yolov8n.pt",
                        help="Local generic vehicle YOLO weights; required for occupied/vacant states")
    parser.add_argument("--plate-model", help="Optional local plate weights for sectional OCR")
    parser.add_argument("--scenario-id", default="standalone")
    parser.add_argument("--session-id", type=int)
    parser.add_argument("--clip-plate", default="", help="Labeled target vehicle plate in this recording")
    parser.add_argument("--clip-slot", default="", help="Labeled bay occupied by the target vehicle")
    parser.add_argument("--event-url", help="Authenticated parking API sectional_event endpoint")
    parser.add_argument("--event-token", help="Camera-event token; defaults to PARKING_EXIT_EVENT_TOKEN or token file")
    parser.add_argument("--output", default="sectional_events.jsonl")
    parser.add_argument("--evidence-dir", default="sectional_evidence")
    parser.add_argument("--sample-seconds", type=float, default=1.0)
    parser.add_argument("--max-seconds", type=float, help="Stop after this much source video for a pre-entry snapshot")
    parser.add_argument("--start-seconds", type=float, default=0.0,
                        help="Begin replay at this source-video time")
    parser.add_argument("--preview", action="store_true", help="Show the sectional camera feed with slot and vehicle overlays")
    parser.add_argument("--preview-label", default="REPLAY", help="Stage name shown in preview window")
    parser.add_argument("--target-slot", help="Assigned bay to confirm during parking replay")
    parser.add_argument("--stop-after-occupied-seconds", type=float,
                        help="Stop after this many continuous video seconds of vehicle occupancy in the target bay")
    parser.add_argument("--stop-on-vacant", action="store_true",
                        help="Stop after the target bay is observed occupied, then vacant")
    parser.add_argument("--frame-offset", type=int, default=0,
                        help="Offset published frame indexes for later clips in one scenario")
    parser.add_argument("--imgsz", type=int, default=960,
                        help="Detector input size; default 960 for wide sectional views")
    parser.add_argument("--grace-seconds", type=float, default=GRACE_SECONDS,
                        help="Driveway/double-parking allowance; default 300 seconds")
    parser.add_argument("--write-config-template", metavar="PATH")
    parser.add_argument("--save-reference-frame", metavar="PATH",
                        help="Save the first source frame for drawing calibrated polygons")
    args = parser.parse_args(argv)
    if not BAY_WEIGHTS.is_file():
        parser.error(f"missing sectional checkpoint: {BAY_WEIGHTS}")
    from ultralytics import YOLO
    bay_model = YOLO(str(BAY_WEIGHTS))
    bay_names = set(bay_model.names.values())
    if args.write_config_template:
        make_template(args.write_config_template, bay_names)
        print(f"Wrote {args.write_config_template}; draw normalized polygons before running inference.")
        return 0
    live = bool(args.source and args.source.startswith("rtsp://"))
    if not args.source or (not live and not Path(args.source).is_file()):
        parser.error("--source must point to an existing video/image or an RTSP URL")
    if args.sample_seconds <= 0 or args.grace_seconds <= 0 or args.imgsz <= 0 or args.start_seconds < 0 or (args.max_seconds is not None and args.max_seconds <= 0):
        parser.error("--sample-seconds, --grace-seconds, and --imgsz must be positive")
    if args.stop_after_occupied_seconds is not None and (not args.target_slot or args.stop_after_occupied_seconds <= 0):
        parser.error("--stop-after-occupied-seconds needs --target-slot and a positive duration")
    if args.stop_on_vacant and not args.target_slot:
        parser.error("--stop-on-vacant needs --target-slot")
    if args.stop_on_vacant and args.stop_after_occupied_seconds is not None:
        parser.error("use only one sectional stop condition")
    if args.frame_offset < 0:
        parser.error("--frame-offset cannot be negative")
    if args.save_reference_frame:
        import cv2
        capture = cv2.VideoCapture(args.source)
        ok, frame = capture.read()
        capture.release()
        if not ok or not cv2.imwrite(args.save_reference_frame, frame):
            parser.error("could not save a reference frame from this source")
        print(f"Saved reference frame to {args.save_reference_frame}")
        return 0
    try:
        slots, zones = load_config(args.config, args.camera_id, bay_names)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        parser.error(f"invalid config: {exc}")
    if args.target_slot and args.target_slot not in slots:
        parser.error(f"target bay {args.target_slot} is absent from this camera's regions")
    if not args.vehicle_model or not Path(args.vehicle_model).is_file():
        parser.error("--vehicle-model must be an existing local weight file")
    vehicle_model = YOLO(args.vehicle_model)
    if not (VEHICLE_NAMES & set(vehicle_model.names.values())):
        parser.error("vehicle model has no car/truck/bus/motorcycle classes")
    if args.plate_model and not Path(args.plate_model).is_file():
        parser.error("--plate-model must be an existing local weight file")
    plate_model = YOLO(args.plate_model) if args.plate_model else None
    reader = None
    if plate_model:
        import easyocr
        reader = easyocr.Reader(["en"])
    event_token = args.event_token or os.environ.get("PARKING_EXIT_EVENT_TOKEN", "")
    if args.event_url and not event_token:
        token_path = ROOT / "backend/database/exit_camera_token"
        event_token = token_path.read_text().strip() if token_path.is_file() else ""
    if args.event_url and (len(event_token) < 32 or not args.event_url.startswith(("http://127.0.0.1:", "http://localhost:", "https://"))):
        parser.error("--event-url requires local HTTP or HTTPS and a camera token")
    import cv2
    capture = cv2.VideoCapture(args.source)
    if not capture.isOpened():
        parser.error(f"cannot open source: {args.source}")
    fps = capture.get(cv2.CAP_PROP_FPS)
    if not math.isfinite(fps) or fps <= 0:
        fps = 1.0
    stride = max(1, round(fps * args.sample_seconds))
    source_label = f"camera:{args.camera_id}" if live else args.source
    monitor = OccupancyMonitor(slots, zones, source_label, args.grace_seconds)
    plate_history = defaultdict(lambda: deque(maxlen=3))
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    evidence_dir = Path(args.evidence_dir)
    frame_index = round(args.start_seconds * fps)
    if frame_index:
        capture.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
    count = 0
    target_occupied_since = None
    parking_confirmed = False
    target_was_occupied = False
    departure_confirmed = False
    window = f"Sectional Occupancy - {args.camera_id}"
    if args.preview:
        cv2.namedWindow(window, cv2.WINDOW_NORMAL)
        cv2.resizeWindow(window, 1280, 720)
    try:
        with output.open("w") as stream:
            while True:
                if args.max_seconds is not None and frame_index / fps >= args.max_seconds:
                    break
                ok, frame = capture.read()
                if not ok:
                    break
                if frame_index % stride:
                    if args.preview:
                        cv2.imshow(window, preview_frame(frame, cv2, slots, zones, [],
                                                          monitor.slot_state, args.preview_label, frame_index, fps))
                        if cv2.waitKey(max(1, round(1000 / fps))) & 0xFF == ord("q"):
                            raise KeyboardInterrupt("Sectional preview stopped by operator")
                    frame_index += 1
                    continue
                height, width = frame.shape[:2]
                bay_result = bay_model.predict(frame, conf=.5, imgsz=args.imgsz,
                                               verbose=False)[0]
                bay_hits = predictions(bay_result, width, height, bay_model.names)
                vehicle_result = vehicle_model.predict(frame, conf=.35,
                                                       imgsz=args.imgsz, verbose=False)[0]
                vehicles = predictions(vehicle_result, width, height,
                                       vehicle_model.names, VEHICLE_NAMES)
                plate_reads = read_plates(frame, plate_model, reader, cv2, slots) if plate_model else {}
                for slot, (_confidence, plate) in plate_reads.items():
                    plate_history[slot].append(plate)
                when = time.monotonic() if live else frame_index / fps
                events = monitor.update(when, frame_index, bay_hits, vehicles)
                if args.stop_after_occupied_seconds is not None:
                    present = any(overlaps_polygon(box, slots[args.target_slot]) for _, _, box in vehicles)
                    if present and target_occupied_since is None:
                        target_occupied_since = when
                    elif not present:
                        target_occupied_since = None
                for event in events:
                    event.update({"scenario_id": args.scenario_id, "session_id": args.session_id,
                                  "camera_id": args.camera_id, "frame_index": frame_index + args.frame_offset,
                                  "clip_plate": args.clip_plate, "clip_slot": args.clip_slot})
                    if event["event_type"] == "sectional.slot.observed":
                        # The bay checkpoint reports localization quality only;
                        # vehicle detections alone determine occupied/vacant.
                        slot_hits = [confidence for name, confidence, box in bay_hits
                                     if name == event["slot_number"] and
                                     overlaps_polygon(box, slots[name])]
                        event["slot_localization_confidence"] = max(slot_hits) if slot_hits else None
                        votes = plate_history[event["slot_number"]]
                        if len(votes) >= 2 and votes.count(votes[-1]) >= 2:
                            event["recognized_plate"] = votes[-1]
                    if event["event_type"] == "parking.restricted.overstay" or event["occupancy_state"] == "OCCUPIED":
                        evidence_dir.mkdir(parents=True, exist_ok=True)
                        label = event.get("region", event.get("slot_number", "event"))
                        evidence = evidence_dir / f"{args.camera_id}_{label}_{frame_index}.jpg"
                        if not cv2.imwrite(str(evidence),
                                           annotate(frame.copy(), cv2, slots, zones, vehicles)):
                            raise OSError(f"could not write evidence image: {evidence}")
                        event["evidence"] = str(evidence)
                    stream.write(json.dumps(event) + "\n")
                    print(json.dumps(event), flush=True)
                    if args.event_url:
                        publish_event(event, args.event_url, event_token)
                    count += 1
                if args.stop_on_vacant:
                    state = monitor.slot_state.get(args.target_slot)
                    if state == "OCCUPIED":
                        target_was_occupied = True
                    elif target_was_occupied and state == "VACANT":
                        departure_confirmed = True
                        print(f"Bay departure confirmed: {args.target_slot} became vacant "
                              f"at frame {frame_index}.", flush=True)
                if (args.stop_after_occupied_seconds is not None and target_occupied_since is not None
                        and monitor.slot_state.get(args.target_slot) == "OCCUPIED"
                        and when - target_occupied_since >= args.stop_after_occupied_seconds):
                    parking_confirmed = True
                    print(f"Parking confirmed: {args.target_slot} occupied continuously for "
                          f"{when - target_occupied_since:.1f} video seconds at frame {frame_index}.", flush=True)
                if args.preview:
                    cv2.imshow(window, preview_frame(frame, cv2, slots, zones, vehicles,
                                                      monitor.slot_state, args.preview_label, frame_index, fps))
                    if cv2.waitKey(1) & 0xFF == ord("q"):
                        raise KeyboardInterrupt("Sectional preview stopped by operator")
                frame_index += 1
                if parking_confirmed or departure_confirmed:
                    break
    finally:
        capture.release()
        if args.preview:
            cv2.destroyWindow(window)
    print(f"Processed {frame_index} frames; emitted {count} events to {output}.", file=sys.stderr)
    if args.stop_after_occupied_seconds is not None and not parking_confirmed:
        print(f"Parking was not confirmed in {args.target_slot} before the clip ended.", file=sys.stderr)
        return 3
    if args.stop_on_vacant and not departure_confirmed:
        print(f"Bay departure was not confirmed in {args.target_slot} before the clip ended.", file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("Sectional preview stopped by operator.", file=sys.stderr)
        raise SystemExit(130)
