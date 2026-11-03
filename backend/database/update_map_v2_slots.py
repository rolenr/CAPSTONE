"""Apply the map-v2 A–D slot counts while retaining historical slot links.

Run from the repository root: python3 backend/database/update_map_v2_slots.py
"""

import sqlite3
from pathlib import Path


DB = Path(__file__).with_name("parking.db")
CAPACITIES = {"A": 9, "B": 7, "C": 6, "D": 7}
MOVES = {"A-10": "A-09", "B-09": "B-07"}
RETIRED = ("A-10", "B-08", "B-09", "B-10", "C-07", "C-08", "D-08", "D-09", "D-10")


def apply(db):
    db.execute("PRAGMA foreign_keys = ON")
    db.execute("BEGIN IMMEDIATE")
    try:
        columns = {row[1] for row in db.execute("PRAGMA table_info(parking_slots)")}
        if "is_active" not in columns:
            db.execute("ALTER TABLE parking_slots ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1")

        slots = {row[1]: row for row in db.execute(
            "SELECT slot_id, slot_number, zone, is_occupied, is_active FROM parking_slots"
        )}
        for old_name, new_name in MOVES.items():
            old, new = slots[old_name], slots[new_name]
            sessions = db.execute(
                "SELECT session_id FROM parking_sessions WHERE slot_id = ? "
                "AND location_status = 'PARKED' AND exit_time IS NULL", (old[0],)
            ).fetchall()
            if sessions:
                if new[3] or db.execute(
                    "SELECT 1 FROM parking_sessions WHERE slot_id = ? "
                    "AND location_status = 'PARKED' AND exit_time IS NULL LIMIT 1", (new[0],)
                ).fetchone():
                    raise ValueError(f"destination {new_name} is occupied")
                if db.execute(
                    "SELECT 1 FROM reservations WHERE slot_id = ? AND status IN ('ACTIVE','PENDING_PAYMENT') LIMIT 1",
                    (new[0],)
                ).fetchone():
                    raise ValueError(f"destination {new_name} has an active reservation")
                db.execute(
                    "UPDATE parking_sessions SET slot_id = ? WHERE slot_id = ? "
                    "AND location_status = 'PARKED' AND exit_time IS NULL", (new[0], old[0])
                )
                db.execute("UPDATE parking_slots SET is_occupied = 1 WHERE slot_id = ?", (new[0],))
                db.execute("UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ?", (old[0],))
                for (session_id,) in sessions:
                    db.execute(
                        "INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details) "
                        "VALUES ('system', 'MAP_V2_SLOT_MOVE', 'parking_session', ?, ?)",
                        (str(session_id), f"Map v2 reassigned active session from {old_name} to {new_name}.")
                    )

        for name in RETIRED:
            slot = slots[name]
            if db.execute(
                "SELECT 1 FROM parking_sessions WHERE slot_id = ? "
                "AND location_status = 'PARKED' AND exit_time IS NULL LIMIT 1", (slot[0],)
            ).fetchone() or db.execute(
                "SELECT 1 FROM reservations WHERE slot_id = ? AND status IN ('ACTIVE','PENDING_PAYMENT') LIMIT 1",
                (slot[0],)
            ).fetchone():
                raise ValueError(f"cannot retire {name}: active session or reservation remains")
            db.execute("UPDATE parking_slots SET is_active = 0, is_occupied = 0 WHERE slot_id = ?", (slot[0],))

        for zone, capacity in CAPACITIES.items():
            actual = db.execute(
                "SELECT COUNT(*) FROM parking_slots WHERE zone = ? AND is_active = 1", (zone,)
            ).fetchone()[0]
            if actual != capacity:
                raise ValueError(f"Zone {zone}: expected {capacity} active slots, found {actual}")
            occupied = db.execute(
                "SELECT COUNT(*) FROM parking_slots WHERE zone = ? AND is_active = 1 AND is_occupied = 1",
                (zone,)
            ).fetchone()[0]
            db.execute(
                "UPDATE spatial_allocation SET max_capacity = ?, active_parked_count = ? "
                "WHERE zone_id = ?", (capacity, occupied, zone)
            )
        db.commit()
    except Exception:
        db.rollback()
        raise


if __name__ == "__main__":
    with sqlite3.connect(DB) as connection:
        apply(connection)
    print("Map-v2 slots updated: A=9, B=7, C=6, D=7; legacy slot history retained.")
