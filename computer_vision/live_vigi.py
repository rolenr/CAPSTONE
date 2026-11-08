"""Continuously review plate detections from two VIGI NVR channels.

Confirmed entrance and exit detections are sent to the local PHP API. Entry
creates or reuses a session; exit checks payment and closes eligible sessions.
Use --detection-only to print detections without sending camera events.

Set VIGI_NVR_IP, VIGI_NVR_USERNAME, and VIGI_NVR_PASSWORD in the environment.
Optionally set VIGI_NVR_RTSP_PORT (default 554). From the repository root:

    python3 computer_vision/live_vigi.py

Press q in either preview window, or Ctrl+C in the terminal, to stop. Use
--no-preview on a machine without a graphical desktop.
"""

import argparse
import json
import os
import queue
import re
import signal
import threading
import time
from collections import Counter, defaultdict, deque
from datetime import datetime
from datetime import timezone
from pathlib import Path
from statistics import median
from urllib.parse import quote
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

try:
    from .plate_utils import normalize_license_plate
except ImportError:
    from plate_utils import normalize_license_plate


SCRIPT_DIR = Path(__file__).resolve().parent
CAMERAS = (("entrance", 1, "VIGI Entrance - Channel 1"),
           ("exit", 13, "VIGI Exit - Channel 13"))
PLATE_PATTERN = re.compile(r"[A-Z]{3}\d{3,4}")
DISAPPEAR_SECONDS = 5.0
TRACK_EXPIRY_SECONDS = 10.0
MIN_PLATE_READS = 3
OCR_MIN_CONFIDENCE = 0.20
OCR_STRONG_CONFIDENCE = 0.65
OCR_TWO_READ_CONFIDENCE = 0.90
ENTRANCE_MOTION_FRACTION = 0.035
EXIT_MOTION_FRACTION = 0.08
PLATE_OBSERVATION_SECONDS = 6.0
LETTER_FIXES = str.maketrans({"0": "O", "1": "I", "2": "Z", "5": "S", "6": "G", "8": "B"})
DIGIT_FIXES = str.maketrans({"B": "8", "G": "6", "I": "1", "L": "1", "O": "0", "S": "5", "Z": "2"})


def rtsp_url(host, port, username, password, channel):
    """Build an NVR channel URL without writing its credentials to output."""
    return (f"rtsp://{quote(username, safe='')}:{quote(password, safe='')}"
            f"@{host}:{port}/live/{channel}/1/avm")


def configuration():
    host = os.environ.get("VIGI_NVR_IP", "").strip()
    username = os.environ.get("VIGI_NVR_USERNAME", "").strip()
    password = os.environ.get("VIGI_NVR_PASSWORD", "")
    port_text = os.environ.get("VIGI_NVR_RTSP_PORT", "554")
    if not host or not username or not password:
        raise ValueError(
            "Set VIGI_NVR_IP, VIGI_NVR_USERNAME, and VIGI_NVR_PASSWORD."
        )
    if any(char in host for char in "/:@?# "):
        raise ValueError("VIGI_NVR_IP must be a local IP address or hostname.")
    try:
        port = int(port_text)
    except ValueError as exc:
        raise ValueError("VIGI_NVR_RTSP_PORT must be a port number.") from exc
    if not 1 <= port <= 65535:
        raise ValueError("VIGI_NVR_RTSP_PORT must be between 1 and 65535.")
    return host, port, username, password


def normalize_ocr_plate(value):
    """Correct common letter/digit swaps only where the plate format requires it."""
    text = normalize_license_plate(value)
    if len(text) not in (6, 7):
        return None
    plate = text[:3].translate(LETTER_FIXES) + text[3:].translate(DIGIT_FIXES)
    return plate if PLATE_PATTERN.fullmatch(plate) else None


