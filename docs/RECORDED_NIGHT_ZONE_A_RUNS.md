# Recorded Zone A night-feed tests

Run the Civic and Xpander one at a time from the repository root. Each grace-bypass run uses five recordings in order: Zone A snapshot, entrance, parking, bay departure, and exit. The runner generates a scenario ID for each run.

## Setup

Activate the computer vision environment:

```sh
source .venv-cv/bin/activate
```

Start the frontend and API in a second terminal:

```sh
LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php
```

Open the Admin page and Cashier POS. The five-clip runs write to the main parking database and need the existing camera-event token. Check for an open session for the test plate before starting. See the [recorded business-rule guide](RECORDED_BUSINESS_RULE_RUNS.md) for the shared setup and [simulation guide](SECTIONAL_FIRST_SIMULATION.md) for token configuration.

## Shared business rule · Grace bypass

Both sets use the shared recorded grace-bypass runner. The snapshot supplies the observed vacancies for allocation. The parking replay identifies a bay that was vacant in the snapshot and stays occupied for the required hold. The departure replay then checks that observed bay. The run reports a failure if parking evidence is missing or more than one bay qualifies. The runner uses the entrance recognition result to open the session, or issues a QR-only entry when the plate is unreadable.

### Civic

Clips: `ZONE-A-NIGHT-SNAPSHOT-CIVIC.MOV` → `ENTRANCE-CIVIC-NIGHT.mov` → `PARK_CIVIC-NIGHTZoneA.MOV` → `EXITPARK_CIVIC-NIGHTZoneA.mov` → `EXIT-CIVIC-NIGHT.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone A \
  --baseline-video computer_vision/simulation_videos/ZONE-A-NIGHT-SNAPSHOT-CIVIC.MOV \
  --entry-video computer_vision/simulation_videos/ENTRANCE-CIVIC-NIGHT.mov \
  --parking-video computer_vision/simulation_videos/PARK_CIVIC-NIGHTZoneA.MOV \
  --departure-video computer_vision/simulation_videos/EXITPARK_CIVIC-NIGHTZoneA.mov \
  --exit-video computer_vision/simulation_videos/EXIT-CIVIC-NIGHT.mov
```

### Xpander

The snapshot filename uses `XPANER`.

Clips: `ZONE-A-NIGHT-SNAPSHOT-XPANER.MOV` → `ENTRANCE-XPANDER-NIGHT.mov` → `PARK_XPANDER-NIGHTZoneA.mov` → `EXITPARK_XPANDER-NIGHTZoneA.mov` → `EXIT-XPANDER-NIGHT.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone A \
  --baseline-video computer_vision/simulation_videos/ZONE-A-NIGHT-SNAPSHOT-XPANER.MOV \
  --entry-video computer_vision/simulation_videos/ENTRANCE-XPANDER-NIGHT.mov \
  --parking-video computer_vision/simulation_videos/PARK_XPANDER-NIGHTZoneA.mov \
  --departure-video computer_vision/simulation_videos/EXITPARK_XPANDER-NIGHTZoneA.mov \
  --exit-video computer_vision/simulation_videos/EXIT-XPANDER-NIGHT.mov
```

## What to verify

| Stage | Expected result |
| --- | --- |
| Snapshot | Record which bays the snapshot reports vacant, occupied, or unknown. |
| Entrance | Record the gate result, including an incorrect or unreadable plate. The runner creates a session from the plate it reads or issues a QR-only entry. Record both the assigned bay and the bay shown in the footage. |
| Parking | One snapshot-vacant bay remains occupied for the default 10 continuous video seconds. Compare it with the assigned bay. |
| Bay departure | The sectional pipeline reports the observed parking bay vacant and the runner advances to cashier handling. |
| Cashier and exit | Complete the grace bypass within 15 minutes of entry, then follow the runner's prompts. Record whether the exit matches the session plate, returns a different plate, or returns no read. Verify session closure and bay release when exit clears. |

For a walk-in eligible for Zone A, allocation uses observed vacancies. A non-VIP/PWD vehicle receives an eligible bay outside Zone A under the existing priority rules. Its recorded Zone A parking can raise an eligibility alert in **Admin → Violations Log**. A confirmed alert adds ₱200 and must be settled before exit. A rejected alert adds no fine. Vehicles already visible in the snapshot are treated as authorized for this simulation.

Score each gate against the plate visible in the footage. Record a wrong plate or no read as a recognition failure, even if staff completes the workflow using the issued entry QR. If exit reads a different plate from the entry session, the runner stops on the mismatch. If a sectional stage stops the run, record the failed stage and resolve any open session through the app before moving on. The scenario folder under `backend/database/simulation_runs/` contains `baseline_events.jsonl`, `sectional_events.jsonl`, `parking_occupancy_summary.json`, and `departure_events.jsonl` for the stages reached.

## Offline reference results

| Vehicle | Night gate replay after the recognition changes | Offline Zone A replay |
| --- | --- | --- |
| Civic | Entrance and exit confirmed `CAH9204`. | The snapshot and parking clips identified A-03 with about 13 seconds of continuous occupancy. Departure confirmed vacancy. An earlier sectional OCR pass read the target plate once, below the repeated-read threshold. |
| Xpander | Entrance and exit confirmed `DBF2489`. | The snapshot and parking clips identified A-02 with 18 seconds of continuous occupancy. Departure ended without confirming vacancy. An earlier sectional OCR pass did not confirm the target plate. |

The four confirmed night gate passes and the sectional observations came from offline replay. The five-clip cashier and database workflows above still need their own results. To repeat the gate checks independently of the sectional stages and database, run the offline gate regression suite. It also exercises the daytime Xpander exit.

```sh
RUN_NIGHT_LPR=1 python -m unittest discover -s tests -p test_lpr_regression.py
```
