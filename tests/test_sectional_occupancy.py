import unittest

from computer_vision.sectional_occupancy import OccupancyMonitor, inside, overlaps_polygon


class SectionalOccupancyTests(unittest.TestCase):
    def setUp(self):
        self.slot = [[0.0, 0.0], [0.4, 0.0], [0.4, 0.4], [0.0, 0.4]]
        self.road = [[0.5, 0.0], [1.0, 0.0], [1.0, 0.5], [0.5, 0.5]]
        self.monitor = OccupancyMonitor({"A-01": self.slot}, {"roadway": self.road},
                                        "test-video", grace_seconds=300)

    def test_polygon_and_stable_slot_states(self):
        self.assertTrue(inside((0.2, 0.2), self.slot))
        self.assertFalse(overlaps_polygon((0.6, 0.1, 0.8, 0.3), self.slot))
        vehicle = [("car", 0.92, (0.1, 0.1, 0.3, 0.3))]
        self.assertEqual(self.monitor.update(0, 0, [], vehicle), [])
        self.assertEqual(self.monitor.update(1, 1, [], vehicle), [])
        events = self.monitor.update(2, 2, [], vehicle)
        self.assertEqual([(e["slot_number"], e["occupancy_state"]) for e in events],
                         [("A-01", "OCCUPIED")])
        self.monitor.update(3, 3, [], [])
        self.monitor.update(4, 4, [], [])
        vacant = self.monitor.update(5, 5, [], [])
        self.assertEqual(vacant[0]["occupancy_state"], "VACANT")
        self.assertIsNone(vacant[0]["confidence"])

    def test_restricted_alert_at_five_minutes_once(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        for second in range(300):
            events = self.monitor.update(second, second, [], car)
            self.assertFalse(any(e["event_type"] == "parking.restricted.overstay"
                                 for e in events))
        events = self.monitor.update(300, 300, [], car)
        alerts = [e for e in events if e["event_type"] == "parking.restricted.overstay"]
        self.assertEqual(len(alerts), 1)
        self.assertEqual(alerts[0]["region"], "roadway")
        self.assertEqual(alerts[0]["duration_seconds"], 300)
        self.assertTrue(alerts[0]["review_required"])
        events = self.monitor.update(301, 301, [], car)
        self.assertFalse(any(e["event_type"] == "parking.restricted.overstay"
                             for e in events))

    def test_missing_vehicle_resets_stay(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        self.monitor.update(0, 0, [], car)
        self.monitor.update(4, 4, [], [])
        events = self.monitor.update(305, 305, [], car)
        self.assertFalse(any(e["event_type"] == "parking.restricted.overstay"
                             for e in events))

    def test_bay_checkpoint_cannot_report_occupancy_alone(self):
        bay = [("D-04", .88, (.1, .1, .3, .3))]
        self.monitor.update(0, 0, bay, None)
        self.monitor.update(1, 1, bay, None)
        events = self.monitor.update(2, 2, bay, None)
        self.assertEqual(events, [])
        self.monitor.update(3, 3, bay, [])
        self.monitor.update(4, 4, bay, [])
        vacant = self.monitor.update(5, 5, bay, [])
        self.assertEqual(vacant[0]["occupancy_state"], "VACANT")

    def test_double_parking_region(self):
        monitor = OccupancyMonitor({}, {"double_parking_lane": self.road}, "clip")
        car = [("car", .95, (.65, .1, .75, .3))]
        for second in range(300):
            monitor.update(second, second, [], car)
        alert = monitor.update(300, 300, [], car)
        self.assertEqual(alert[0]["violation_type"], "DOUBLE_PARKING")


if __name__ == "__main__":
    unittest.main()
