# Parking Ltd. User Stories and Tasks

This backlog translates the project evaluation into user-focused work items. Task IDs use `TASK<story>.<sequence>`; for example, `TASK01.1` is the first task under US-01. Status reflects the repository as reviewed on 2026-10-27. Verification notes distinguish isolated API checks from browser or physical-site testing.

## Scope decisions

- Use recorded video feeds for the next phase of computer-vision integration and simulation.
- Run the recorded-video scenarios against the existing `backend/database/parking.db`; preserve its data between runs and prevent duplicate state changes.
- Defer physical VIGI camera and drone feed integration until a later phase.
- The guard operates the existing barrier buttons. The application reports exit eligibility and records departure after staff confirmation. Barrier hardware integration is outside the project scope.
- Confirm zone allocation policy with the adviser before changing zone behavior. The proposal describes Zone A as VIP/PWD priority and Zones B–E as standard reservation zones, but conflicts about the overflow area (Area E vs. Zone F).

## Backlog

### US-01 — Check whether a vehicle may exit

**As a cashier or guard, I want to look up an exiting vehicle by plate or QR token and see a clear clearance decision, so that I can open the barrier manually only when the vehicle is eligible to leave.**

**Status:** Implemented in code; clearance and departure API behavior passed isolated PHP CLI checks. The browser workflow remains to be evaluated with the project database. Clearance decisions are audited, and an authorized cashier/admin can confirm departure after the guard confirms the vehicle passed.

**Confirmed clearance policy**

- Permit exit for `PAID`, `BYPASSED`, `PAID_OVERNIGHT`, and `PAID_RESERVATION` on an active parking session.
- Permit `PAID_VENDOR` only during its 20-minute departure window. After the window expires, the vehicle must be settled by the cashier before it can be cleared.
- The primary lookup is an exact plate match to an active parked session. If it cannot identify the active session, deny that attempt and offer QR fallback.
- QR fallback accepts only the session's issued entry QR token. It must not accept a plate number or a session database ID as a substitute token. It applies the same payment-status and active-session checks.
- A successful lookup returns a clearance decision for the guard. It does not operate the physical barrier or mean that the vehicle has departed.

**User story acceptance criteria**

- A successful lookup displays **Cleared to Exit** and the payment status that cleared the vehicle.
- An unsuccessful lookup displays **Not Cleared** with a useful reason.
- Only the confirmed payment statuses and active vendor window allow clearance.
- A plate mismatch cannot be cleared by the plate path; QR fallback requires the issued entry token.
- The interface states that the guard operates the physical barrier manually.
- Each decision records the time, operator, lookup method, and outcome in the audit trail.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK01.1** | Confirm the allowed payment statuses and plate/QR fallback rules. | [x] Done | This story documents the allowed statuses, vendor time limit, exact plate lookup, and issued-token-only fallback policy. |
| **TASK01.2** | Update `exit_barrier_clearance` to return a clearance decision without implying hardware actuation. | [x] Done | The API returns `CLEARED_TO_EXIT` or `NOT_CLEARED`; it does not actuate hardware, close the session, or free the slot. |
| **TASK01.3** | Update the cashier screen to show the clearance result and remove simulated barrier actuation controls. | [x] Done | The screen shows **Cleared to Exit** or **Not Cleared**, states that the guard operates the barrier, and has no barrier-opening animation or force-open control. |
| **TASK01.4** | Audit denied and approved clearance attempts. | [x] Done | Each attempt records operator, time, lookup method, outcome, and status/reason; a denied attempt is visible in the audit log. |
| **TASK01.5** | Verify unpaid, paid, bypassed, vendor, overnight, prepaid, mismatch, and unknown-token cases. | [x] Done | The verification record below shows each case returned the expected decision, logged an audit row, and left the session parked. |
| **TASK01.6** | Add staff confirmation after the vehicle passes to record departure and free its slot. | [x] Done | Only an authorized staff confirmation after a valid clearance marks the session `EXITED`, records exit time/method, frees the slot, updates zone occupancy, and writes an audit entry. |

**TASK01.5 verification record — 2026-10-27**

Ran the current clearance endpoint through a PHP CLI harness using an isolated temporary copy of the endpoint and a temporary SQLite database initialized from `backend/database/Schema.sql`. No project database, web app, or computer-vision simulation was used. The harness made authenticated cashier requests with CSRF tokens and checked the returned decision, audit row count, session state, and that raw QR tokens were not written to audit details.

