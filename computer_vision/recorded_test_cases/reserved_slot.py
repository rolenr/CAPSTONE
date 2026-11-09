"""Recorded simulation entry point and wrong-reserved-slot rule."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from computer_vision.full_simulation import main


def apply_business_rule(args, scenario, session_id, token, entry, violation_alert=None):
    """Require staff review of the observed parking alert before exit is replayed."""
    import sqlite3
    from computer_vision.full_simulation import DB, wait_for_alert_review
    with sqlite3.connect(f"file:{DB}?mode=ro", uri=True) as db:
        alert = db.execute("""SELECT alert_id,violation_type FROM sectional_alerts
            WHERE scenario_id=? AND session_id=?
              AND violation_type IN ('WRONG_SLOT','ZONE_A_PRIORITY')
              AND status='PENDING_REVIEW' ORDER BY alert_id DESC LIMIT 1""",
                           (scenario, session_id)).fetchone()
    if not alert:
        raise RuntimeError("The reserved-bay clip did not create a pending parking alert")
    print(f"{alert[1]} alert {alert[0]} is pending. Review it in Admin before exit.", flush=True)
    status, _fine_id = wait_for_alert_review(scenario, int(alert[0]))
    print(f"{alert[1]} alert review completed: {status}.", flush=True)

if __name__ == "__main__":
    raise SystemExit(main(["recorded", "--case", "reserved_slot", *sys.argv[1:]]))
