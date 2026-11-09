"""Recorded simulation entry point and normal-fare rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Move only this test session past grace and require the standard fare."""
    from computer_vision.full_simulation import advance_simulation_clock
    advance_simulation_clock(args, scenario, session_id, token, "NORMAL_FARE")
    print("Normal-fare case: confirm unpaid exit is denied, then pay the bill before continuing.", flush=True)
    input()

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "normal_fare", *sys.argv[1:]]))