| Case | Expected result | Result |
| --- | --- | --- |
| `PAID` by plate | Cleared | Passed |
| `BYPASSED` by issued QR token | Cleared | Passed |
| `PAID_OVERNIGHT` by plate | Cleared | Passed |
| `PAID_RESERVATION` by issued QR token | Cleared | Passed |
| `PAID_VENDOR` within 20 minutes | Cleared | Passed |
| `UNPAID` by plate | Not cleared | Passed |
| `PAID_VENDOR` after 21 minutes | Not cleared | Passed |
| Plate not matching an active session | Not cleared | Passed |
| Unknown QR token | Not cleared | Passed |
| Session ID supplied as a QR token | Not cleared | Passed |

**Checks across all 10 cases:** 10 audit rows created; all 10 sessions remained `PARKED` with no exit time; no raw QR token appeared in audit details.

**TASK01.6 verification record — 2026-10-27**

Ran the departure-confirmation endpoint against an isolated temporary SQLite database using the current endpoint code. No project database, browser session, or physical barrier was used.

| Case | Expected result | Result |
| --- | --- | --- |
| Authorized cashier confirms after approved clearance | Record departure | Passed |
| Confirmation without an approved clearance | Deny; keep session parked | Passed |
| Latest clearance attempt is denied | Deny; keep session parked | Passed |
| Vendor departure window expires before confirmation | Deny; keep session parked | Passed |
| Driver role attempts to confirm departure | Deny | Passed |

**Successful confirmation checks:** session changed to `EXITED`; exit time and method were recorded; the occupied slot was freed; zone occupancy decreased; and a departure audit entry was written. Failed confirmations left the session parked and did not release its slot.

### US-02 — Process recorded video using project data

**As a project evaluator, I want the vision pipeline to accept selected recorded videos, so that entry and exit behavior can be evaluated using the application database before real cameras are available.**

**Status:** Partially implemented. Scenario scripts accept recorded video files and use the project database. Replay protection and application event integration remain open.

**User story acceptance criteria**

- Each scenario documents its input video, expected detections, and expected database outcome.
- A video can be selected through configuration rather than editing a hard-coded path.
- The script reports detection/OCR results and errors clearly, including when no plate is found.
- Replaying a video does not silently create duplicate sessions or release an already exited vehicle's slot again.
- The recorded-video scenarios run without physical camera hardware.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK02.1** | Inventory recorded videos and map each to an entry, exit, reservation, or occupancy scenario. | [x] Done | The inventory below lists each video, its current/default script use, expected input, and intended outcome, while identifying unassigned assets and missing occupancy footage. |
| **TASK02.2** | Replace hard-coded video paths with command-line options or configuration. | [x] Done | Scenario scripts accept explicit video paths, retain repository video defaults, and stop with an actionable error if a selected file does not exist. |
| **TASK02.3** | Define a stable detection event format (event type, timestamp, plate/slot, confidence, source video/frame). | [x] Done | The versioned JSON Schema and examples in [DETECTION_EVENT_SCHEMA.md](DETECTION_EVENT_SCHEMA.md) define required fields, types, and validation rules for plate and occupancy event types. |
| **TASK02.4** | Prevent duplicate state changes when recorded video is replayed against the project database. | [ ] To do | Replaying an entrance detection does not create another active session for the same vehicle; repeated exit detections do not close or release a session twice. |
| **TASK02.5** | Document setup and expected outputs without claiming live-camera support. | [x] Done | [RECORDED_VIDEO_DEMO.md](RECORDED_VIDEO_DEMO.md) documents dependencies, commands, project database effects, expected behavior, prerequisites, and recorded-video limitations without claiming live-camera support. |

**TASK02.1 video and scenario inventory — 2026-10-27**

Inventory is based on repository filenames and the current script configuration. The clips were not played and the simulations were not run, so the outcomes below are expected behavior from the script logic, not verified detections or confirmed clip contents.

