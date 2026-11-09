"""Print an issued entry token as a scannable terminal QR and save its image."""

from pathlib import Path


def print_entry_ticket(token: str, session_id: int, slot: str, color: str,
                       output_dir: Path) -> Path:
    import cv2

    print(f"QR token: {token}", flush=True)
    output_dir.mkdir(parents=True, exist_ok=True)
    matrix = cv2.QRCodeEncoder_create().encode(token)
    image = cv2.copyMakeBorder(matrix, 4, 4, 4, 4, cv2.BORDER_CONSTANT, value=255)
    image = cv2.resize(image, None, fx=8, fy=8, interpolation=cv2.INTER_NEAREST)
    path = output_dir / f"entry_qr_session_{session_id}.png"
    if not cv2.imwrite(str(path), image):
        raise OSError(f"Could not save entry QR: {path}")
    print(f"\nENTRY QR · session {session_id} · bay {slot} · entrance color {color}", flush=True)
    quiet = [255] * (matrix.shape[1] + 8)
    for row in [quiet] * 4 + [[255] * 4 + list(values) + [255] * 4 for values in matrix] + [quiet] * 4:
        print("\x1b[47;30m" + "".join("██" if value == 0 else "  " for value in row) + "\x1b[0m")
    print(f"QR token: {token}\nQR image: {path}\n", flush=True)
    return path