def best_ocr_candidate(reads):
    """Choose one plausible (confidence, plate), including split text boxes."""
    candidates = []
    pieces = []
    for box, text, probability in reads:
        probability = float(probability)
        if probability < OCR_MIN_CONFIDENCE:
            continue
        plate = normalize_ocr_plate(text)
        if plate:
            candidates.append((probability, plate))
        if box:
            center_x = sum(float(point[0]) for point in box) / len(box)
            pieces.append((center_x, text, probability))
    if len(pieces) > 1:
        pieces.sort(key=lambda piece: piece[0])
        combined = normalize_ocr_plate("".join(piece[1] for piece in pieces))
        if combined:
            candidates.append((min(piece[2] for piece in pieces), combined))
    return max(candidates, default=(0.0, None))


def best_ocr_plate(reads):
    """Return the plate selected from one OCR pass."""
    return best_ocr_candidate(reads)[1]


def is_approaching(history, role):
    """Use a short size trend; exit view has no reliable vertical direction."""
    if len(history["areas"]) < 4:
        return False
    areas = list(history["areas"])
    older_area = max(median(areas[:2]), 1e-9)
    recent_area = median(areas[-2:])
    if recent_area >= older_area * 1.15:
        return True
    if role == "entrance":
        centers = list(history["centers"])
        moved_down = median(centers[-2:]) - median(centers[:2]) >= 0.015
        return moved_down and recent_area >= older_area * 0.95
    return False


def has_entrance_motion(history):
    """Accept a plate traveling across the entrance without growing in view."""
    positions = history["positions"]
    if len(positions) < 4:
        return False
    start = history["origin"]
    start_x = median(point[0] for point in start)
    start_y = median(point[1] for point in start)
    end_x = median(point[0] for point in list(positions)[-2:])
    end_y = median(point[1] for point in list(positions)[-2:])
    return ((end_x - start_x) ** 2 + (end_y - start_y) ** 2) ** 0.5 >= ENTRANCE_MOTION_FRACTION


