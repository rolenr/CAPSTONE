"""Conservative, camera-observed vehicle paint colors for session cross-checks.

Color is supporting evidence. A low-confidence view is UNKNOWN, never a
registration requirement or a substitute for a plate/issued QR token.
"""

from collections import Counter, defaultdict, deque

COLOR_LABELS = {"WHITE", "BLACK", "GRAY", "RED", "BLUE", "GREEN",
                "YELLOW", "BROWN", "OTHER", "UNKNOWN"}
VEHICLE_NAMES = {"car", "truck", "bus", "motorcycle"}


def classify_vehicle_color(frame, box, cv2):
    """Return (simple color, confidence) for a normalized vehicle box."""
    height, width = frame.shape[:2]
    x1, y1, x2, y2 = box
    left = max(0, int((x1 + .12 * (x2 - x1)) * width))
    right = min(width, int((x2 - .12 * (x2 - x1)) * width))
    top = max(0, int((y1 + .18 * (y2 - y1)) * height))
    bottom = min(height, int((y1 + .52 * (y2 - y1)) * height))
    crop = frame[top:bottom, left:right]
    if crop.size == 0 or crop.shape[0] < 12 or crop.shape[1] < 12:
        return "UNKNOWN", 0.0
    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    hsv = hsv[::3, ::3].reshape(-1, 3)
    counts = Counter()
    for hue, saturation, value in hsv:
        h, s, v = int(hue), int(saturation), int(value)
        if v < 35:
            continue  # deep shadow gives no useful paint evidence
        if s < 55:
            label = "WHITE" if v >= 185 else "BLACK" if v < 75 else "GRAY"
        elif h < 10 or h >= 170:
            label = "RED"
        elif h < 24:
            label = "BROWN" if v < 155 else "OTHER"
        elif h < 40:
            label = "YELLOW"
        elif h < 86:
            label = "GREEN"
        elif h < 136:
            label = "BLUE"
        else:
            label = "OTHER"
        counts[label] += 1
    total = sum(counts.values())
    if total < 80:
        return "UNKNOWN", 0.0
    # Windshield glare can make a gray vehicle's center look white. Require
    # bright neutral paint on both body edges before promoting it to WHITE.
    side_white = []
    side_gray = []
    for start, end in ((.03, .25), (.75, .97)):
        side = frame[max(0, int((y1 + .25 * (y2-y1)) * height)):
                     min(height, int((y1 + .70 * (y2-y1)) * height)),
                     max(0, int((x1 + start * (x2-x1)) * width)):
                     min(width, int((x1 + end * (x2-x1)) * width))]
        if side.size == 0:
            return "UNKNOWN", 0.0
        sample = cv2.cvtColor(side, cv2.COLOR_BGR2HSV)[::3, ::3].reshape(-1, 3)
        side_white.append(sum(1 for _, s, v in sample if s < 55 and v >= 185) / len(sample))
        side_gray.append(sum(1 for _, s, v in sample if s < 55 and 75 <= v < 185) / len(sample))
    body_white = ((min(side_white) >= .12 and max(side_white) >= .20) or
                  (max(side_white) >= .35 and counts['WHITE'] >= .28 * total))
    # For overhead views the broad middle crop can be mostly windshield.
    # Confirm white paint on the roof and some white paint on the body.
    roof = frame[max(0,int((y1+.03*(y2-y1))*height)):
                 min(height,int((y1+.20*(y2-y1))*height)),
                 max(0,int((x1+.20*(x2-x1))*width)):
                 min(width,int((x1+.80*(x2-x1))*width))]
    if roof.size and roof.shape[0] >= 8 and roof.shape[1] >= 8:
        roof_hsv = cv2.cvtColor(roof,cv2.COLOR_BGR2HSV)[::3,::3].reshape(-1,3)
        roof_white = sum(1 for _,s,v in roof_hsv if s < 55 and v >= 185)
        if roof_white >= .5 * len(roof_hsv) and body_white and counts['WHITE'] >= .16 * total:
            return 'WHITE', round(min(1.0,roof_white / len(roof_hsv)),3)
    # Dark windows and shadows are common inside a vehicle box. When bright
    # neutral paint is present, compare it primarily with other neutral paint.
    chromatic = max((counts[name] for name in ('RED','BLUE','GREEN','YELLOW','BROWN')), default=0)
    if (body_white and counts['WHITE'] >= .17 * total
            and counts['WHITE'] >= .3 * counts['GRAY']
            and counts['WHITE'] >= .8 * chromatic):
        return 'WHITE', round(min(1.0, .55 + counts['WHITE'] / total), 3)
    if (counts['WHITE'] >= counts['GRAY'] and not body_white
            and sum(side_gray) / 2 >= .25):
        return 'GRAY', round(max(.55, sum(side_gray) / 2), 3)
    label, count = counts.most_common(1)[0]
    confidence = count / total
    # Overhead cameras often reflect a blue sky in a gray roof or windshield.
    # Prefer neutral paint visible on both sides of the vehicle in that case.
    if (label == 'BLUE' and min(side_gray) >= .25
            and sum(side_gray) / 2 >= .45):
        return 'GRAY', round(min(1.0, .55 + sum(side_gray) / 4), 3)
    if label in {'RED','BLUE','GREEN','YELLOW','BROWN'}:
        lower = frame[max(0, int((y1 + .45 * (y2-y1)) * height)):
                      min(height, int((y1 + .78 * (y2-y1)) * height)), left:right]
        if lower.size == 0:
            return 'UNKNOWN', 0.0
        lower_hsv = cv2.cvtColor(lower, cv2.COLOR_BGR2HSV)[::3, ::3].reshape(-1, 3)
        lower_colored = 0
        for hue, saturation, value in lower_hsv:
            h, s, v = int(hue), int(saturation), int(value)
            if s < 55 or v < 35:
                continue
            if ((label == 'RED' and (h < 10 or h >= 170)) or
                (label == 'BROWN' and 10 <= h < 24 and v < 155) or
                (label == 'YELLOW' and 24 <= h < 40) or
                (label == 'GREEN' and 40 <= h < 86) or
                (label == 'BLUE' and 86 <= h < 136)):
                lower_colored += 1
        if lower_colored / max(1, len(lower_hsv)) < .5:
            return 'UNKNOWN', round(confidence, 3)
    return (label, round(confidence, 3)) if confidence >= .55 else ("UNKNOWN", round(confidence, 3))


