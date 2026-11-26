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
import getpass
import json
import os
import queue
import re
import signal
import threading
import time
from collections import Counter
from datetime import datetime
from datetime import timezone
from pathlib import Path
from urllib.parse import quote
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

try:
    from .plate_utils import normalize_license_plate
except ImportError:
    from plate_utils import normalize_license_plate
try:
    from .gate_passage import Crossing, GateGeometry, Passage, PlateCrop, associated_vehicle, center, match_passage
    from .vehicle_color import GateColorObserver
except ImportError:
    from gate_passage import Crossing, GateGeometry, Passage, PlateCrop, associated_vehicle, center, match_passage
    from vehicle_color import GateColorObserver
try:
    from . import overlay
except ImportError:
    import overlay


SCRIPT_DIR = Path(__file__).resolve().parent
CAMERAS = (("entrance", 1, "VIGI Entrance - Channel 1"),
           ("exit", 13, "VIGI Exit - Channel 13"))
PLATE_PATTERN = re.compile(r"[A-Z]{3}\d{3,4}")
DISAPPEAR_SECONDS = 5.0
TRACK_EXPIRY_SECONDS = 10.0
OCR_MIN_CONFIDENCE = 0.20
OCR_STRONG_CONFIDENCE = 0.65
LETTER_FIXES = str.maketrans({"0": "O", "1": "I", "5": "S", "6": "G", "8": "B"})
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
    candidates = [text]
    # EasyOCR sometimes absorbs a plate border/reflection as one edge symbol.
    # Permit a single edge trim only when it produces the exact local plate
    # format, rather than accepting arbitrary partial substrings.
    if len(text) == 8:
        candidates.extend((text[:-1], text[1:]))
    elif len(text) == 9:
        candidates.append(text[1:-1])
    for candidate in candidates:
        if len(candidate) not in (6, 7):
            continue
        # Edge-trimmed OCR must still contain three visible letters. A missing
        # leading letter plus an extra trailing border must not become a
        # plausible plate by converting the first numeric digit into Z.
        if candidate != text and not candidate[:3].isalpha():
            continue
        plate = candidate[:3].translate(LETTER_FIXES) + candidate[3:].translate(DIGIT_FIXES)
        if PLATE_PATTERN.fullmatch(plate):
            return plate
    return None


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


