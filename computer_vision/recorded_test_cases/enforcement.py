"""Recorded simulation entry point and Zone C enforcement rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Require staff review; only a confirmed alert adds a cashier fine."""
    if violation_alert is None:
        raise RuntimeError("Zone C violation clip did not create an alert")
    from computer_vision.full_simulation import wait_for_alert_review
    print(f"Review sectional alert {violation_alert} in Admin. Cashier POS will show a fee only if it is confirmed.", flush=True)
    status, violation_id = wait_for_alert_review(scenario, int(violation_alert))
    if status == "REJECTED":
        print("Alert rejected: no violation fee was issued. Continue through Cashier POS before exit.", flush=True)
        return
    if status != "CONFIRMED" or not violation_id:
        raise RuntimeError(f"Zone C enforcement alert has an unexpected review result: {status}")
    print("Alert confirmed: the ₱200 violation fee is due in Cashier POS before exit.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "enforcement", *sys.argv[1:]]))