class PlateDetector:
    """Scenario 5's ALPR rules, with state scoped to one camera."""

    def __init__(self, role, model, reader, cv2, image_size=960):
        self.role = role
        self.model = model
        self.reader = reader
        self.cv2 = cv2
        self.image_size = image_size
        self.track_history = defaultdict(
            lambda: {
                "centers": deque(maxlen=8), "areas": deque(maxlen=8),
                "positions": deque(maxlen=8), "origin": deque(maxlen=2),
            }
        )
        self.plate_history = defaultdict(lambda: deque(maxlen=10))
        self.track_last_seen = {}
        self.eligible_tracks = set()
        self.confirmed_tracks = {}
        self.plate_last_seen = {}
        # ByteTrack can give a moving plate a new ID after a missed frame.
        # Keep a short plate-level history so those reads can still agree.
        self.plate_observations = defaultdict(lambda: deque(maxlen=10))

    def _moving_plate_confirmed(self, plate, position, now, confidence):
        observations = self.plate_observations[plate]
        observations.append((now, position, confidence))
        while observations and now - observations[0][0] > PLATE_OBSERVATION_SECONDS:
            observations.popleft()
        if len(observations) < 2:
            return False
        first = observations[0][1]
        movement = max(
            ((point[0] - first[0]) ** 2 + (point[1] - first[1]) ** 2) ** 0.5
            for _, point, _ in observations
        )
        minimum_motion = (ENTRANCE_MOTION_FRACTION if self.role == "entrance"
                          else EXIT_MOTION_FRACTION)
        if len(observations) >= MIN_PLATE_READS and movement >= minimum_motion:
            return True
        # Two particularly clear reads can suffice when the plate has moved
        # substantially and CPU inference skipped the remaining frames.
        strong = [entry for entry in observations if entry[2] >= OCR_TWO_READ_CONFIDENCE]
        if len(strong) < 2 or strong[-1][0] - strong[0][0] < 0.25:
            return False
        strong_first = strong[0][1]
        strong_motion = max(
            ((point[0] - strong_first[0]) ** 2 +
             (point[1] - strong_first[1]) ** 2) ** 0.5
            for _, point, _ in strong
        )
        return strong_motion >= max(minimum_motion, 0.08)

    def _read_plate(self, crop, padded_crop):
        """Return the best (OCR confidence, plate) from crop variants."""
        options = {
            "allowlist": "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
            "paragraph": False,
        }
        scale = min(4.0, max(1.0, 280.0 / crop.shape[1]))
        enlarged = self.cv2.resize(
            crop, None, fx=scale, fy=scale, interpolation=self.cv2.INTER_CUBIC
        ) if scale > 1.0 else crop
        best = best_ocr_candidate(self.reader.readtext(enlarged, **options))
        if best[0] >= OCR_STRONG_CONFIDENCE:
            return best

        # Night scenes can leave the characters dark beside bright headlights.
        # Work on the detected plate only; enhancing the whole frame would also
        # brighten glare and change the tracker's input.
        gray = self.cv2.cvtColor(enlarged, self.cv2.COLOR_BGR2GRAY)
        contrast = self.cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
        best = max(best, best_ocr_candidate(self.reader.readtext(contrast, **options)))
        if best[0] >= OCR_STRONG_CONFIDENCE:
            return best

        # Local thresholding can separate characters from a plate illuminated
        # unevenly by headlights or the camera's night light.
        binary = self.cv2.adaptiveThreshold(
            self.cv2.GaussianBlur(gray, (3, 3), 0), 255,
            self.cv2.ADAPTIVE_THRESH_GAUSSIAN_C, self.cv2.THRESH_BINARY, 31, 9,
        )
        best = max(best, best_ocr_candidate(self.reader.readtext(binary, **options)))
        if best[1] or padded_crop.size == 0:
            return best

        # A wider crop is useful only when the detector clipped a character.
        scale = min(4.0, max(1.0, 280.0 / padded_crop.shape[1]))
        enlarged = self.cv2.resize(
            padded_crop, None, fx=scale, fy=scale, interpolation=self.cv2.INTER_CUBIC
        ) if scale > 1.0 else padded_crop
        return best_ocr_candidate(self.reader.readtext(enlarged, **options))

    def process(self, frame, now=None):
        """Annotate a frame and return newly confirmed (role, plate) events."""
        now = time.monotonic() if now is None else now
        height, width = frame.shape[:2]
        if self.role == "entrance":
            xmin, xmax = int(width * 0.20), int(width * 0.95)
            ymin, ymax = int(height * 0.30), int(height * 0.95)
            confidence, image_size = 0.20, self.image_size
        else:
            # Include close plates near the bottom of the live exit view.
            xmin, xmax = int(width * 0.20), int(width * 0.95)
            ymin, ymax = int(height * 0.05), int(height * 0.95)
            confidence, image_size = 0.25, self.image_size

        # Draw on a separate image so the ROI and labels cannot affect OCR.
        annotated = frame.copy()
        results = self.model.track(
            frame, persist=True, tracker="bytetrack.yaml", conf=confidence,
            imgsz=image_size, verbose=False, device="cpu",
        )
        events = []
        for result in results:
            boxes = result.boxes
            if boxes.id is None:
                continue
            for box, raw_id in zip(boxes.xyxy.cpu().numpy(), boxes.id.cpu().numpy()):
                x1, y1, x2, y2 = map(int, box)
                track_id = int(raw_id)
                cx, cy = (x1 + x2) // 2, (y1 + y2) // 2
                area = max(0, x2 - x1) * max(0, y2 - y1)
                history = self.track_history[track_id]
                history["centers"].append(cy / height)
                history["areas"].append(area / (width * height))
                position = (cx / width, cy / height)
                history["positions"].append(position)
                if len(history["origin"]) < 2:
                    history["origin"].append(position)
                self.track_last_seen[track_id] = now
                if (is_approaching(history, self.role) or
                        (self.role == "entrance" and has_entrance_motion(history))):
                    self.eligible_tracks.add(track_id)
                eligible = track_id in self.eligible_tracks

                if track_id in self.confirmed_tracks:
                    plate = self.confirmed_tracks[track_id]
                    self.plate_last_seen[plate] = now
                    self._label(annotated, plate, x1, y1)
                    continue

                if not (xmin <= cx <= xmax and ymin <= cy <= ymax):
                    continue

                # A little margin preserves characters at the detector's edge.
                pad_x = max(2, int((x2 - x1) * 0.08))
                pad_y = max(2, int((y2 - y1) * 0.12))
                crop = frame[max(0, y1):min(height, y2), max(0, x1):min(width, x2)]
                padded_crop = frame[
                    max(0, y1 - pad_y):min(height, y2 + pad_y),
                    max(0, x1 - pad_x):min(width, x2 + pad_x),
                ]
                if crop.size:
                    ocr_confidence, plate = self._read_plate(crop, padded_crop)
                    if plate:
                        # One vote per processed frame, including when EasyOCR
                        # returns multiple text boxes or processing variants.
                        self.plate_history[track_id].append(plate)
                        if self._moving_plate_confirmed(plate, position, now, ocr_confidence):
                            self.confirmed_tracks[track_id] = plate
                            last_seen = self.plate_last_seen.get(plate)
                            self.plate_last_seen[plate] = now
                            self._label(annotated, plate, x1, y1)
                            if last_seen is None or now - last_seen >= DISAPPEAR_SECONDS:
                                events.append((self.role, plate))
                            continue
                if not eligible:
                    self.cv2.putText(
                        annotated, "checking movement", (max(0, x1), max(20, y1 - 8)),
                        self.cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 200, 255), 2,
                    )
                    continue
                votes = Counter(self.plate_history[track_id]).most_common(2)
                if not votes or votes[0][1] < MIN_PLATE_READS:
                    continue
                if len(votes) > 1 and votes[0][1] == votes[1][1]:
                    continue
                candidate = votes[0][0]
                self.confirmed_tracks[track_id] = candidate
                last_seen = self.plate_last_seen.get(candidate)
                self.plate_last_seen[candidate] = now
                self._label(annotated, candidate, x1, y1)
                if last_seen is None or now - last_seen >= DISAPPEAR_SECONDS:
                    events.append((self.role, candidate))

        self.cv2.rectangle(annotated, (xmin, ymin), (xmax, ymax), (0, 255, 0), 2)
        self._expire(now)
        return annotated, events

    def _label(self, frame, plate, x, y):
        self.cv2.putText(
            frame, plate, (max(0, x), max(20, y - 8)),
            self.cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 220, 0), 2,
        )

    def _expire(self, now):
        for track_id, seen_at in list(self.track_last_seen.items()):
            if now - seen_at > TRACK_EXPIRY_SECONDS:
                del self.track_last_seen[track_id]
                self.track_history.pop(track_id, None)
                self.plate_history.pop(track_id, None)
                self.eligible_tracks.discard(track_id)
                self.confirmed_tracks.pop(track_id, None)
        for plate, seen_at in list(self.plate_last_seen.items()):
            if now - seen_at > TRACK_EXPIRY_SECONDS:
                del self.plate_last_seen[plate]
        for plate, observations in list(self.plate_observations.items()):
            while observations and now - observations[0][0] > PLATE_OBSERVATION_SECONDS:
                observations.popleft()
            if not observations:
                del self.plate_observations[plate]


