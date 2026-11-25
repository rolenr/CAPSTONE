"""Recorded simulation entry point and tenant-validation rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Tenant validation uses its actual recorded time, like other live rules."""
    print("Complete tenant validation in the Vendor portal, then press Enter to continue.", flush=True)
    input()
    if args.tenant_expired:
        print("Use the actual 20-minute validation window; wait for it to expire before testing a late exit.", flush=True)
    else:
        print("Continue to the exit feed; the API will decide whether validation is still active.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "tenant", *sys.argv[1:]]))
