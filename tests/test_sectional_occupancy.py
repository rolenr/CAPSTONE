import unittest

from computer_vision.sectional_occupancy import OccupancyMonitor, inside, overlaps_polygon


class SectionalOccupancyTests(unittest.TestCase):
    def setUp(self):
        self.slot = [[0.0, 0.0], [0.4, 0.0], [0.4, 0.4], [0.0, 0.4]]
        self.road = [[0.5, 0.0], [1.0, 0.0], [1.0, 0.5], [0.5, 0.5]]
        self.monitor = OccupancyMonitor({"A-01": self.slot}, {"roadway": self.road},
                                        "test-video", grace_seconds=600)

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

    def test_no_parking_alert_after_ten_minutes_once(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        for second in range(601):
            events = self.monitor.update(second, second, [], car)
            self.assertFalse(any(e["event_type"] == "parking.no_parking.overstay"
                                 for e in events))
        events = self.monitor.update(601, 601, [], car)
        alerts = [e for e in events if e["event_type"] == "parking.no_parking.overstay"]
        self.assertEqual(len(alerts), 1)
        self.assertEqual(alerts[0]["region"], "roadway")
        self.assertTrue(alerts[0]["review_required"])
        events = self.monitor.update(700, 700, [], car)
        self.assertFalse(any(e["event_type"] == "parking.no_parking.overstay"
                             for e in events))

    def test_missing_vehicle_resets_stay(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        self.monitor.update(0, 0, [], car)
        self.monitor.update(4, 4, [], [])
        events = self.monitor.update(605, 605, [], car)
        self.assertFalse(any(e["event_type"] == "parking.no_parking.overstay"
                             for e in events))

    def test_bay_checkpoint_can_report_occupied_but_not_vacant_alone(self):
        # Geometry remains useful even if the model predicts the wrong bay ID.
        bay = [("D-04", .88, (.1, .1, .3, .3))]
        self.monitor.update(0, 0, bay, None)
        self.monitor.update(1, 1, bay, None)
        events = self.monitor.update(2, 2, bay, None)
        self.assertEqual(events[0]["occupancy_state"], "OCCUPIED")
        self.monitor.update(3, 3, [], None)
        self.monitor.update(4, 4, [], None)
        self.assertEqual(self.monitor.update(5, 5, [], None), [])


if __name__ == "__main__":
    unittest.main()
