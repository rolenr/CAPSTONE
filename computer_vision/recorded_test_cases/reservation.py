"""Recorded simulation entry point and paid-reservation rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Report the real entry outcome; the API applies the same booking rules to all runs."""
    status = str(entry.get("payment_status", "")).upper()
    print(f"Entry payment status: {status or 'unknown'}.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "reservation", *sys.argv[1:]]))
