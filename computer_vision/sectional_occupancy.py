"""Sectional bay occupancy and timed no-parking monitoring.

The bundled checkpoint recognizes 40 bay IDs, not generic vehicles or empty
spaces. Configure normalized polygons for each visible slot and no-parking area.
A separate COCO-style vehicle model is required for vacancy and enforcement.
This program emits JSON Lines and optional evidence images; it never changes
parking sessions, slot counts, or fines without a verified vehicle identity.
"""

import argparse
import json
import math
import sys
import time
import uuid
from collections import defaultdict, deque
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BAY_WEIGHTS = ROOT / "content/runs/detect/train/weights/best.pt"
VEHICLE_NAMES = {"car", "truck", "bus", "motorcycle"}
GRACE_SECONDS = 600.0
MISSING_SECONDS = 3.0
MIN_STABLE_SAMPLES = 3


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
        # The trained bay-ID classifier can confuse sections in an unfamiliar
        # recording. With calibrated slot polygons, geometry determines the
        # slot; any bay-model box inside it is positive vehicle evidence.
        by_slot = defaultdict(list)
        for _predicted_name, conf, box in bay_hits:
            for name, polygon in self.slots.items():
                if overlaps_polygon(box, polygon):
                    by_slot[name].append(conf)
        for name, polygon in self.slots.items():
            vehicle_conf = [conf for _, conf, box in (vehicles or [])
                            if overlaps_polygon(box, polygon)]
            # A bay-ID detection is positive evidence; lack of detection alone
            # is not evidence of an empty parking space.
            positive = by_slot[name] + vehicle_conf
            occupied = bool(positive)
            self.slot_samples[name].append(occupied)
            samples = self.slot_samples[name]
            if len(samples) < MIN_STABLE_SAMPLES or len(set(samples)) != 1:
                continue
            state = "OCCUPIED" if occupied else "VACANT"
            if not occupied and vehicles is None:
                continue
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
                "method": "bay_or_vehicle_detection" if occupied else "no_vehicle_in_calibrated_slot",
            })
        if vehicles is not None:
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
                                   "center": (x, y), "alerted": False}
                track = tracks[key]
                used.add(key)
                track["last"], track["center"] = when, (x, y)
                elapsed = when - track["first"]
                if elapsed > self.grace_seconds and not track["alerted"]:
                    track["alerted"] = True
                    events.append({
                        "event_id": str(uuid.uuid4()),
                        "event_type": "parking.no_parking.overstay",
                        "timestamp": utc_now(),
                        "source": {"video": self.source, "frame_index": frame_index},
                        "region": region, "local_track_id": key,
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


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", help="Recorded video or image path")
    parser.add_argument("--camera-id", default="section_a")
    parser.add_argument("--config", default="computer_vision/sectional_regions.json")
    parser.add_argument("--vehicle-model", help="Local generic vehicle YOLO weights; required for no-parking enforcement and reliable vacancy")
    parser.add_argument("--output", default="sectional_events.jsonl")
    parser.add_argument("--evidence-dir", default="sectional_evidence")
    parser.add_argument("--sample-seconds", type=float, default=1.0)
    parser.add_argument("--imgsz", type=int, default=960,
                        help="Detector input size; default 960 for wide sectional views")
    parser.add_argument("--grace-seconds", type=float, default=GRACE_SECONDS,
                        help="No-parking allowance; default 600 seconds")
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
    if args.sample_seconds <= 0 or args.grace_seconds <= 0 or args.imgsz <= 0:
        parser.error("--sample-seconds, --grace-seconds, and --imgsz must be positive")
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
    if zones and not args.vehicle_model:
        parser.error("--vehicle-model is required for no-parking enforcement")
    if args.vehicle_model and not Path(args.vehicle_model).is_file():
        parser.error("--vehicle-model must be an existing local weight file")
    vehicle_model = YOLO(args.vehicle_model) if args.vehicle_model else None
    if vehicle_model and not (VEHICLE_NAMES & set(vehicle_model.names.values())):
        parser.error("vehicle model has no car/truck/bus/motorcycle classes")
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
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    evidence_dir = Path(args.evidence_dir)
    frame_index = 0
    count = 0
    try:
        with output.open("w") as stream:
            while True:
                ok, frame = capture.read()
                if not ok:
                    break
                if frame_index % stride:
                    frame_index += 1
                    continue
                height, width = frame.shape[:2]
                bay_result = bay_model.predict(frame, conf=.5, imgsz=args.imgsz,
                                               verbose=False)[0]
                bay_hits = predictions(bay_result, width, height, bay_model.names)
                vehicles = None
                if vehicle_model:
                    vehicle_result = vehicle_model.predict(frame, conf=.35,
                                                           imgsz=args.imgsz, verbose=False)[0]
                    vehicles = predictions(vehicle_result, width, height,
                                           vehicle_model.names, VEHICLE_NAMES)
                when = time.monotonic() if live else frame_index / fps
                events = monitor.update(when, frame_index, bay_hits, vehicles)
                for event in events:
                    if event["event_type"] == "parking.no_parking.overstay":
                        evidence_dir.mkdir(parents=True, exist_ok=True)
                        evidence = evidence_dir / f"{args.camera_id}_{event['region']}_{frame_index}.jpg"
                        if not cv2.imwrite(str(evidence),
                                           annotate(frame.copy(), cv2, slots, zones, vehicles)):
                            raise OSError(f"could not write evidence image: {evidence}")
                        event["evidence"] = str(evidence)
                    stream.write(json.dumps(event) + "\n")
                    print(json.dumps(event), flush=True)
                    count += 1
                frame_index += 1
    finally:
        capture.release()
    print(f"Processed {frame_index} frames; emitted {count} events to {output}.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
