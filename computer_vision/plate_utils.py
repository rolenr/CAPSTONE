import re


def normalize_license_plate(value):
    """Return the OCR/manual-input canonical form: uppercase letters and digits only."""
    return re.sub(r"[^A-Z0-9]", "", str(value).upper())


def prompt_for_license_plate(prompt):
    """Read a manual plate and return its no-space canonical form, or None if blank."""
    raw_value = input(prompt).strip()
    if not raw_value:
        return None

    plate = normalize_license_plate(raw_value)
    if not re.fullmatch(r"[A-Z]{3}\d{3,4}", plate):
        print("[ERROR]: Enter three letters followed by three or four digits, without spaces (e.g. ABC1234).")
        return None

    if raw_value.upper() != plate:
        print(f"[SYSTEM]: Plate formatted as {plate} (no spaces or punctuation).")
    return plate
