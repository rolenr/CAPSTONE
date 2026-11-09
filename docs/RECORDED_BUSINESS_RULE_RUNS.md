# Recorded business-rule runs by zone

These commands are adapted from the attached run list for the **current** six Python entry points. Run them one at a time from the repository root. Each command generates its own scenario ID, so there is no need to supply `--scenario-id`. The old `--presentation-seconds 90` flag and countdown are no longer supported: grace starts at actual entrance, while the normal-fare and expired-tenant cases advance only the current test session’s effective time at the rule boundary.

```sh
source .venv-cv/bin/activate
```

Start the frontend/API in another terminal with `LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php`. Refresh the frontend during a run. Entry, payment, occupancy and exit changes use the main database and remain in the audit trail. Each zone uses its own `ZONE <letter> SNAPSHOT.mp4` before entrance.

The recorded bay is **D-02** for Zone D, even though its parking and departure filenames say `D-01`. A recorded target bay is accepted only after that zone’s snapshot sees it vacant. The entrance detector reads the plate from each video; no command supplies the expected plate. Treat any known plate as a result to verify after recognition, not as an input. The FD entrance clip may need the QR-only fallback if its plate remains unreadable. An unreadable entrance in the paid-reservation case needs that reservation’s QR via `--entry-qr-token TOKEN` so the booking can be linked. In a recorded Zone A run, a non-VIP vehicle is allocated the nearest available eligible bay, starting with Zone B. The Zone A clip still shows where it actually parks. After that clip confirms occupancy, Admin > Violations Log receives one VIP/PWD eligibility alert. Staff can confirm it for a ₱200 fine or reject it with no fine; the run proceeds through payment and exit. A paid Zone A reservation whose VIP status was revoked is reassigned to an eligible bay while retaining its payment and QR. Live entrance allocation also excludes non-VIP vehicles from Zone A.

The runner uses the detected entrance plate to create the session and then labels the sectional replay with that observed result. It never inserts a plate from these commands. Every successful entry prints and saves an issued QR, including readable-plate entries. For an unreadable exit plate, scan that issued token in Cashier POS; the internal `QR_ONLY_...` label is not an exit credential. Color is supporting evidence. Finish each exit and verify the slot is released before moving to the next command.

For the Zone A recorded simulation, treat every vehicle already visible in the pre-entry snapshot as VIP/PWD authorized. The snapshot records occupied bays without creating accounts, changing VIP flags, or issuing eligibility alerts for those existing vehicles. The priority check applies only to the newly entering vehicle after its own parking clip confirms occupancy.

## Grace bypass

Finish cashier grace handling and exit before starting the next run.

### Zone A · A-06

Clips: `ZONE A SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEA-06v1.mp4` → `EXITPARK_XPANDER-ZONEA-06v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone A
```

### Zone B · B-05

Clips: `ZONE B SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEB-05V1.mp4` → `EXITPARK_XPANDER-ZONEB-05v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone B
```

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `EXITPark_XPANDER-ZONEC-01v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone C
```

### Zone D · D-02

Clips: `ZONE D SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE D-01.mp4` → `EXIT FD ZONE D-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone D
```

### Zone E · E-01

Clips: `ZONE E SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE E-01.mp4` → `EXIT FD ZONE E-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/grace.py --zone E
```

## Normal fare

The runner advances only the identified session past the 15-minute grace boundary. Verify unpaid exit is denied, collect the fare, then complete exit.

### Zone A · A-06

Clips: `ZONE A SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEA-06v1.mp4` → `EXITPARK_XPANDER-ZONEA-06v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/normal_fare.py --zone A
```

### Zone B · B-05

Clips: `ZONE B SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEB-05V1.mp4` → `EXITPARK_XPANDER-ZONEB-05v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/normal_fare.py --zone B
```

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `EXITPark_XPANDER-ZONEC-01v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/normal_fare.py --zone C
```

### Zone D · D-02

Clips: `ZONE D SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE D-01.mp4` → `EXIT FD ZONE D-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/normal_fare.py --zone D
```

### Zone E · E-01

Clips: `ZONE E SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE E-01.mp4` → `EXIT FD ZONE E-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/normal_fare.py --zone E
```

## Paid reservation

