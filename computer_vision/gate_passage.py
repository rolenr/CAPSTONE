"""Passage geometry and per-vehicle plate evidence for gate cameras."""

import json
import math
import os
from collections import Counter, deque
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional


DEFAULT_GEOMETRY = {
    "entrance": {
        "zone": [.03, .34, .98, .98],
        "vehicle_line": [[.05, .60], [.95, .60]],
        "plate_line": [[.05, .54], [.95, .54]],
    },
    "exit": {
        "zone": [.18, .015, .98, .60],
        "vehicle_line": [[.77, .235], [.77, .95]],
        "plate_line": [[.57, .015], [.57, .60]],
    },
}


@dataclass(frozen=True)
class GateGeometry:
    zone: tuple
    vehicle_line: tuple
    plate_line: tuple

    @classmethod
    def load(cls, role):
        path = os.environ.get("PARKING_GATE_REGIONS")
        values = json.loads(Path(path).read_text())[role] if path else DEFAULT_GEOMETRY[role]
        zone = tuple(values["zone"])
        if (len(zone) != 4 or not all(isinstance(v, (int, float)) and 0 <= v <= 1 for v in zone)
                or not (zone[0] < zone[2] and zone[1] < zone[3])):
            raise ValueError(f"{role} gate zone must be an ordered normalized rectangle")
        lines = []
        for name in ("vehicle_line", "plate_line"):
            line = tuple(tuple(point) for point in values[name])
            if (len(line) != 2 or any(len(p) != 2 for p in line) or line[0] == line[1]
                    or not all(isinstance(v, (int, float)) and 0 <= v <= 1 for p in line for v in p)):
                raise ValueError(f"{role} {name} must have two distinct normalized endpoints")
            lines.append(line)
        return cls(zone, *lines)

    def contains(self, point):
        x, y = point
        x1, y1, x2, y2 = self.zone
        return x1 <= x <= x2 and y1 <= y <= y2


@dataclass
class Crossing:
    approached: bool = False
    crossed: bool = False

    def observe(self, point, line):
        (x1, y1), (x2, y2) = line
        dx, dy = x2 - x1, y2 - y1
        x, y = point
        length = math.hypot(dx, dy)
        projection = ((x - x1) * dx + (y - y1) * dy) / length
        if not 0 <= projection <= length:
            return
        distance = (dx * (y - y1) - dy * (x - x1)) / length
        if distance < -.012:
            self.approached = True
        elif distance > .012 and self.approached:
            self.crossed = True


@dataclass
class PlateCrop:
    timestamp: float
    quality: float
    tight: object
    padded: object


@dataclass
class Passage:
    last_seen: float
    box: tuple
    vehicle_id: Optional[int]
    crossing: Crossing = field(default_factory=Crossing)
    candidates: list = field(default_factory=list)
    reads: deque = field(default_factory=lambda: deque(maxlen=12))
    last_ocr: float = -math.inf
    plate: Optional[str] = None
    crossed: bool = False

    def confirmed_plate(self):
        # A stopped vehicle can hold the same passage for much longer than the
        # crop buffer. Keep its votes until the passage itself is lost.
        reads = {when: (plate, score) for when, plate, score in self.reads}.values()
        votes = Counter(plate for plate, _ in reads)
        if not votes:
            return None
        plate, count = votes.most_common(1)[0]
        if any(other != plate and total >= count for other, total in votes.items()):
            return None
        strong = sum(candidate == plate and score >= .85 for candidate, score in reads)
        conflicting_strong = any(candidate != plate and score >= .85 for candidate, score in reads)
        if count >= 3 or (strong >= 2 and not conflicting_strong):
            return plate
        return None


def center(box):
    x1, y1, x2, y2 = box
    return ((x1 + x2) / 2, (y1 + y2) / 2)


def associated_vehicle(point, vehicles):
    x, y = point
    matches = [track for track, (x1, y1, x2, y2) in vehicles.items()
               if x1 <= x <= x2 and y1 <= y <= y2]
    return matches


def match_passage(box, vehicle_id, passages, used, visible_vehicles, now):
    x, y = center(box)
    width, height = box[2] - box[0], box[3] - box[1]
    choices = []
    for index, passage in enumerate(passages):
        if index in used:
            continue
        if vehicle_id is not None and passage.vehicle_id == vehicle_id:
            return index
        if passage.vehicle_id in visible_vehicles and passage.vehicle_id != vehicle_id:
            continue
        if now - passage.last_seen > .75:
            continue
        previous = passage.box
        px, py = center(previous)
        previous_width, previous_height = previous[2] - previous[0], previous[3] - previous[1]
        dx = abs(x - px) / max(width, previous_width)
        dy = abs(y - py) / max(height, previous_height)
        if (dx <= .8 and dy <= .55 and min(width, previous_width) >= .45 * max(width, previous_width)):
            choices.append((dx + dy, index))
    choices.sort()
    if len(choices) == 1 or (len(choices) > 1 and choices[1][0] - choices[0][0] > .25):
        return choices[0][1]
    return None
