"""Draw sectional bay or restricted-area polygons for recorded test clips.

Click polygon vertices on the displayed reference frame, then press Enter to
save. Press r to redraw or q to exit without saving.
"""

import argparse
import json
from pathlib import Path

import cv2


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--camera-id", required=True)
    parser.add_argument("--output", type=Path, required=True)
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument("--slot", help="Active bay ID, such as A-06")
    target.add_argument("--restricted-area", choices=["driveway", "double_parking"])
    parser.add_argument("--name", help="Restricted-area label; defaults to the selected kind")
    parser.add_argument("--at-seconds", type=float, default=1.0)
    args = parser.parse_args()
    if not args.source.is_file() or args.at_seconds < 0:
        parser.error("source must exist and reference time must be nonnegative")

    capture = cv2.VideoCapture(str(args.source))
    capture.set(cv2.CAP_PROP_POS_MSEC, args.at_seconds * 1000)
    ok, frame = capture.read()
    capture.release()
    if not ok:
        parser.error("could not read a reference frame from the clip")
    source_height, source_width = frame.shape[:2]
    scale = min(1440 / source_width, 810 / source_height, 1.0)
    display = cv2.resize(frame, (round(source_width * scale), round(source_height * scale)))

    document = json.loads(args.output.read_text()) if args.output.exists() else {"version": 1, "cameras": {}}
    camera = document.setdefault("cameras", {}).setdefault(args.camera_id,
                                                            {"slots": {}, "no_parking_zones": {}})
    section = "slots" if args.slot else "no_parking_zones"
    label = args.slot or args.name or args.restricted_area
    if section == "no_parking_zones":
        prefix = "double" if args.restricted_area == "double_parking" else "driveway"
        label = f"{prefix}_{label.lower().replace(' ', '_')}"
    points = []
    title = f"{args.camera_id}: {label} | click polygon points, Enter save, r redraw, q cancel"

    def draw(_event, x, y, event, _flags, _param):
        if event == cv2.EVENT_LBUTTONDOWN:
            points.append((x, y))

    window = "Simulation region calibration"
    cv2.namedWindow(window, cv2.WINDOW_NORMAL)
    cv2.resizeWindow(window, 1440, 810)
    cv2.setMouseCallback(window, draw)
    try:
        while True:
            preview = display.copy()
            for index, point in enumerate(points):
                cv2.circle(preview, point, 7, (0, 255, 255), -1)
                cv2.putText(preview, str(index + 1), (point[0] + 8, point[1] - 8),
                            cv2.FONT_HERSHEY_SIMPLEX, .8, (0, 255, 255), 2)
            if len(points) > 1:
                for left, right in zip(points, points[1:] + points[:1]):
                    cv2.line(preview, left, right, (0, 255, 0), 2)
            cv2.imshow(window, preview)
            key = cv2.waitKey(25) & 0xFF
            if key == ord("r"):
                points.clear()
            elif key in (10, 13):
                if len(points) < 3:
                    print("Choose at least three vertices.")
                    continue
                height, width = display.shape[:2]
                polygon = [[round(x / width, 6), round(y / height, 6)] for x, y in points]
                camera.setdefault(section, {})[label] = polygon
                args.output.parent.mkdir(parents=True, exist_ok=True)
                args.output.write_text(json.dumps(document, indent=2) + "\n")
                print(f"Saved {label} to {args.output} under camera {args.camera_id}.")
                return 0
            elif key == ord("q") or key == 27:
                print("Calibration cancelled; no file changes saved.")
                return 1
    finally:
        cv2.destroyAllWindows()


if __name__ == "__main__":
    raise SystemExit(main())
