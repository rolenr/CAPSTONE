# Sectional plate reading: zone snapshot check

Tested the recorded `ZONE A` through `ZONE E SNAPSHOT.mp4` files at 0, 1, and 2 seconds per video. Each frame used the sectional pipeline's vehicle detector (`yolov8n.pt`, confidence 0.35, image size 960), calibrated zone polygons, plate detector (`computer_vision/best.pt`, confidence 0.25), and EasyOCR with the production 0.55 acceptance threshold. This was a read-only test; no events were sent to the API.

| Zone | Frames with at least one raw read | Raw reads by bay (0s / 1s / 2s) | Same plate in at least two samples |
| --- | ---: | --- | --- |
| A | 1/3 | A-05: WKI1208 / — / — | None |
| B | 3/3 | B-03: NGF3495 / NGF3495 / —; B-04: DAB550 / DAB550 / DAB550; B-06: — / CCM1305 / — | B-03, B-04 |
| C | 0/3 | None | None |
| D | 2/3 | D-04: — / COK7355 / —; D-07: — / — / NBV6399 | None |
| E | 3/3 | E-03: NFY3371 / NFY3371 / NFY3371; E-07: CNY3248 / CKY3248 / — | E-03 |

Across 15 sampled frames, 9 had at least one raw plate read. Eight distinct bays produced a read; three bays produced the same plate in at least two sampled frames. E-07 produced conflicting characters, so it should not be treated as a stable identity. The sectional publisher requires two agreeing OCR observations before including a `recognized_plate` in an event. On this sample, only B-03, B-04, and E-03 meet that repeatability condition.

**Accuracy limit:** These snapshots do not provide ground-truth plate labels for every parked vehicle. OCR confidence is a model score, not the percentage of correct plates. This test measures read coverage and repeatability, not precision or recall. Establishing an accuracy percentage requires a manually verified plate list (including unreadable plates) for each visible bay, then comparing the model's published reads against it. The 1-second sampling here is also sparser than the runner's normal snapshot sampling, so these figures should not be treated as final production rates.
