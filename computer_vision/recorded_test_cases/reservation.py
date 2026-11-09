"""Recorded simulation entry point and paid-reservation rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Verify the frontend reservation was fulfilled for this entry session."""
    status = str(entry.get("payment_status", "")).upper()
    if status not in ("PAID_RESERVATION", "BYPASSED"):
        raise RuntimeError(f"Reservation entry was not paid or exempt (status: {status or 'unknown'})")
    print(f"Reservation case: frontend reservation fulfilled; entry status is {status}.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "reservation", *sys.argv[1:]]))
