"""Shared drawing helpers for CCTV overlays (evidence images and live previews).

Every border is drawn twice, a dark outline under a coloured line, so it stays
visible on bright pavement and on dark vehicles. Labels sit on solid chips
placed *outside* the shape they describe, so they never cover the vehicle,
plate or bay being reported. Sizes scale with the frame resolution so a 1080p
feed is as readable as a 480p clip.
"""

# BGR colours with strong contrast against asphalt and each other.
VACANT = (60, 200, 0)        # green
OCCUPIED = (40, 40, 230)     # red
RESTRICTED = (0, 0, 255)     # pure red, used with a light fill
VEHICLE = (0, 165, 255)      # orange
PLATE = (255, 190, 0)        # sky blue
ROI = (0, 230, 120)          # bright green
PENDING = (0, 215, 255)      # yellow
NEUTRAL = (230, 230, 230)    # light grey
OUTLINE = (15, 15, 15)


def unit(frame):
    """Scale factor relative to a 540-pixel short side (1.0 = 960x540)."""
    height, width = frame.shape[:2]
    return max(1.0, min(width, height) / 540.0)


def line_width(frame):
    return max(2, round(2.5 * unit(frame)))


def _text_colour(background):
    blue, green, red = background
    luminance = 0.299 * red + 0.587 * green + 0.114 * blue
    return (0, 0, 0) if luminance > 140 else (255, 255, 255)


def draw_polygon(cv2, frame, points, colour, fill_alpha=0.0):
    """Outlined polygon, optionally with a translucent fill that keeps details visible."""
    import numpy as np

    pts = np.array(points, dtype=np.int32).reshape((-1, 1, 2))
    if fill_alpha > 0:
        layer = frame.copy()
        cv2.fillPoly(layer, [pts], colour)
        cv2.addWeighted(layer, fill_alpha, frame, 1 - fill_alpha, 0, dst=frame)
    width = line_width(frame)
    cv2.polylines(frame, [pts], True, OUTLINE, width + 2, cv2.LINE_AA)
    cv2.polylines(frame, [pts], True, colour, width, cv2.LINE_AA)
    return frame


def draw_box(cv2, frame, top_left, bottom_right, colour):
    width = line_width(frame)
    cv2.rectangle(frame, top_left, bottom_right, OUTLINE, width + 2, cv2.LINE_AA)
    cv2.rectangle(frame, top_left, bottom_right, colour, width, cv2.LINE_AA)
    return frame


def draw_label(cv2, frame, text, left, top, colour, bottom=None, size=1.0):
    """Solid label chip placed just above ``top``.

    If there is no room above, the chip goes just below ``bottom`` (the lower
    edge of the shape) so it still sits outside it. Returns the chip rectangle.
    """
    height, width = frame.shape[:2]
    u = unit(frame)
    font = cv2.FONT_HERSHEY_SIMPLEX
    scale = 0.55 * u * size
    thickness = max(1, round(1.6 * u * size))
    (text_w, text_h), baseline = cv2.getTextSize(text, font, scale, thickness)
    pad = max(3, round(4 * u))
    gap = max(2, round(3 * u))
    chip_w, chip_h = text_w + 2 * pad, text_h + baseline + 2 * pad

    y1 = top - gap - chip_h
    if y1 < 0:
        y1 = (bottom if bottom is not None else top) + gap
    y1 = max(0, min(height - chip_h, y1))
    x1 = max(0, min(width - chip_w, left))
    x2, y2 = x1 + chip_w, y1 + chip_h

    cv2.rectangle(frame, (x1 - 1, y1 - 1), (x2 + 1, y2 + 1), OUTLINE, -1)
    cv2.rectangle(frame, (x1, y1), (x2, y2), colour, -1)
    cv2.putText(frame, text, (x1 + pad, y2 - pad - baseline), font, scale,
                _text_colour(colour), thickness, cv2.LINE_AA)
    return x1, y1, x2, y2


def labelled_box(cv2, frame, top_left, bottom_right, colour, text=None):
    draw_box(cv2, frame, top_left, bottom_right, colour)
    if text:
        draw_label(cv2, frame, text, top_left[0], top_left[1], colour, bottom=bottom_right[1])
    return frame


def labelled_polygon(cv2, frame, points, colour, text=None, fill_alpha=0.0):
    draw_polygon(cv2, frame, points, colour, fill_alpha)
    if text:
        xs = [p[0] for p in points]
        ys = [p[1] for p in points]
        draw_label(cv2, frame, text, min(xs), min(ys), colour, bottom=max(ys), size=0.9)
    return frame


def status_banner(cv2, frame, lines, alpha=0.55):
    """Translucent header for preview windows; the scene stays visible beneath it."""
    u = unit(frame)
    font = cv2.FONT_HERSHEY_SIMPLEX
    pad = round(10 * u)
    sizes = [(0.65 * u, max(1, round(1.8 * u))), (0.5 * u, max(1, round(1.4 * u)))]
    heights = []
    for index, text in enumerate(lines):
        scale, thick = sizes[min(index, 1)]
        (_, text_h), baseline = cv2.getTextSize(text, font, scale, thick)
        heights.append(text_h + baseline)
    banner_h = sum(heights) + pad * (len(lines) + 1)
    layer = frame.copy()
    cv2.rectangle(layer, (0, 0), (frame.shape[1], banner_h), OUTLINE, -1)
    cv2.addWeighted(layer, alpha, frame, 1 - alpha, 0, dst=frame)
    y = pad
    for index, text in enumerate(lines):
        scale, thick = sizes[min(index, 1)]
        y += heights[index]
        cv2.putText(frame, text, (pad, y), font, scale, (255, 255, 255), thick, cv2.LINE_AA)
        y += pad
    return frame