class PlateDetector:
    """Collect plate evidence per passage and confirm after a directed crossing."""

    def __init__(self, role, model, reader, cv2, image_size=960, vehicle_model=None):
        self.role = role
        self.model = model
        self.reader = reader
        self.cv2 = cv2
        self.image_size = image_size
        self.geometry = GateGeometry.load(role)
        self.color_observer = GateColorObserver(vehicle_model, cv2) if vehicle_model else None
        self.confirmed_colors = {}
        self.passages = []
        self.vehicle_crossings = {}
        self.plate_last_seen = {}
        self.metrics = Counter()

    def _read_plate(self, crop, padded_crop):
        """Read the whole plate from tight and context-padded crops."""
        options = {
            "allowlist": "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
            "paragraph": False,
        }
        if crop.size == 0:
            return 0.0, None

        candidates = []
        # Entrance views are often angled and the plate detector may clip the
        # first or last character. Read both the tight detection and its padded
        # context on every frame, so a plausible partial read cannot suppress
        # a more complete read from the same image.
        sources = [padded_crop, crop] if padded_crop.size else [crop]
        enlarged_sources = []
        for source in sources:
            scale = min(5.0, max(1.0, 420.0 / source.shape[1]))
            enlarged = self.cv2.resize(
                source, None, fx=scale, fy=scale,
                interpolation=self.cv2.INTER_CUBIC if scale > 1 else self.cv2.INTER_LINEAR,
            ) if scale > 1.0 else source
            enlarged_sources.append(enlarged)
            candidates.append(best_ocr_candidate(self.reader.readtext(enlarged, **options)))

        if len(candidates) == 1:
            tight = candidates[0]
            padded = (0.0, None)
        else:
            padded, tight = candidates[:2]
        if padded[1] and tight[1] == padded[1] and padded[0] >= OCR_STRONG_CONFIDENCE:
            return padded

        # Spend extra OCR work only when the base and padded crops disagree or
        # both fail. CLAHE and sharpening help glare, shadows, and motion blur.
        if not tight[1] or not padded[1] or tight[1] != padded[1]:
            source = enlarged_sources[-1]
            gray = (self.cv2.cvtColor(source, self.cv2.COLOR_BGR2GRAY)
                    if source.ndim == 3 else source)
            contrast = self.cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
            candidates.append(best_ocr_candidate(self.reader.readtext(contrast, **options)))
            blurred = self.cv2.GaussianBlur(contrast, (0, 0), 1.1)
            sharpened = self.cv2.addWeighted(contrast, 1.7, blurred, -0.7, 0)
            candidates.append(best_ocr_candidate(self.reader.readtext(sharpened, **options)))
            if not any(candidate[1] for candidate in candidates):
                binary = self.cv2.adaptiveThreshold(
                    self.cv2.GaussianBlur(contrast, (3, 3), 0), 255,
                    self.cv2.ADAPTIVE_THRESH_GAUSSIAN_C, self.cv2.THRESH_BINARY, 31, 9,
                )
                candidates.append(best_ocr_candidate(self.reader.readtext(binary, **options)))

        # Try a plate-shaped quadrilateral when the regular crops are weak.
        if max((score for score, plate in candidates if plate), default=0) < .80:
            rectified = self._rectify_plate(enlarged_sources[0])
            if rectified is not None:
                score, plate = best_ocr_candidate(self.reader.readtext(rectified, **options))
                if plate and score >= OCR_STRONG_CONFIDENCE:
                    candidates.append((score, plate))

        valid = [candidate for candidate in candidates if candidate[1]]
        if not valid:
            return max(candidates, default=(0.0, None))

        # When one read is exactly a prefix of another, keep the complete read
        # if its confidence is reasonably close. This helps with clipped final
        # digits while retaining six-character plates when no longer read exists.
        selected = max(valid, key=lambda item: item[0])
        for candidate in valid:
            if (candidate[1] != selected[1] and
                    candidate[1].startswith(selected[1]) and
                    len(candidate[1]) == len(selected[1]) + 1 and
                    candidate[0] >= selected[0] * 0.70):
                selected = candidate
        return selected

    def _rectify_plate(self, crop):
        """Warp a plausible four-corner plate outline to a rectangle."""
        import numpy as np

        cv2 = self.cv2
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if crop.ndim == 3 else crop
        _, bright = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)
        contours, _ = cv2.findContours(bright, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        height, width = gray.shape
        for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:3]:
            if not .24 * width * height <= cv2.contourArea(contour) <= .92 * width * height:
                continue
            polygon = cv2.approxPolyDP(contour, .035 * cv2.arcLength(contour, True), True)
            if len(polygon) != 4 or not cv2.isContourConvex(polygon):
                continue
            points = sorted(polygon.reshape(4, 2).tolist())
            tl, bl = sorted(points[:2], key=lambda point: point[1])
            tr, br = sorted(points[2:], key=lambda point: point[1])
            out_width = round(max(np.linalg.norm(np.subtract(tr, tl)), np.linalg.norm(np.subtract(br, bl))))
            out_height = round(max(np.linalg.norm(np.subtract(bl, tl)), np.linalg.norm(np.subtract(br, tr))))
            if out_height == 0 or not 1.6 <= out_width / out_height <= 6:
                continue
            source = np.float32([tl, tr, br, bl])
            target = np.float32([[0, 0], [out_width - 1, 0],
                                 [out_width - 1, out_height - 1], [0, out_height - 1]])
            return cv2.warpPerspective(crop, cv2.getPerspectiveTransform(source, target),
                                       (out_width, out_height))
        return None

    def _collect_crop(self, frame, pixels, confidence, now):
        height, width = frame.shape[:2]
        x1, y1, x2, y2 = pixels
        pad_x = max(3, int((x2 - x1) * .30))
        pad_y = max(3, int((y2 - y1) * (.38 if self.role == "entrance" else .30)))
        tight = frame[y1:y2, x1:x2]
        padded = frame[max(0, y1 - pad_y):min(height, y2 + pad_y),
                       max(0, x1 - pad_x):min(width, x2 + pad_x)]
        gray = self.cv2.cvtColor(tight, self.cv2.COLOR_BGR2GRAY)
        gray = self.cv2.resize(gray, (128, 48), interpolation=self.cv2.INTER_AREA)
        sharpness = self.cv2.Laplacian(gray, self.cv2.CV_64F).var()
        glare = (gray >= 245).mean()
        quality = confidence ** .5 * (x2 - x1) ** .5 * (1 + min(sharpness, 1200)) ** .25 * (1 - .7 * glare)
        return PlateCrop(now, quality, tight.copy(), padded.copy())

    def _read_pending(self, passage, now, crossed):
        passage.candidates[:] = [c for c in passage.candidates if now - c.timestamp <= 2]
        if not passage.candidates or passage.confirmed_plate():
            return
        if not crossed and now - passage.last_ocr < .24:
            return
        # At the line, use a second buffered frame if the first leaves the vote undecided.
        budget = 2 if crossed and passage.last_ocr < now - .18 else 1
        for _ in range(min(budget, len(passage.candidates))):
            crop = passage.candidates.pop(0)
            passage.last_ocr = now
            self.metrics["ocr_frames"] += 1
            confidence, plate = self._read_plate(crop.tight, crop.padded)
            if plate and confidence >= .55:
                passage.reads.append((crop.timestamp, plate, confidence))
                self.metrics["valid_reads"] += 1
                if passage.confirmed_plate():
                    break

    def process(self, frame, now=None):
        now = time.monotonic() if now is None else now
        self._expire(now)
        annotated = frame.copy()
        height, width = frame.shape[:2]
        vehicles = {}
        if self.color_observer:
            self.color_observer.observe(frame)
            vehicles = self.color_observer.tracked_boxes
            if self.role == "exit":
                minimum_bottom = min(point[1] for point in self.geometry.vehicle_line)
                vehicles = {key: box for key, box in vehicles.items() if box[3] >= minimum_bottom}
        for vehicle_id, box in vehicles.items():
            crossing, _ = self.vehicle_crossings.get(vehicle_id, (Crossing(), now))
            crossing.observe((center(box)[0], box[3]), self.geometry.vehicle_line)
            self.vehicle_crossings[vehicle_id] = crossing, now

        entrance = self.role == "entrance"
        detections = self.model.predict(
            frame, conf=.15 if entrance else .20,
            imgsz=max(self.image_size, 1152) if entrance else self.image_size,
            iou=.50, agnostic_nms=True, verbose=False, device="cpu",
        )[0].boxes
        used = set()
        for detection in sorted(detections, key=lambda box: float(box.conf[0]), reverse=True):
            x1, y1, x2, y2 = map(int, detection.xyxy[0].tolist())
            pixels = max(0, x1), max(0, y1), min(width, x2), min(height, y2)
            x1, y1, x2, y2 = pixels
            if x2 - x1 < max(24, .018 * width) or y2 - y1 < 9:
                continue
            box = x1 / width, y1 / height, x2 / width, y2 / height
            point = center(box)
            if not self.geometry.contains(point):
                continue
            self.metrics["plate_boxes"] += 1
            vehicle_ids = associated_vehicle(point, vehicles)
            if len(vehicle_ids) > 1:
                self.metrics["ambiguous_vehicle_matches"] += 1
                continue
            vehicle_id = vehicle_ids[0] if vehicle_ids else None
            # Accept at most one plate candidate per vehicle per frame.
            if vehicle_id is not None and any(self.passages[i].vehicle_id == vehicle_id for i in used):
                continue
            index = match_passage(box, vehicle_id, self.passages, used, vehicles, now)
            if index is None:
                index = len(self.passages)
                self.passages.append(Passage(now, box, vehicle_id))
                self.metrics["passages_started"] += 1
            passage = self.passages[index]
            used.add(index)
            passage.last_seen, passage.box = now, box
            if vehicle_id is not None:
                passage.vehicle_id = vehicle_id
                if not passage.plate:
                    self.color_observer.sample_vehicle(vehicle_id, vehicles[vehicle_id])
            passage.crossing.observe(point, self.geometry.plate_line)
            if passage.plate:
                self.plate_last_seen[passage.plate] = now
                self._label(annotated, passage.plate, x1, y1)
                continue
            passage.candidates[:] = [crop for crop in passage.candidates if now - crop.timestamp <= 2]
            candidate = self._collect_crop(frame, pixels, float(detection.conf[0]), now)
            passage.candidates.append(candidate)
            passage.candidates.sort(key=lambda item: item.quality, reverse=True)
            del passage.candidates[5:]

        events = []
        for index, passage in enumerate(self.passages):
            vehicle_crossing = self.vehicle_crossings.get(passage.vehicle_id)
            crossed = passage.crossing.crossed or (vehicle_crossing and vehicle_crossing[0].crossed)
            if crossed and not passage.crossed:
                passage.crossed = True
                self.metrics["crossings"] += 1
            if passage.plate or (index not in used and not crossed):
                continue
            self._read_pending(passage, now, crossed)
            plate = passage.confirmed_plate()
            if not plate or not crossed:
                continue
            passage.plate = plate
            passage.candidates.clear()
            x, y = passage.box[:2]
            self._label(annotated, plate, int(x * width), int(y * height))
            if now - self.plate_last_seen.get(plate, -DISAPPEAR_SECONDS) < DISAPPEAR_SECONDS:
                continue
            self.plate_last_seen[plate] = now
            if self.color_observer:
                color = self.color_observer.for_track(passage.vehicle_id)
                if color[0] == "UNKNOWN":
                    color = self.color_observer.for_single_vehicle()
                self.confirmed_colors[plate] = color
            self.metrics["confirmed"] += 1
            events.append((self.role, plate))

        x1, y1, x2, y2 = self.geometry.zone
        self.cv2.rectangle(annotated, (int(x1 * width), int(y1 * height)),
                           (int(x2 * width), int(y2 * height)), (0, 255, 0), 2)
        for line in (self.geometry.vehicle_line, self.geometry.plate_line):
            start, end = ((int(x * width), int(y * height)) for x, y in line)
            self.cv2.line(annotated, start, end, (50, 190, 255), 2)
        if self.color_observer:
            color, _score = self.color_observer.for_single_vehicle()
            self.cv2.putText(annotated, f"Vehicle color: {color.title()}",
                             (10, 32), self.cv2.FONT_HERSHEY_SIMPLEX, .65, (255, 255, 255), 2)
        return annotated, events

    def _label(self, frame, plate, x, y):
        overlay.draw_label(self.cv2, frame, plate, x, y, overlay.VACANT, size=1.25)

    def _expire(self, now):
        self.vehicle_crossings = {key: state for key, state in self.vehicle_crossings.items()
                                  if now - state[1] <= TRACK_EXPIRY_SECONDS}
        if self.color_observer:
            for key in self.color_observer.track_votes.keys() - self.vehicle_crossings.keys():
                del self.color_observer.track_votes[key]
        self.passages = [p for p in self.passages if now - p.last_seen <= TRACK_EXPIRY_SECONDS
                         and (now - p.last_seen <= 2 or p.vehicle_id in self.vehicle_crossings)]
        for plate, seen_at in list(self.plate_last_seen.items()):
            if now - seen_at > DISAPPEAR_SECONDS:
                del self.plate_last_seen[plate]
                self.confirmed_colors.pop(plate, None)


