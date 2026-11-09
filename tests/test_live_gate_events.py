import io
import json
import queue
import threading
import unittest
from unittest.mock import patch

from computer_vision.live_vigi import publish_camera_events


class LiveGateEventTests(unittest.TestCase):
    def test_unreadable_reserved_entry_forwards_qr_to_camera_api(self):
        sent = []

        class Opener:
            def open(self, request, timeout):
                sent.append(json.loads(request.data))
                return io.BytesIO(b'{"success":true,"duplicate":true}')

        pending = queue.Queue()
        pending.put(('', '2026-11-09T02:00:00+00:00', 'GRAY', .75, 'QR_TEST_RESERVATION'))
        stopped = threading.Event()
        stopped.set()
        with patch('computer_vision.live_vigi.build_opener', return_value=Opener()):
            publish_camera_events(pending, stopped, 'test-token', 'http://127.0.0.1:8080/entry',
                                  'entrance', 'live-test')

        self.assertEqual(len(sent), 1)
        self.assertEqual(sent[0]['qr_token'], 'QR_TEST_RESERVATION')
        self.assertEqual(sent[0]['channel'], 1)
        self.assertEqual(sent[0]['vehicle_color'], 'GRAY')


if __name__ == '__main__':
    unittest.main()
