"""Recorded simulation entry point and grace-bypass rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Use the real entry timestamp; cashier must grant grace only within 15 minutes."""
    from computer_vision.full_simulation import remind_if_grace_expired
    remind_if_grace_expired(session_id)
    print("Grace started at entrance. Review the ₱0 cashier bill and complete bypass before the 15-minute window ends, then press Enter.", flush=True)
    input()
    remind_if_grace_expired(session_id)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "grace", *sys.argv[1:]]))
