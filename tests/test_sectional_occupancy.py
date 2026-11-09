import unittest
import json
from pathlib import Path

from computer_vision.sectional_occupancy import (OccupancyMonitor, inside,
    overlaps_polygon, restricted_region_for_vehicle, vehicles_for_bays)


class SectionalOccupancyTests(unittest.TestCase):
    def test_every_camera_zone_has_bays_and_a_driveway_region(self):
        root = Path(__file__).resolve().parents[1] / 'computer_vision/recorded_test_cases/regions'
        for zone, capacity in [('a',9),('b',7),('c',6),('d',7),('e',10)]:
            with self.subTest(zone=zone):
                document = json.loads((root / f'zone_{zone}.json').read_text())
                camera = next(iter(document['cameras'].values()))
                self.assertEqual(len(camera['slots']),capacity)
                self.assertTrue(any(name.startswith('driveway') for name in camera['no_parking_zones']))
                if zone == 'e':
                    self.assertTrue(all(max(y for _, y in polygon) <= .65
                                        for polygon in camera['slots'].values()))
                    self.assertGreater(min(y for _, y in camera['no_parking_zones']['driveway_middle_e']), .65)

    def setUp(self):
        self.slot = [[0.0, 0.0], [0.4, 0.0], [0.4, 0.4], [0.0, 0.4]]
        self.road = [[0.5, 0.0], [1.0, 0.0], [1.0, 0.5], [0.5, 0.5]]
        self.monitor = OccupancyMonitor({"A-01": self.slot}, {"roadway": self.road},
                                        "test-video")

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

    def test_restricted_alert_at_twenty_seconds_once(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        for second in range(20):
            events = self.monitor.update(second, second, [], car)
            self.assertFalse(any(e["event_type"] == "parking.restricted.overstay"
                                 for e in events))
        events = self.monitor.update(20, 20, [], car)
        alerts = [e for e in events if e["event_type"] == "parking.restricted.overstay"]
        self.assertEqual(len(alerts), 1)
        self.assertEqual(alerts[0]["region"], "roadway")
        self.assertEqual(alerts[0]["duration_seconds"], 20)
        self.assertTrue(alerts[0]["review_required"])
        events = self.monitor.update(21, 21, [], car)
        self.assertFalse(any(e["event_type"] == "parking.restricted.overstay"
                             for e in events))

    def test_missing_vehicle_resets_stay(self):
        car = [("car", .95, (.65, .1, .75, .3))]
        self.monitor.update(0, 0, [], car)
        self.monitor.update(4, 4, [], [])
        events = self.monitor.update(25, 25, [], car)
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
        for second in range(20):
            monitor.update(second, second, [], car)
        alert = monitor.update(20, 20, [], car)
        self.assertEqual(alert[0]["violation_type"], "DOUBLE_PARKING")

    def test_roadway_vehicle_does_not_occupy_overlapping_bays(self):
        bays = {
            'C-03': [[.35,.44],[.46,.44],[.46,.68],[.35,.68]],
            'C-04': [[.47,.44],[.58,.44],[.58,.68],[.47,.68]],
        }
        road = {'driveway_zone_c': [[.015,.655],[.985,.655],[.985,.93],[.015,.93]]}
        monitor = OccupancyMonitor(bays, road, 'zone-c', grace_seconds=20)
        parked = ('car', .9, (.36,.47,.43,.63))
        stopped = ('car', .95, (.35,.54,.57,.72))
        self.assertIsNone(restricted_region_for_vehicle(parked[2],road))
        self.assertEqual(restricted_region_for_vehicle(stopped[2],road),'driveway_zone_c')
        self.assertEqual(vehicles_for_bays([parked,stopped],road),[parked])
        for second in range(20):
            events = monitor.update(second,second,[],[parked,stopped])
            self.assertFalse(any(e['event_type']=='parking.restricted.overstay' for e in events))
        events = monitor.update(20,20,[],[parked,stopped])
        self.assertEqual(monitor.slot_state,{'C-03':'OCCUPIED','C-04':'VACANT'})
        self.assertEqual([e['region'] for e in events if e['event_type']=='parking.restricted.overstay'],
                         ['driveway_zone_c'])

    def test_driveway_boundary_covers_other_positions(self):
        road = {'driveway_zone_c': [[.015,.655],[.985,.655],[.985,.93],[.015,.93]]}
        for x in (.12,.5,.85):
            monitor = OccupancyMonitor({},road,'zone-c',grace_seconds=20)
            car = [('car',.9,(x-.05,.68,x+.05,.80))]
            for second in range(20):
                self.assertFalse(monitor.update(second,second,[],car))
            self.assertEqual(monitor.update(20,20,[],car)[0]['violation_type'],'DRIVEWAY')

    def test_twenty_second_simulation_needs_continuous_detections(self):
        road = {'driveway': [[.5,0],[1,0],[1,.5],[.5,.5]]}
        monitor = OccupancyMonitor({},road,'clip',grace_seconds=20,max_observation_gap=1.5)
        car = [('car',.9,(.65,.1,.75,.3))]
        for second in range(10):
            monitor.update(second,second,[],car)
        for second in range(12,32):
            self.assertFalse(monitor.update(second,second,[],car))
        self.assertEqual(monitor.update(32,32,[],car)[0]['duration_seconds'],20)


if __name__ == "__main__":
    unittest.main()
