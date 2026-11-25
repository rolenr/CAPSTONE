# Recorded gate plate and color check

The existing entrance and exit pipeline was run without preview against the paired XPANDER and FD recordings, using `computer_vision/best.pt` for plates, `yolov8n.pt` for vehicles, and the shared color observer. This checks the actual production inference path, not a hand-picked image crop.

| Recording | Confirmed plate | Color result |
| --- | --- | --- |
| `ENTRANCE_XPANDER.mov` | DBF2489 | Gray (0.55 consensus score) |
| `EXIT_XPANDER.mov` | DBF2489 | Unknown |
| `ENTRANCE_FD.mov` | Unreadable | Unknown |
| `EXIT_FD.mov` | Unreadable | White (0.978 consensus score) |

The XPANDER plate is consistent across its two gate views. FD's plate is not confirmed by either gate view, so the issued QR remains the supported fallback. Color is supporting evidence and never substitutes for a confirmed plate or QR. `UNKNOWN` means the camera did not collect enough consistent evidence; it should not be replaced with a guessed color.

The sectional plate publisher now requires a repeated majority of recent reads. This rejects a tie or a two-of-four split such as the conflicting Zone E snapshot read. The zone snapshot sample results are in `SECTIONAL_PLATE_SNAPSHOT_EVALUATION.md`.

These clips do not constitute a labeled accuracy dataset. A true plate or color accuracy percentage requires independently verified ground truth for all sampled vehicles, including misses and unreadable views.
