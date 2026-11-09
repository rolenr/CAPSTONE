# Reusable recorded simulation commands

There are six scenario commands—one per business rule. Pass the zone and optional clip overrides each time; the zone profile supplies the default clips and bay.

| Command | Zones | Use |
| --- | --- | --- |
| `grace.py` | A–E | Use the actual entrance timestamp and complete the ₱0 bypass within 15 minutes |
| `normal_fare.py` | A–E | Simulate parking past grace, collect the normal fare, then exit |
| `reservation.py` | A–E | Run the paired car and bay after manually creating its frontend reservation |
| `reserved_slot.py` | A–E | Run a walk-in against another vehicle’s reservation at the clip bay; review the wrong-slot alert before exit |
| `tenant.py` | A–E | Validate through the Vendor frontend and simulate the tenant departure window; add `--tenant-expired` to test expiry |
| `enforcement.py` | C only | Use the recorded Zone C violation clip to demonstrate the 20-second rule shared by all zones |

## Calibrate sectional regions

The region JSON files contain initial polygons around each labeled vehicle’s parked position. Review the preview and redraw the full bay boundary if the polygon does not match the recorded camera view. Run a case only after its baseline reports the target bay as vacant.

```sh
source .venv-cv/bin/activate
python computer_vision/calibrate_simulation_regions.py \
  --source computer_vision/simulation_videos/PARK_XPANDER-ZONEA-06v1.mp4 \
  --camera-id simulation_zone_a \
  --slot A-06 --at-seconds 25 \
  --output computer_vision/recorded_test_cases/regions/zone_a.json
```

The other target bays are B-05, C-01, D-02, and E-01. Use each matching parking clip, camera ID, and `zone_<letter>.json` file. To calibrate Zone C’s driveway or double-parking area, inspect the clip and draw the actual restricted region:

```sh
python computer_vision/calibrate_simulation_regions.py \
  --source computer_vision/simulation_videos/VIOLATION_XPANDER-ZONECv1.mp4 \
  --camera-id simulation_zone_c_violation \
  --restricted-area driveway --at-seconds 15 \
  --output computer_vision/recorded_test_cases/regions/zone_c_violation.json
```

Click polygon vertices, then press Enter to save; `r` redraws and `q` cancels. A restricted area named with the `double` prefix is treated as double parking; `driveway` is treated as driveway obstruction.

## Run a case

The runner uses the main database and regular frontend at `http://127.0.0.1:8080/frontend/`. Refresh the page during the run to see changes. Sessions, payments, alerts, audit records, and observed occupancy persist after Python exits. Before each recorded zone run, the runner replays only that zone's snapshot. You can sync all zone snapshots separately with `python computer_vision/recorded_test_cases/sync_snapshots.py`.

Snapshot coverage is reported per zone. The current maps define A: 9, B: 7, C: 6, D: 7, and E: 10 active bays. The runner evaluates the entire selected zone before acknowledging the recorded target bay; it proceeds to entrance only if that bay was observed vacant. The B–E bay-ID ordering should be checked against physical site markings before operational use. A snapshot cannot release a bay with an open session; resolve the session through the normal exit or admin recovery flow. The persistent snapshot summary appears in Admin audit logs.

For Zone A recorded runs, vehicles already parked in the pre-entry snapshot are assumed VIP/PWD authorized. Their occupied bays are recorded, but the simulation does not create accounts or change VIP flags for them. Only the newly entering vehicle is checked for priority eligibility after its parking clip confirms occupancy.

You may keep the regular app running independently:

```sh
LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php
```

From another terminal at the repository root, run one scenario command and choose its zone. `--plate` is optional; when supplied, it must be the actual normalized plate from that car’s entrance clip.

```sh
python computer_vision/recorded_test_cases/grace.py --zone A --plate YOURPLATE
```

The default zone profiles use `ZONE A SNAPSHOT.mp4` through `ZONE E SNAPSHOT.mp4` for the first three video seconds of the pre-entry baseline. They pair XPANDER with A-06, B-05, and C-01, and FD with D-02 and E-01. Parking replay starts at the beginning of its separate parking clip. In Zone D, the already parked white SUV is D-01; the recorded FD parks in D-02 even though the clip filename still says D-01. The departure stage starts from the parking clip’s confirmed occupied state and publishes only its target bay’s vacancy. Override any recording or sectional map when needed. For example:

