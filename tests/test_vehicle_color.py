import unittest
from pathlib import Path

import cv2

from computer_vision.vehicle_color import classify_vehicle_color, consensus


ROOT = Path(__file__).resolve().parents[1]


class VehicleColorTests(unittest.TestCase):
    def test_consensus_requires_repeated_agreement(self):
        self.assertEqual(consensus([('WHITE', .9)])[0], 'UNKNOWN')
        self.assertEqual(consensus([('WHITE', .8), ('WHITE', .9)])[0], 'UNKNOWN')
        self.assertEqual(consensus([('WHITE', .8), ('WHITE', .9), ('WHITE', .85)])[0], 'WHITE')
        self.assertEqual(consensus([('WHITE', .8), ('BLACK', .9)])[0], 'UNKNOWN')
        self.assertEqual(consensus([('WHITE', .8), ('BLACK', .9), ('WHITE', .85),
                                    ('BLACK', .95), ('WHITE', .9), ('BLACK', .99)])[0], 'BLACK')
        self.assertEqual(consensus([('WHITE', .9), ('BLACK', .9), ('GRAY', .9)])[0], 'UNKNOWN')

    def test_white_pickup_in_recorded_gate_and_sectional_clips(self):
        examples = [
            ('ENTRANCE-PU-WHITE.mp4', 165, (.48, .19, .76, .54)),
            ('SectOcc_Sample1.mp4', 1300, (.48, .60, .58, .82)),
        ]
        for filename, frame_index, box in examples:
            with self.subTest(video=filename):
                capture = cv2.VideoCapture(str(ROOT / 'computer_vision/simulation_videos' / filename))
                capture.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
                ok, frame = capture.read()
                capture.release()
                self.assertTrue(ok)
                color, confidence = classify_vehicle_color(frame, box, cv2)
                self.assertEqual(color, 'WHITE')
                self.assertGreaterEqual(confidence, .55)

    def test_fd_white_and_xpander_gray_across_camera_views(self):
        examples = [
            ('ENTRANCE_FD.mov', 3.2, (.457, .259, .682, .537), 'WHITE'),
            ('PARK FD ZONE E-01.mp4', 45.0, (.84, .52, .99, .65), 'WHITE'),
            ('ENTRANCE_XPANDER.mov', 3.3, (.434, .241, .61, .464), 'GRAY'),
            ('EXIT_XPANDER.mov', 24.5, (0, .004, .568, .981), 'GRAY'),
            ('PARK_XPANDER-ZONEB-05V1.mp4', 31.0, (.345, .54, .45, .72), 'GRAY'),
            ('PARK_XPANDER-ZONEC-01v1.mp4', 31.0, (.34, .48, .45, .66), 'GRAY'),
        ]
        for filename, seconds, box, expected in examples:
            with self.subTest(video=filename):
                capture = cv2.VideoCapture(str(ROOT / 'computer_vision/simulation_videos' / filename))
                capture.set(cv2.CAP_PROP_POS_MSEC, seconds * 1000)
                ok, frame = capture.read()
                capture.release()
                self.assertTrue(ok)
                color, confidence = classify_vehicle_color(frame, box, cv2)
                self.assertEqual(color, expected)
                self.assertGreaterEqual(confidence, .55)


if __name__ == '__main__':
    unittest.main()