| Video | Intended scenario / expected input | Expected outcome | Current script use |
| --- | --- | --- | --- |
| `computer_vision/simulation_videos/Entry Fortuner - Day (walk-in).mp4` | Daytime walk-in arrival at entrance; plate should be readable by the entrance model. | `scenario3.py` should identify the plate, choose an available slot, create a parking session, and mark the slot occupied. | Default entry clip for Scenario 3. |
| `computer_vision/simulation_videos/Entry Fortuner -Day2 (walk-in).mp4` | Alternate daytime walk-in entrance clip. | Expected to exercise the same plate recognition and walk-in slot assignment as the other Fortuner clip. | Not referenced by current scripts. |
| `computer_vision/simulation_videos/nissanExit.mp4` | Vehicle approaching the exit; exit model should read its plate. | `scenario4.py` and `scenario5.py` look for a matching active parked session, check payment status, and record departure only after guard confirmation. They access SQLite directly rather than using the US-01 clearance API. | Default exit clip for Scenarios 4 and 5. |
| `computer_vision/simulation_videos/mitsubishiExit.mp4` | Alternate exit vehicle clip; expected to test exit plate recognition and session matching. | Should identify a plate and evaluate the matching session's exit eligibility under the selected scenario. | Not referenced by current scripts. |
| `computer_vision/simulation_videos/RegularCustomer - Reservation (Entry and Exit).mp4` | Regular customer with an active reservation arriving at entrance. | Scenario 5 should recognize the plate, use the existing reservation's slot, mark the reservation fulfilled, and create an occupied parking session; its exit phase then uses `nissanExit.mp4`. | Default entry clip for Scenario 5. Despite the filename, this clip is not passed to the exit-processing phase. |
| `computer_vision/simulation_videos/VIP -Reservation (Entry and Exit).mp4` | VIP reservation arrival/departure scenario. | Intended to validate recognition and use of a VIP-reserved slot through entry and exit. Exact expected policy/result remains unverified; the current Scenario 5 flow does not select this clip or implement a separate VIP path. | Not referenced by current scripts. |

**Coverage and cautions:** No occupancy-only video or occupancy processing scenario was found in `computer_vision/simulation_videos/`. The current scripts cover entry/exit plate recognition and basic reservation/slot assignment, but do not process sectional bay occupancy. The `video dump` subdirectory contains no files. Scenarios 3 and 5 write sessions and slot state directly to the project database; Scenarios 4 and 5 can close sessions after payment clearance and guard confirmation. Repeated entry detections are not deduplicated.

**TASK02.2 usage**

Each scenario keeps its existing repository video as the default. Supply another existing clip at launch; paths may be absolute or relative to the current working directory:

```text
python computer_vision/scenario3.py --entry-video "/path/to/entry clip.mp4"
python computer_vision/scenario4.py --exit-video "/path/to/exit clip.mp4"
python computer_vision/scenario5.py --entry-video "/path/to/entry clip.mp4" --exit-video "/path/to/exit clip.mp4"
```

All requested video files are checked before opening the database or loading models. A missing file prints the invalid option, resolved path, and corrected usage hint. The video selection task was checked with Python syntax compilation, help output, and missing-path handling; no video was processed.

**TASK02.4 replay handling**

All three scenarios use `backend/database/parking.db`, the same database as the web application. Replaying an entrance clip can create a second active session, so duplicate-event handling remains open. Review existing sessions before repeating an entry scenario.

### US-03 — Send vision detections into the application

**As an operator, I want detections from recorded-video processing to update the application through a defined interface, so that the web portal reflects the selected footage and the resulting parking records.**

**Status:** Not integrated end to end.

**User story acceptance criteria**

- The computer-vision process submits events through a documented backend interface.
- The backend validates event type and required values before changing data.
- Entry events create or update the correct parking session; exit detections request a clearance decision.
- Occupancy events update the appropriate slot or zone state with source and timestamp.
- Failed or malformed events do not corrupt sessions or occupancy records.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK03.1** | Choose the local integration method (authenticated API endpoint or local event queue). | [ ] To do | The selected method, trust boundary, and local setup are documented for the application. |
| **TASK03.2** | Apply the existing [detection event schema](DETECTION_EVENT_SCHEMA.md) at the integration boundary. | [ ] To do | Incoming plate and occupancy events are validated against the versioned contract before they change project data. |
| **TASK03.3** | Add server-side idempotency and audit logging for accepted events. | [ ] To do | The same source event cannot duplicate state changes, and accepted events can be traced to their source and result. |
| **TASK03.4** | Connect scenario scripts to the integration interface. | [ ] To do | A recorded-video scenario sends events through the chosen interface and receives a success/error response. |
| **TASK03.5** | Display event time/source and stale-data state in relevant pages. | [ ] To do | The map or dashboard shows the latest update time/source and visibly marks data stale or unavailable. |