def consensus(votes, minimum=3):
    """Confirm a color from three agreeing frames; break ties by confidence."""
    valid = [(label, score) for label, score in votes if label != "UNKNOWN" and score >= .55]
    if len(valid) < minimum:
        return "UNKNOWN", 0.0
    counts = Counter(label for label, _ in valid)
    label, count = max(counts.items(), key=lambda item: (
        item[1],
        sum(score for name, score in valid if name == item[0]) / item[1],
    ))
    agreement = count / len(valid)
    if count < minimum or agreement < .5:
        return "UNKNOWN", 0.0
    score = sum(value for name, value in valid if name == label) / count
    return label, round(min(1.0, score * agreement), 3)


class GateColorObserver:
    """Match plate boxes to vehicle boxes and aggregate color by plate track."""

    def __init__(self, vehicle_model, cv2):
        self.model, self.cv2 = vehicle_model, cv2
        self.boxes = []
        self.frame = None
        self.track_votes = defaultdict(lambda: deque(maxlen=8))
        self.single_vehicle_votes = deque(maxlen=8)

    def observe(self, frame):
        self.frame = frame
        height, width = frame.shape[:2]
        result = self.model.predict(frame, conf=.4, imgsz=640, verbose=False, device="cpu")[0]
        self.boxes = []
        for item in result.boxes:
            if self.model.names[int(item.cls[0])] not in VEHICLE_NAMES:
                continue
            x1, y1, x2, y2 = (float(n) for n in item.xyxy[0].tolist())
            box = (x1 / width, y1 / height, x2 / width, y2 / height)
            if (x2 - x1) * (y2 - y1) >= .004 * width * height:
                self.boxes.append(box)
        ranked = sorted(self.boxes, key=lambda b: (b[2] - b[0]) * (b[3] - b[1]), reverse=True)
        if ranked:
            largest_area = (ranked[0][2] - ranked[0][0]) * (ranked[0][3] - ranked[0][1])
            next_area = ((ranked[1][2] - ranked[1][0]) * (ranked[1][3] - ranked[1][1])
                         if len(ranked) > 1 else 0)
            if next_area == 0 or largest_area >= 2 * next_area:
                self.single_vehicle_votes.append(classify_vehicle_color(frame, ranked[0], self.cv2))
            else:
                self.single_vehicle_votes.clear()
        else:
            self.single_vehicle_votes.clear()

    def sample_plate(self, track_id, plate_box):
        if self.frame is None:
            return
        height, width = self.frame.shape[:2]
        x1, y1, x2, y2 = plate_box
        center = ((x1 + x2) / (2 * width), (y1 + y2) / (2 * height))
        matches = [box for box in self.boxes if box[0] <= center[0] <= box[2]
                   and box[1] <= center[1] <= box[3]]
        if not matches:
            return
        box = min(matches, key=lambda b: (b[2] - b[0]) * (b[3] - b[1]))
        self.track_votes[track_id].append(classify_vehicle_color(self.frame, box, self.cv2))

    def for_track(self, track_id):
        return consensus(self.track_votes[track_id])

    def for_single_vehicle(self):
        return consensus(self.single_vehicle_votes)