def capture_frames(cv2, url, role, frames, stop):
    """Keep the newest frame only, and reopen a channel after a disconnect."""
    delay = 1.0
    while not stop.is_set():
        capture = None
        try:
            try:
                capture = cv2.VideoCapture(
                    url, cv2.CAP_FFMPEG,
                    [cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, 5000,
                     cv2.CAP_PROP_READ_TIMEOUT_MSEC, 5000],
                )
            except (AttributeError, TypeError):
                # Older OpenCV builds lack the optional timeout parameters.
                capture = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
            if not capture.isOpened():
                print(f"[{role}] Stream unavailable; retrying.", flush=True)
            else:
                print(f"[{role}] Stream connected.", flush=True)
                delay = 1.0
                capture.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                while not stop.is_set():
                    ok, frame = capture.read()
                    if not ok:
                        print(f"[{role}] Stream interrupted; reconnecting.", flush=True)
                        break
                    try:
                        frames.put_nowait(frame)
                    except queue.Full:
                        try:
                            frames.get_nowait()
                        except queue.Empty:
                            pass
                        frames.put_nowait(frame)
        except Exception as exc:
            # OpenCV errors can include the RTSP URL, so do not print the
            # exception text or the URL (both may contain credentials).
            print(f"[{role}] Stream error ({type(exc).__name__}); retrying.", flush=True)
        finally:
            if capture is not None:
                capture.release()
        if not stop.is_set():
            stop.wait(delay)
            delay = min(delay * 2, 10.0)