def capture_frames(cv2, url, role, frames, stop):
    """Buffer a few timestamped frames and reopen a channel after disconnects."""
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
                    item = (time.monotonic(), frame)
                    try:
                        frames.put_nowait(item)
                    except queue.Full:
                        try:
                            frames.get_nowait()
                        except queue.Empty:
                            pass
                        frames.put_nowait(item)
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
            plate, observed_at, color, color_confidence, entry_qr = events.get(timeout=0.2)
        except queue.Empty:
            continue
        payload = json.dumps({
            "channel": 1 if role == "entrance" else 13,
            "plate_number": plate, "observed_at": observed_at,
            "qr_only": role == "entrance" and not plate,
            "scenario_id": scenario_id,
            "preferred_slot": os.environ.get("PARKING_PREFERRED_SLOT", "") if role == "entrance" else "",
            "vehicle_color": color, "color_confidence": color_confidence,
            "qr_token": entry_qr if role == "entrance" else "",
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
                    if not result.get("duplicate") and result.get("entry_token"):
                        try:
                            from .qr_ticket import print_entry_ticket
                        except ImportError:
                            from qr_ticket import print_entry_ticket
                        print_entry_ticket(result["entry_token"], int(result["session_id"]),
                                           result["slot_number"], result.get("vehicle_color", "UNKNOWN"),
                                           SCRIPT_DIR.parent / "backend/database/simulation_runs" / scenario_id)
                elif result.get("departure_recorded"):
                    print(f"[exit] Session {result.get('session_id')} closed; slot {result.get('slot_number')} released.", flush=True)
                break
            except HTTPError as error:
                # A denied or unmatched event must not be retried as a new pass.
                try:
                    reason = json.load(error).get("error", "Camera event rejected")
                except (ValueError, OSError):
                    reason = "Camera event rejected"
                print(f"[{role}] {plate or 'unreadable plate'}: {reason} (HTTP {error.code}).", flush=True)
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
            captured_at, frame = frames.get(timeout=0.2)
        except queue.Empty:
            continue
        if time.monotonic() - captured_at > 2:
            continue
        try:
            annotated, events = detector.process(frame, now=captured_at)
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
                    color, score = detector.confirmed_colors.get(plate, ("UNKNOWN", 0.0))
                    queue_for_role.put_nowait((plate, datetime.now(timezone.utc).isoformat(timespec="seconds"),
                                               color, score, ""))
                except queue.Full:
                    print(f"[{event_role}] Event queue full; use staff fallback if needed.", flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--no-preview", action="store_true",
                        help="Print detections without OpenCV video windows.")
    parser.add_argument("--detection-only", action="store_true",
                        help="Print exit detections without recording departures in the API.")
    parser.add_argument("--vehicle-model", default=str(SCRIPT_DIR.parent / "yolov8n.pt"),
                        help="Generic vehicle detector used for paint-color observations")
    args = parser.parse_args(argv)
    if not Path(args.vehicle_model).is_file():
        parser.error(f"vehicle model is missing: {args.vehicle_model}")
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
    detectors = {}
    scenario_id = os.environ.get("PARKING_SCENARIO_ID") or "live-" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    event_queues = ({role: queue.Queue(maxsize=64) for role in ("entrance", "exit")}
                    if exit_token else {})
    try:
        for role, channel, _title in CAMERAS:
            model_file = SCRIPT_DIR / (
                "best.pt"
            )
            if not model_file.is_file():
                raise FileNotFoundError(f"Model weights missing: {model_file}")
            detector = PlateDetector(role, YOLO(str(model_file)), easyocr.Reader(["en"]), cv2,
                                     vehicle_model=YOLO(args.vehicle_model))
            detectors[role] = detector
            frames = queue.Queue(maxsize=4)
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
        print(f"Watching entrance channel 1 and exit channel 13 for scenario {scenario_id}. "
              f"Press {stop_hint} to stop; press u for an unreadable-plate walk-in QR "
              "or r to scan an active reservation QR when one vehicle is visible.")
        while not stop.is_set():
            if args.no_preview:
                stop.wait(0.2)
                continue
            with lock:
                previews = [(title, latest.get(role)) for role, _, title in CAMERAS]
            for title, frame in previews:
                if frame is not None:
                    cv2.imshow(title, frame)
            key = cv2.waitKey(50) & 0xFF
            if key == ord("u") and "entrance" in event_queues:
                observer = detectors["entrance"].color_observer
                if observer and len(observer.boxes) == 1:
                    color, score = observer.for_single_vehicle()
                    try:
                        event_queues["entrance"].put_nowait(("", datetime.now(timezone.utc).isoformat(timespec="seconds"),
                                                             color, score, ""))
                        print(f"[entrance] Unreadable plate; requesting QR-only session. Color: {color}.", flush=True)
                    except queue.Full:
                        print("[entrance] QR event queue is full.", flush=True)
                else:
                    print("[entrance] Show exactly one vehicle before issuing an unreadable-plate QR.", flush=True)
            if key == ord("r") and "entrance" in event_queues:
                observer = detectors["entrance"].color_observer
                if observer and len(observer.boxes) == 1:
                    reservation_qr = getpass.getpass("Scan the active reservation QR token: ").strip()
                    if reservation_qr:
                        color, score = observer.for_single_vehicle()
                        try:
                            event_queues["entrance"].put_nowait(("", datetime.now(timezone.utc).isoformat(timespec="seconds"),
                                                                 color, score, reservation_qr))
                            print(f"[entrance] Reservation QR submitted for the visible vehicle. Color: {color}.", flush=True)
                        except queue.Full:
                            print("[entrance] Reservation event queue is full.", flush=True)
                else:
                    print("[entrance] Show exactly one vehicle before scanning a reservation QR.", flush=True)
            if key == ord("q"):
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