### US-04 — See and assign sectional occupancy

**As a customer or parking operator, I want the map and allocation logic to reflect occupancy by section, so that customers can find spaces and the system can direct vehicles appropriately.**

**Status:** Partial. The map and heatmap exist; model artifacts cover 40 bays in Zones A–E; a complete model-to-slot update and allocation rules engine are not established.

**User story acceptance criteria**

- Each detected bay maps unambiguously to a registered slot.
- Occupied, vacant, reserved, and unavailable states are distinguishable.
- API and UI totals agree with slot-level states.
- Allocation follows the zone policy approved for the project.
- Zone F behavior is defined as overflow/manual-only if that remains the agreed policy.
- The UI identifies stale or unavailable vision data instead of presenting it as live.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK04.1** | Confirm bay labels and slot mapping between model classes and `parking_slots`. | [ ] To do | Every model class maps to one documented database slot, or is explicitly marked unmapped. |
| **TASK04.2** | Implement occupancy event persistence and synchronize zone capacity counters. | [ ] To do | A valid occupancy event updates the correct slot and zone totals consistently; rejected events leave both unchanged. |
| **TASK04.3** | Agree on regular, VIP/PWD, reservation, and overflow allocation rules. | [ ] To do | The approved policy resolves Zone A eligibility and Zone F/overflow behavior and is recorded for implementation. |
| **TASK04.4** | Implement the rules in one backend service and return the assignment reason. | [ ] To do | A server-side allocation response includes the assigned slot/zone and a rule-based reason. |
| **TASK04.5** | Update the customer map and admin heatmap to show state and data freshness. | [ ] To do | Both views show matching slot states and identify stale/unavailable occupancy data. |

### US-05 — Apply consistent reservation rules and status

**As a customer, I want to reserve an eligible space under clear rules and know whether my booking is paid and confirmed, so that I can rely on the QR pass.**

**Status:** Partial. Reservation creation, slot assignment, date limits, fee calculation, and a QR token exist. The flow does not show payment confirmation before issuing the pass, and current zone restrictions conflict with the proposal.

**User story acceptance criteria**

- The implemented zone policy matches the adviser-approved policy across UI, API, and map.
- A booking is not described as paid until payment is recorded.
- The customer can distinguish pending payment, confirmed, expired, and cancelled reservations.
- Expired or cancelled reservations do not consume a slot.
- Date, duration, and one-active-booking rules are enforced by the server.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK05.1** | Decide whether online payment is in scope; otherwise label reservations as unpaid until cashier settlement. | [ ] To do | The scope decision is documented and UI/API statuses accurately distinguish unpaid from paid bookings. |
| **TASK05.2** | Confirm Zone A VIP/PWD and Zones B–E standard reservation policy, plus Zone F overflow behavior. | [ ] To do | Adviser-approved rules are recorded, including the overflow zone, with no conflicting zone definitions. |
| **TASK05.3** | Align the reservation form, server validation, map labels, and admin account privileges. | [ ] To do | All four components enforce and display the same approved eligibility and zone rules. |
| **TASK05.4** | Store reservation fee and payment state explicitly if required by the chosen flow. | [ ] To do | The database/API can distinguish the quoted fee from confirmed payment and expose the state to the customer. |
| **TASK05.5** | Review expiry, cancellation, replacement, and slot-release behavior. | [ ] To do | Each lifecycle transition has a defined state change and releases/reassigns capacity exactly once. |

### US-06 — Review and settle customer violations

**As a customer, I want to see outstanding violations linked to my vehicle and understand how they can be settled, so that I can resolve them before leaving.**

**Status:** Partial. Violation lookup and cashier/admin settlement paths exist. Customer online settlement is described in the proposal but was not found in the current flow.

**User story acceptance criteria**

