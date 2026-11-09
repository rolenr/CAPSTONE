"""Recorded simulation entry point and tenant-validation rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Run the short simulation window for tenant exit validation."""
    from computer_vision.full_simulation import advance_simulation_clock
    print("Complete tenant validation in the Vendor portal, then press Enter to start the window demo.", flush=True)
    input()
    if args.tenant_expired:
        advance_simulation_clock(args, scenario, session_id, token, "TENANT_EXPIRED")
    if args.tenant_expired:
        print("Tenant window expired. Settle the cashier bill before retrying exit.", flush=True)
    else:
        print("Tenant validation is active. Continue to the exit feed.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "tenant", *sys.argv[1:]]))
