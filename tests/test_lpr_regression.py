import os
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import cv2
import numpy as np

from computer_vision.gate_passage import Crossing, Passage, match_passage
from computer_vision.live_vigi import PlateDetector, normalize_ocr_plate


class FixedReader:
    def readtext(self, image, **kwargs):
        return [([[0, 0], [20, 0], [20, 10], [0, 10]], "DBF24891", 0.8)]


class LprRegressionTests(unittest.TestCase):
    def test_border_character_is_trimmed_from_the_correct_end(self):
        self.assertEqual(normalize_ocr_plate("DBF24891"), "DBF2489")
        self.assertEqual(normalize_ocr_plate("JDBF24891"), "DBF2489")
        self.assertIsNone(normalize_ocr_plate("BF24891"))

    def test_exit_ocr_accepts_a_single_crop_without_unpacking_error(self):
        detector = PlateDetector("exit", None, FixedReader(), cv2)
        crop = np.zeros((30, 100, 3), dtype=np.uint8)
        confidence, plate = detector._read_plate(crop, crop)
        self.assertEqual(plate, "DBF2489")
        self.assertGreaterEqual(confidence, 0.8)


    def test_untracked_plate_confirms_after_crossing_with_distinct_frames(self):
        # Two reads while the vehicle pauses, then a sideways exit crossing.
        x_positions = iter([122] * 13 + [113, 91, 85])

        class PlateModel:
            def predict(self, _frame, **_kwargs):
                x = next(x_positions)
                box = SimpleNamespace(conf=[.9], xyxy=np.array([[x - 15, 20, x + 15, 35]]))
                return [SimpleNamespace(boxes=[box])]

        detector = PlateDetector("exit", PlateModel(), FixedReader(), cv2)
        frame = np.zeros((100, 200, 3), dtype=np.uint8)
        reads = iter([(.96, "DBF2489")] * 2)
        with patch.object(detector, "_read_plate", side_effect=lambda *_: next(reads, (0, None))):
            timestamps = [index * .5 for index in range(13)] + [6.2, 6.4, 6.6]
            results = [detector.process(frame, now=t)[1] for t in timestamps]
        self.assertEqual(results, [[]] * 14 + [[("exit", "DBF2489")], []])

    def test_rectification_accepts_a_skewed_plate_outline(self):
        crop = np.zeros((90, 230, 3), dtype=np.uint8)
        corners = np.int32([[24, 20], [194, 13], [209, 59], [30, 72]])
        cv2.fillConvexPoly(crop, corners, (230, 230, 230))
        detector = PlateDetector("exit", None, FixedReader(), cv2)
        normalized = detector._rectify_plate(crop)
        self.assertIsNotNone(normalized)
        self.assertGreater(normalized.shape[1], 3 * normalized.shape[0])
        self.assertGreater(normalized.mean(), 200)

    def test_reverse_motion_does_not_qualify(self):
        crossing = Crossing()
        line = ((.5, .1), (.5, .9))
        for point in ((.4, .5), (.5, .5), (.6, .5)):
            crossing.observe(point, line)
        self.assertFalse(crossing.crossed)

    def test_plate_evidence_cannot_switch_between_visible_vehicles(self):
        first = Passage(0, (.4, .4, .5, .5), vehicle_id=3)
        visible = {3: (.2, .2, .6, .8), 4: (.3, .3, .7, .9)}
        self.assertIsNone(match_passage(first.box, 4, [first], set(), visible, .1))

    def test_conflicting_strong_reads_block_two_read_confirmation(self):
        passage = Passage(0, (.4, .4, .5, .5), vehicle_id=None)
        passage.reads.extend([(0, "DBF2489", .98), (.1, "DBF2488", .94), (.2, "DBF2489", .93)])
        self.assertIsNone(passage.confirmed_plate())
        passage.reads.append((.3, "DBF2489", .95))
        self.assertEqual(passage.confirmed_plate(), "DBF2489")


@unittest.skipUnless(os.environ.get("RUN_NIGHT_LPR") == "1", "requires local night recordings and model weights")
class NightGateReplayTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import torch

        torch.set_num_threads(min(torch.get_num_threads(), 4))
        cv2.setNumThreads(2)
        cls.root = Path(__file__).resolve().parents[1]

    def assert_gate_pass(self, clip_name, role, expected):
        from computer_vision.scenario5 import process_video_feed

        clip = self.root / "computer_vision/simulation_videos" / clip_name
        weights = self.root / "computer_vision/best.pt"
        plate = process_video_feed(str(clip), str(weights), role, feed_type=role, preview=False)
        self.assertEqual(plate, expected)

    def test_four_night_gate_passes(self):
        for car, expected in (("CIVIC", "CAH9204"), ("XPANDER", "DBF2489")):
            for role in ("entrance", "exit"):
                with self.subTest(car=car, role=role):
                    self.assert_gate_pass(f"{role.upper()}-{car}-NIGHT.mov", role, expected)

    def test_daytime_xpander_pause_before_exit(self):
        self.assert_gate_pass("EXIT_XPANDER.mov", "exit", "DBF2489")


if __name__ == "__main__":
    unittest.main()