- A customer sees only violations belonging to their registered vehicle/account.
- Each item shows type, amount, status, issue date, and settlement route.
- A violation is marked paid only after valid payment or authorized cashier action.
- Settlement is auditable and cannot be applied twice.
- Exit policy clearly states whether unpaid violations block clearance.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK06.1** | Decide whether customer online payment is in scope or settlement remains cashier-only. | [ ] To do | The decision and resulting customer-facing settlement instructions are documented. |
| **TASK06.2** | Confirm whether unpaid violations block exit and implement the rule consistently. | [ ] To do | The approved rule is applied identically by clearance logic and cashier/customer messaging. |
| **TASK06.3** | Ensure customer lookup is ownership-scoped and has a clear empty state. | [ ] To do | A customer cannot retrieve another account's violations and receives a clear result when none are outstanding. |
| **TASK06.4** | Record settlement method, amount, operator/provider, and timestamp. | [ ] To do | Each settled violation has auditable settlement details associated with the correct payment action. |
| **TASK06.5** | Add duplicate-settlement protection. | [ ] To do | Repeated settlement requests cannot charge or mark the same violation paid more than once. |

### US-07 — Reproduce and document the occupancy model

**As a developer, I want the model, dataset configuration, class mapping, and inference steps documented and available, so that another team member can reproduce occupancy inference on recorded footage.**

**Status:** Partial. Model weights and training-result artifacts are present. The recorded training configuration points to a dataset path outside this repository.

**User story acceptance criteria**

- Dataset/configuration is available or documented as an external dependency with access instructions.
- Class names map to physical bay identifiers and database slots.
- Model architecture, dependencies, input size, confidence threshold, and inference command are documented.
- Training metrics are identified as recorded validation metrics, not guaranteed field accuracy.
- Model files have a documented version-control policy.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK07.1** | Locate and preserve the dataset YAML, class names, and split definition. | [ ] To do | The training configuration and class list are available in the repository or in documented, retrievable storage. |
| **TASK07.2** | Document model provenance and reproducible inference steps. | [ ] To do | A developer can identify the model version, dependencies, settings, and command needed for inference. |
| **TASK07.3** | Add the model-to-slot class mapping. | [ ] To do | Every model class has a verified physical/database slot mapping or an explicit exclusion reason. |
| **TASK07.4** | Decide which model artifacts belong in Git. | [ ] To do | A documented version-control policy identifies the model weights and training artifacts needed to reproduce inference. |
| **TASK07.5** | Update the tracker so completion claims match repository evidence. | [ ] To do | Tracker statuses distinguish implemented code, recorded artifacts, untested behavior, and deferred integration. |

### US-08 — Align project documentation with the implementation

**As a capstone team member, I want the proposal, tracker, and implementation notes to describe the same system behavior, so that development and evaluation use one agreed scope.**

**Status:** Needed. The tracker contains outdated completion claims, and the proposal describes Python/Tesseract while the current API is PHP and the vision scripts use EasyOCR.

**User story acceptance criteria**

- The tracker distinguishes implemented UI and recorded-video behavior from later camera integration.
- The chosen backend and OCR stack are documented, with proposal deviations explained.
- Zone A, Zones B–E, and overflow behavior are consistent across documents and code.
- Current scope defers real camera feeds and uses manual barrier operation.

| Task ID | Task | Status | Task acceptance criteria |
| --- | --- | --- | --- |
| **TASK08.1** | Update `PROJECT_TRACKER.md` to qualify claims of physical barrier actuation and live camera telemetry. | [x] Done | Tracker describes exit clearance as a decision, barriers as manually operated, and vision feeds as recorded footage unless verified live. |
| **TASK08.2** | Reconcile Python/Tesseract proposal requirements with PHP/EasyOCR implementation or document an approved deviation. | [ ] To do | Proposal alignment decision is recorded and the architecture/dependencies documentation matches the chosen implementation. |
| **TASK08.3** | Record final zone and overflow policy after adviser confirmation. | [ ] To do | The approved zone policy is consistent in the proposal notes, tracker, backlog, UI, and backend. |
| **TASK08.4** | Keep this backlog and implementation tracker synchronized as stories are completed. | [ ] To do | Each completed task updates its status and the tracker reflects the shipped implementation without stale claims. |
| **TASK08.5** | Document how the shared project database is managed during development and recorded-video replays. | [ ] To do | The team knows which database changes are runtime data, how to preserve needed records, and when a database change belongs in Git. |

## Deferred work

These items are intentionally outside the current implementation phase:

- Real camera integration, including VIGI entrance/exit cameras and drone feeds.