Before each run, manually reserve and pay for the matching vehicle and bay in the frontend. Finish that session before making the next reservation.

### Zone A · A-06

Clips: `ZONE A SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEA-06v1.mp4` → `EXITPARK_XPANDER-ZONEA-06v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reservation.py --zone A
```

### Zone B · B-05

Clips: `ZONE B SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEB-05V1.mp4` → `EXITPARK_XPANDER-ZONEB-05v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reservation.py --zone B
```

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `EXITPark_XPANDER-ZONEC-01v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reservation.py --zone C
```

### Zone D · D-02

Clips: `ZONE D SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE D-01.mp4` → `EXIT FD ZONE D-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/reservation.py --zone D
```

### Zone E · E-01

Clips: `ZONE E SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE E-01.mp4` → `EXIT FD ZONE E-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/reservation.py --zone E
```

## Another vehicle’s reserved bay

Reserve the recorded target bay for a different registered vehicle. The entrant must have no active reservation. Review the wrong-slot alert in Admin before exit.

### Zone A · A-06

Clips: `ZONE A SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEA-06v1.mp4` → `EXITPARK_XPANDER-ZONEA-06v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reserved_slot.py --zone A
```

### Zone B · B-05

Clips: `ZONE B SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEB-05V1.mp4` → `EXITPARK_XPANDER-ZONEB-05v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reserved_slot.py --zone B
```

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `EXITPark_XPANDER-ZONEC-01v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/reserved_slot.py --zone C
```

### Zone D · D-02

Clips: `ZONE D SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE D-01.mp4` → `EXIT FD ZONE D-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/reserved_slot.py --zone D
```

### Zone E · E-01

Clips: `ZONE E SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE E-01.mp4` → `EXIT FD ZONE E-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/reserved_slot.py --zone E
```

## Tenant validation

Validate the active session in the Vendor portal when prompted. For an expired-window variant, add `--tenant-expired` to an individual command.

### Zone A · A-06

Clips: `ZONE A SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEA-06v1.mp4` → `EXITPARK_XPANDER-ZONEA-06v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/tenant.py --zone A
```

### Zone B · B-05

Clips: `ZONE B SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEB-05V1.mp4` → `EXITPARK_XPANDER-ZONEB-05v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/tenant.py --zone B
```

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `EXITPark_XPANDER-ZONEC-01v1.mp4` → `EXIT_XPANDER.mov`.

```sh
python computer_vision/recorded_test_cases/tenant.py --zone C
```

### Zone D · D-02

Clips: `ZONE D SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE D-01.mp4` → `EXIT FD ZONE D-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/tenant.py --zone D
```

### Zone E · E-01

Clips: `ZONE E SNAPSHOT.mp4` → `ENTRANCE_FD.mov` → `PARK FD ZONE E-01.mp4` → `EXIT FD ZONE E-01.mp4` → `EXIT_FD.mov`.

```sh
python computer_vision/recorded_test_cases/tenant.py --zone E
```

## Zone C driveway or double-parking violation

Review the video-backed alert in Admin, then use Cashier POS before exit. A confirmed alert adds a ₱200 fine to the cashier bill; a rejected alert adds no violation fee and the simulation still proceeds to the exit camera. Zone C bay recognition continues during this clip; the driveway region spans the visible lane, so the alert is based on a tracked vehicle remaining stationary in that region rather than a box placed on the expected car. The 20-second rule applies to all zones; Zone C is the only zone with a recorded violation clip for this run guide.

### Zone C · C-01

Clips: `ZONE C SNAPSHOT.mp4` → `ENTRANCE_XPANDER.mov` → `PARK_XPANDER-ZONEC-01v1.mp4` → `VIOLATION_XPANDER-ZONECv1.mp4` → `EXIT_XPANDER.mov`. After staff review and any required payment, this case skips the bay-departure clip and plays the exit camera feed.

```sh
python computer_vision/recorded_test_cases/enforcement.py --zone C
```

## Acceptance check

For every run, check the entrance session and observed color, assigned and observed bay, occupancy transitions, cashier or tenant decision, exit clearance, released slot, zone count, and Admin audit record. For Zone C enforcement, confirm the alert has continuous 20-second evidence in the restricted region before staff review.