def exit_event_configuration():
    token = os.environ.get("PARKING_EXIT_EVENT_TOKEN", "")
    if not token:
        token_file = SCRIPT_DIR.parent / "backend/database/exit_camera_token"
        if token_file.is_file():
            token = token_file.read_text().strip()
    if len(token) < 32:
        raise ValueError("Set PARKING_EXIT_EVENT_TOKEN or create backend/database/exit_camera_token (at least 32 characters).")
    url = os.environ.get(
        "PARKING_EXIT_EVENT_URL",
        "http://127.0.0.1:8080/backend/api/endpoints.php?action=exit_camera_event",
    )
    if not url.startswith(("http://127.0.0.1:", "http://localhost:", "https://")):
        raise ValueError("PARKING_EXIT_EVENT_URL must use local HTTP or HTTPS.")
    return token, url


def publish_camera_events(events, stop, token, url, role, scenario_id):
    """Send confirmed gate reads without blocking either vision worker."""
    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, *_args, **_kwargs):
            return None

    opener = build_opener(NoRedirect)
    while not stop.is_set() or not events.empty():
        try:
            plate, observed_at = events.get(timeout=0.2)
        except queue.Empty:
            continue
        payload = json.dumps({
            "channel": 1 if role == "entrance" else 13,
            "plate_number": plate, "observed_at": observed_at,
            "scenario_id": scenario_id,
            "preferred_slot": os.environ.get("PARKING_PREFERRED_SLOT", "") if role == "entrance" else "",
        }).encode("utf-8")
        request = Request(url, data=payload, headers={
            "Content-Type": "application/json", "X-Exit-Event-Token": token,
        }, method="POST")
        for attempt in range(2):
            try:
                with opener.open(request, timeout=4) as response:
                    result = json.load(response)
                if role == "entrance" and result.get("success"):
                    print(f"[entrance] Session {result.get('session_id')} assigned to {result.get('slot_number')} ({result.get('zone')}).", flush=True)
                elif result.get("departure_recorded"):
                    print(f"[exit] Session {result.get('session_id')} closed; slot {result.get('slot_number')} released.", flush=True)
                break
            except HTTPError as error:
                # A denied or unmatched event must not be retried as a new pass.
                print(f"[{role}] {plate}: API rejected detection (HTTP {error.code}).", flush=True)
                break
            except (URLError, TimeoutError, OSError, ValueError):
                if attempt == 0:
                    stop.wait(1)
                else:
                    print(f"[{role}] {plate}: API unavailable; use staff fallback if needed.", flush=True)
        events.task_done()


