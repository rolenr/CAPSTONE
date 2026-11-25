"""The selected recorded case does not decide who owns a booked bay."""

import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from computer_vision import full_simulation


class RecordedBookingSelectionTest(unittest.TestCase):
    def test_booking_owner_controls_reserved_bay_path(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "booking.db"
            with sqlite3.connect(path) as db:
                db.executescript("""
                    CREATE TABLE vehicles(vehicle_id INTEGER PRIMARY KEY, plate_number TEXT);
                    CREATE TABLE parking_slots(slot_id INTEGER PRIMARY KEY, slot_number TEXT);
                    CREATE TABLE reservations(vehicle_id INTEGER, slot_id INTEGER,
                        status TEXT, payment_state TEXT, token_id TEXT);
                    INSERT INTO vehicles VALUES(1, 'AAA1111'),(2, 'BBB2222');
                    INSERT INTO parking_slots VALUES(1, 'B-05');
                    INSERT INTO reservations VALUES(1, 1, 'ACTIVE', 'PAID', 'QR_BOOKING');
                """)
            with patch.object(full_simulation, "DB", path):
                self.assertFalse(full_simulation.bay_reserved_for_another_vehicle("AAA1111", "B-05"))
                self.assertTrue(full_simulation.bay_reserved_for_another_vehicle("BBB2222", "B-05"))
                self.assertEqual(full_simulation.reservation_plate_for_token("QR_BOOKING"), "AAA1111")


if __name__ == "__main__":
    unittest.main()
