"""Recorded simulation entry point and wrong-reserved-slot rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """The shared runner handles any alert caused by the actual bay reservation."""
    print(f"Parking outcome follows the current booking and camera observations for {entry['slot_number']}.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "reserved_slot", *sys.argv[1:]]))
