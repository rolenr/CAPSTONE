# GUI Review Fixes: Evidence

This folder holds the evidence for four panel review items: readability and mobile support, zoom, data tables, and CCTV overlays. The screenshots were captured on 2026-11-23 from the local demo server (`php -S 127.0.0.1:8080 backend/router.php`) with headless Chrome, signed in as the demo administrator. "Before" is the committed frontend and "after" is the updated one. Both runs used the same database.

## 1. Readability and mobile support

An automated audit of the rendered pages counted every visible text element smaller than 13px and every control (button, input, select) smaller than 32px. It also checked whether the page scrolls sideways.

| Page / width | Text < 13px | Controls < 32px | Horizontal scroll |
|---|---|---|---|
| Admin console, phone 375px | 81 → **1** | 42 → **0** | none (before and after) |
| Admin console, tablet 768px | 81 → **1** | 42 → **0** | none |
| Admin console, desktop 1440px | 84 → **2** | 42 → **0** | none |
| Live map, phone 375px | 30 → **1** | 45 → **0** | none |
| Live map, tablet 768px | 30 → **1** | 45 → **0** | none |
| Live map, desktop 1440px | 30 → **1** | 45 → **0** | none |

The remaining small text is the footer copyright line and the two-letter avatar initials. Full results are in `audit-before.json` and `audit-after.json`.

What changed:
- Secondary text has a minimum size of about 13.8px (0.8125rem). This covers table notes, chart labels, zone cards and badges.
- Sortable table headers are at least 36px tall. Zoom buttons are 44px.
- Map markers are larger (18px slot dots with a 36px tap area, 34px zone labels) and have stronger outlines.
- Slot states use shape as well as colour (circle = vacant, square = occupied, diamond = reserved), so colour-blind users can tell them apart.
- On phones, the 24-hour chart shows every third hour label instead of shrinking all of them.

Screenshots: `before-admin-phone.png` vs `after-admin-phone.png`, `before-live-map-phone.png` vs `after-live-map-phone.png`, and the tablet captures.

## 2. Zoom for the parking layout and CCTV views

- **Where:** the live parking map and the camera evidence viewer in the alert and violation review dialogs.
- **Controls:** − / + buttons, a zoom percentage, Reset, and Full screen (evidence only). Pinch on touch screens, Ctrl + scroll or trackpad pinch on desktop, drag to move, double-click to zoom. Keyboard: `+`, `-`, `0` and the arrow keys.
- **Controls stay reachable:** the toolbar sits outside the zoomed area, so it never moves or gets covered. Normal page scrolling still works when the map is not zoomed.
- **Markers stay readable:** map markers keep the same on-screen size while zoomed, so they do not grow and cover the map.

Screenshots: `zoom-live-map-phone.png` (map at 195% on a phone) and `zoom-evidence-dialog.png` (camera frame at 195% in the review dialog).

## 3. Data tables

All eight admin tables have a search box, column filters, sortable headers, a match count, "Clear filters" and a "no records match" message.

## 4. CCTV detection overlays

`cctv-overlay-before-after.jpg` shows the same 4K Zone C frame. The top half is the old drawing and the bottom half is the new one.

- Line width and text size scale with the video resolution. The old 2px lines and tiny text could barely be seen on 4K footage.
- Each line has a dark outline underneath, so it stays visible on light pavement and on dark cars.
- Labels sit on solid colour chips placed outside the box or bay, so they never cover the vehicle or its plate.
- Bay outlines take the colour of their state (green = vacant, red = occupied), and the label states it in words.
- Restricted zones get a light 10% tint, so the area is clear and the pavement still shows through.
- The saved evidence images that the admin reviews now include each bay's state.

The drawing code is in `computer_vision/overlay.py`, which `sectional_occupancy.py` and `live_vigi.py` both use.
