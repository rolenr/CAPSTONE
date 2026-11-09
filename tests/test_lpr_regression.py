import unittest

import cv2
import numpy as np

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


if __name__ == "__main__":
    unittest.main()