```sh
python computer_vision/recorded_test_cases/normal_fare.py \
  --zone A --plate YOURPLATE \
  --entry-video computer_vision/simulation_videos/ENTRANCE_XPANDER.mov \
  --baseline-video path/to/baseline.mp4 \
  --parking-video path/to/parking.mp4 \
  --departure-video path/to/departure.mp4 \
  --exit-video computer_vision/simulation_videos/EXIT_XPANDER.mov \
  --clip-slot A-06 --camera-id simulation_zone_a \
  --regions computer_vision/recorded_test_cases/regions/zone_a.json
```

The presentation order matches the existing runner: **selected zone sectional snapshot → entrance and allocation → parking clip → bay departure clip → payment or tenant action → exit camera**. Feed previews and manual stage controls are enabled by default. Grace begins at entrance, with no presentation countdown. Normal fare advances only the identified test session past 15 minutes; the expired tenant case advances its validation time past 20 minutes. The active tenant case uses the actual validation time. The simulation clock adjustments remain in the main database audit log.

During parking replay the sectional detector reads plates from vehicle crops inside calibrated bays and records a plate only after repeated, sufficiently confident OCR results. It also aggregates simple vehicle color observations. A clip label identifies the staged vehicle but is not reported as camera OCR; a distant or unreadable plate remains unknown.

## Case prerequisites

- Clear or recover any open session for the test plate before starting. In recorded Zone A runs, a non-VIP XPANDER is assigned the nearest available eligible bay, starting in Zone B. The Zone A parking clip then records where it actually parked and creates a VIP/PWD staff review alert after confirmation. A confirmed alert adds a ₱200 fine, and a rejected alert adds none. Zone A frontend reservations require VIP/PWD status unless an admin overrides the restriction; a paid reservation is reassigned at entry if that status has since been revoked. Zone E reservations use standard eligibility.
- Before `reservation.py`, manually create and pay or admin-exempt a reservation for the test plate and exact bay in the frontend.
- Before `reserved_slot.py`, reserve the clip bay for a different registered vehicle and leave the test car without a reservation. The simulation allocates that car to another database-vacant bay in the same zone, then the sectional clip records the car in the reserved bay. Admin review is required before exit.
- Use `--tenant-expired` on `tenant.py` for the expired 20-minute window path.
- `enforcement.py` runs only in Zone C because that is the only zone with a recorded violation clip. The 20-second driveway/double-parking rule and separate bay/roadway analysis apply to all zones with calibrated regions. The Zone C violation feed retains the same six bay polygons and plate/color observation; a separate polygon covers the visible driveway across the zone. A vehicle grounded in that driveway is excluded from bay occupancy while it obstructs the lane. The 35-second recorded clip produces one review alert at about 23 video seconds. Confirmed Admin review creates a ₱200 fine; rejected alerts carry no fine and the run continues. Use Cashier POS for either decision, then replay the exit camera directly; this case skips the bay-departure clip. Review each camera preview and site markings before relying on the A–E roadway polygons for unattended enforcement.

Every successful entrance, including one with a readable plate, receives an issued entry QR. The simulation prints its scannable QR and token and saves a QR PNG. If the exit plate is unreadable, scan that **issued entry QR as the only fallback credential**. Vehicle color supports staff review and cannot authorize exit. Event logs and evidence are saved under `backend/database/simulation_runs/<scenario-id>/`.

If the entrance camera cannot confirm a plate, the recorded walk-in cases create a QR-only session. The terminal prints the scannable entry QR, its token, assigned bay, and observed entrance color; it also saves a QR PNG under that run's evidence directory. Keep the terminal QR or token for Cashier POS: after settling any fee, scan it in **Fallback: Entry QR Token** to record exit. The exit camera still plays for presentation, but its plate reading cannot authorize a QR-only session. An unknown entrance color is recorded as `UNKNOWN` and does not block QR clearance.

For a manually paid reservation whose entrance plate is unreadable, pass its issued reservation QR with `--entry-qr-token TOKEN` so the entry can be linked to the reserved bay. In live VIGI preview, press `u` while exactly one vehicle is visible to issue a QR-only entry when its plate cannot be read; the QR and token print in that detector's terminal.