def recognize_frames(role, frames, detector, latest, lock, stop, event_queues=None):
    while not stop.is_set():
        try:
            frame = frames.get(timeout=0.2)
        except queue.Empty:
            continue
        try:
            annotated, events = detector.process(frame)
        except Exception as exc:
            print(f"[{role}] Recognition stopped: {type(exc).__name__}: {exc}", flush=True)
            stop.set()
            return
        with lock:
            latest[role] = annotated
        for event_role, plate in events:
            stamp = datetime.now().astimezone().isoformat(timespec="seconds")
            print(f"[{stamp}] {event_role.upper()} plate: {plate}", flush=True)
            queue_for_role = (event_queues or {}).get(event_role)
            if queue_for_role is not None:
                try:
                    queue_for_role.put_nowait((plate, datetime.now(timezone.utc).isoformat(timespec="seconds")))
                except queue.Full:
                    print(f"[{event_role}] Event queue full; use staff fallback if needed.", flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--no-preview", action="store_true",
                        help="Print detections without OpenCV video windows.")
    parser.add_argument("--detection-only", action="store_true",
                        help="Print exit detections without recording departures in the API.")
    args = parser.parse_args(argv)
    try:
        host, port, username, password = configuration()
    except ValueError as exc:
        parser.error(str(exc))
    try:
        exit_token, exit_url = (None, None) if args.detection_only else exit_event_configuration()
    except ValueError as exc:
        parser.error(str(exc))

    # Delayed imports keep --help usable before the vision packages are installed.
    import cv2
    import easyocr
    from ultralytics import YOLO

    stop = threading.Event()
    signal.signal(signal.SIGTERM, lambda _number, _frame: stop.set())
    latest, lock = {}, threading.Lock()
    workers = []
    scenario_id = os.environ.get("PARKING_SCENARIO_ID") or "live-" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    event_queues = ({role: queue.Queue(maxsize=64) for role in ("entrance", "exit")}
                    if exit_token else {})
    try:
        for role, channel, _title in CAMERAS:
            model_file = SCRIPT_DIR / (
                "entrance-feed-best1.pt" if role == "entrance" else "best.pt"
            )
            if not model_file.is_file():
                raise FileNotFoundError(f"Model weights missing: {model_file}")
            detector = PlateDetector(role, YOLO(str(model_file)), easyocr.Reader(["en"]), cv2)
            frames = queue.Queue(maxsize=1)
            url = rtsp_url(host, port, username, password, channel)
            workers.extend((
                threading.Thread(target=capture_frames,
                                 args=(cv2, url, role, frames, stop), daemon=True),
                threading.Thread(target=recognize_frames,
                                 args=(role, frames, detector, latest, lock, stop, event_queues), daemon=True),
            ))
        for role, events in event_queues.items():
            url = exit_url if role == "exit" else os.environ.get(
                "PARKING_ENTRY_EVENT_URL", exit_url.replace("exit_camera_event", "camera_entry_event"))
            if not url.startswith(("http://127.0.0.1:", "http://localhost:", "https://")):
                raise ValueError("PARKING_ENTRY_EVENT_URL must use local HTTP or HTTPS.")
            workers.append(threading.Thread(
                target=publish_camera_events,
                args=(events, stop, exit_token, url, role, scenario_id), daemon=True,
            ))
        for worker in workers:
            worker.start()
        stop_hint = "Ctrl+C" if args.no_preview else "q or Ctrl+C"
        print(f"Watching entrance channel 1 and exit channel 13 for scenario {scenario_id}. Press {stop_hint} to stop.")
        while not stop.is_set():
            if args.no_preview:
                stop.wait(0.2)
                continue
            with lock:
                previews = [(title, latest.get(role)) for role, _, title in CAMERAS]
            for title, frame in previews:
                if frame is not None:
                    cv2.imshow(title, frame)
            if cv2.waitKey(50) & 0xFF == ord("q"):
                stop.set()
    except KeyboardInterrupt:
        stop.set()
    finally:
        stop.set()
        for worker in workers:
            if worker.is_alive():
                worker.join(timeout=2)
        if not args.no_preview:
            cv2.destroyAllWindows()


if __name__ == "__main__":
    main()
