# Detection Event Schema

**Contract version:** 1.0
**Machine-readable schema:** [`schemas/detection-event.schema.json`](schemas/detection-event.schema.json)

This document defines the event payload shape for future recorded-video integrations. It covers recognized vehicle plates at entry or exit and a parking slot's occupancy state. Defining this format does not connect a vision script to the API or persist events.

## Event types

| `event_type` | Required event-specific fields | Meaning |
| --- | --- | --- |
| `vehicle.entry.detected` | `plate_number` | A vehicle plate was recognized in an entry video. |
| `vehicle.exit.detected` | `plate_number` | A vehicle plate was recognized in an exit video. |
| `parking.occupancy.detected` | `slot_number`, `occupancy_state` | A parking slot was classified as occupied or vacant. |

## Required fields

Every event includes:

- `schema_version`: the contract version, currently `1.0`.
- `event_id`: a UUID unique to the emitted event.
- `event_type`: one of the event types listed above.
- `timestamp`: observation time in RFC 3339 date-time format with a timezone, preferably UTC (`Z`).
- `confidence`: a number from `0` through `1` for the reported plate or occupancy result. For plate events, this is the OCR confidence associated with the accepted plate text; for occupancy events, it is the occupancy classifier confidence.
- `source.video`: the source video path or URI. Use repository-relative paths for local demo clips where practical, not a machine-specific absolute path.
- `source.frame_index`: zero-based frame index where the detection was observed.

Plate text must be normalized to uppercase alphanumeric characters with spaces and punctuation removed. Occupancy state is `OCCUPIED` or `VACANT`. Unknown or low-confidence classifications should not be emitted as a detection event; how to represent rejected/no-detection results can be decided when the scripts are integrated.

## Examples

Entry plate detection:

```json
{
  "schema_version": "1.0",
  "event_id": "10a6d66f-7179-4ff6-8e40-a076b6559875",
  "event_type": "vehicle.entry.detected",
  "timestamp": "2026-10-27T09:30:12.450Z",
  "plate_number": "ABC1234",
  "confidence": 0.94,
  "source": {
    "video": "computer_vision/simulation_videos/Entry Fortuner - Day (walk-in).mp4",
    "frame_index": 128
  }
}
```

Occupancy detection:

```json
{
  "schema_version": "1.0",
  "event_id": "38c36e60-47c3-47ee-b36e-0e75499a367c",
  "event_type": "parking.occupancy.detected",
  "timestamp": "2026-10-27T09:30:12.450Z",
  "slot_number": "A-01",
  "occupancy_state": "OCCUPIED",
  "confidence": 0.88,
  "source": {
    "video": "computer_vision/simulation_videos/parking-section.mp4",
    "frame_index": 128
  }
}
```

## Validation and scope

Validate event payloads against the JSON Schema before accepting them. Reject unknown event types, missing or incorrectly typed required values, confidence outside the inclusive 0–1 range, timestamps without a timezone, malformed normalized plates, and missing video/frame provenance. Each type requires only its applicable fields; extra fields are rejected to keep the contract stable.

The `event_id` provides an identity for a single emitted event, but this contract alone does not guarantee idempotency or prevent duplicate database updates. Duplicate handling belongs to TASK02.4 and the later backend integration work in US-03. The current vision scripts do not yet emit this schema, and no occupancy detector is currently mapped to registered slots.
