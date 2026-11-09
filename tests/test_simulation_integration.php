<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/api/simulation_integration.php';
require_once __DIR__ . '/../backend/api/exit_departure.php';

function isExitPaymentCleared($paymentStatus, $vendorScanTime = null): bool {
    return in_array($paymentStatus,['PAID','BYPASSED','PAID_OVERNIGHT','PAID_RESERVATION'],true);
}

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function eventData(string $scenario, int $session, string $slot, string $plate, int $frame, string $state='OCCUPIED'): array {
    return ['event_id'=>sprintf('00000000-0000-4000-8000-%012d',$frame),
        'scenario_id'=>$scenario,'session_id'=>$session,'camera_id'=>'section_d_sample1',
        'event_type'=>'sectional.slot.observed','frame_index'=>$frame,
        'timestamp'=>gmdate('c'),'slot_number'=>$slot,'occupancy_state'=>$state,
        'clip_plate'=>$plate,'clip_slot'=>$slot,'source'=>['frame_index'=>$frame]];
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$db->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('D',2),('F',2)");
$db->exec("INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES('D-05','D','Standard'),('D-06','D','Standard'),('F-01','F','Standard')");
$db->exec("INSERT INTO vehicles(plate_number,vehicle_type) VALUES('LAC7863','Transient')");
$db->exec("INSERT INTO reservations(vehicle_id,slot_id,status,payment_state,token_id) VALUES(1,1,'ACTIVE','PAID','QR_TEST_ENTRY')");
$entry = simulationEntry($db,'LAC7863','recorded1','TEST','', 'WHITE', .90);
check($entry['slot_number']==='D-05' && $entry['payment_status']==='PAID_RESERVATION','paid reservation assignment');
check($entry['vehicle_color']==='WHITE' && $entry['entry_token']==='QR_TEST_ENTRY','entrance color and QR association');
check($db->query("SELECT vehicle_color FROM parking_sessions WHERE session_id={$entry['session_id']}")->fetchColumn()==='WHITE','entry color persisted');
check(strongColorMismatch('WHITE',.95,'BLACK',.95),'clear color mismatch flagged');
check(!strongColorMismatch('WHITE',.60,'BLACK',.95),'uncertain color cannot block');
check(simulationEntry($db,'LAC7863','recorded1','TEST')['duplicate']===true,'duplicate entry');
check((int)$db->query("SELECT active_parked_count FROM spatial_allocation WHERE zone_id='D'")->fetchColumn()===1,'zone count on entry');
$id = (int)$entry['session_id'];
$foreign = eventData('unrelated',$id,'D-05','LAC7863',2);
try { simulationSectionalEvent($db,$foreign); throw new RuntimeException('cross-scenario binding accepted'); }
catch (RuntimeException $expected) { check($expected->getCode()===404,'cross-scenario binding denial'); }
$first = eventData('recorded1',$id,'D-05','LAC7863',3);
check(simulationSectionalEvent($db,$first)['result']==='CLIP_BOUND','matching clip bound to session');
check(simulationSectionalEvent($db,$first)['duplicate']===true,'event idempotency');
check(simulationSectionalEvent($db,eventData('recorded1',$id,'D-09','LAC7863',8))['result']==='INACTIVE_SLOT_IGNORED','retired bay ignored');
check(simulationSectionalEvent($db,eventData('recorded1',$id,'D-05','ZZZ9999',4))['result']==='IDENTITY_UNKNOWN','unrelated clip identity');
check((int)$db->query('SELECT COUNT(*) FROM sectional_alerts')->fetchColumn()===0,'unrelated clip raised no alert');
$wrong = eventData('recorded1',$id,'D-06','LAC7863',5);
check(simulationSectionalEvent($db,$wrong)['result']==='WRONG_SLOT','wrong-slot review');
check((int)$db->query('SELECT COUNT(*) FROM sectional_alerts')->fetchColumn()===1,'wrong-slot alert');
$wrongAlertId = (int)$db->query("SELECT alert_id FROM sectional_alerts WHERE violation_type='WRONG_SLOT'")->fetchColumn();
check(simulationReviewAlert($db,$wrongAlertId,'REJECTED',null,'staff@example.test')['violation_id']===null,
    'rejected wrong-slot alert has no fine');
check((int)$db->query('SELECT COUNT(*) FROM violations')->fetchColumn()===0,'rejected alert issued no fine');
check(simulationSectionalEvent($db,eventData('recorded1',$id,'D-05','LAC7863',6,'VACANT'))['result']==='OBSERVED','vacancy transition');
$colorMismatch = eventData('recorded1',$id,'D-05','LAC7863',9);
$colorMismatch['vehicle_color'] = 'BLACK';
$colorMismatch['color_confidence'] = .96;
check(simulationSectionalEvent($db,$colorMismatch)['result']==='COLOR_REVIEW','strong sectional color mismatch sent to review');
check((int)$db->query("SELECT COUNT(*) FROM violations")->fetchColumn()===0,'color review does not issue an automatic fine');
$plateUpdate = eventData('recorded1',$id,'D-05','LAC7863',10);
$plateUpdate['recognized_plate'] = 'LAC7863';
check(simulationSectionalEvent($db,$plateUpdate)['result']==='MATCH','late confirmed sectional plate matches session');
check($db->query("SELECT recognized_plate FROM slot_observations WHERE slot_id=1 AND scenario_id='recorded1'")->fetchColumn()==='LAC7863',
    'sectional plate persisted with bay observation');
$overstay = ['event_id'=>'00000000-0000-4000-8000-000000000007','scenario_id'=>'recorded1',
    'session_id'=>$id,'camera_id'=>'section_d_sample1','event_type'=>'parking.restricted.overstay',
    'frame_index'=>20,'timestamp'=>gmdate('c'),'violation_type'=>'DRIVEWAY',
    'duration_seconds'=>19,'region'=>'driveway','track_id'=>'track1'];
try { simulationSectionalEvent($db,$overstay); throw new RuntimeException('19-second alert accepted'); }
catch (InvalidArgumentException $expected) {}
$overstay['duration_seconds']=20;
check(simulationSectionalEvent($db,$overstay)['result']==='PENDING_REVIEW','20-second alert');
check((int)$db->query('SELECT COUNT(*) FROM violations')->fetchColumn()===0,'no automatic fine');
$alertId = (int)$db->query("SELECT alert_id FROM sectional_alerts WHERE violation_type='DRIVEWAY'")->fetchColumn();
check(simulationReviewAlert($db,$alertId,'CONFIRMED',null,'staff@example.test')['violation_id']>0,'staff fine');
check((float)$db->query('SELECT penalty_amount FROM violations')->fetchColumn()===200.0,'fine amount');
$colorAlertId = (int)$db->query("SELECT alert_id FROM sectional_alerts WHERE violation_type='COLOR_REVIEW'")->fetchColumn();
check(simulationReviewAlert($db,$colorAlertId,'CONFIRMED',null,'staff@example.test')['violation_id']>0,
    'confirmed color alert has a fine');
check((int)$db->query('SELECT COUNT(*) FROM violations WHERE penalty_amount=200 AND status=\'UNPAID\'')->fetchColumn()===2,
    'each distinct confirmed alert has a 200-peso fine');
try { recordExitDeparture($db,$id,'ALPR','TEST'); throw new RuntimeException('exit with unpaid fine accepted'); }
catch (RuntimeException $expected) { check($expected->getCode()===409,'unpaid fine exit denial'); }
$db->exec("UPDATE violations SET status='PAID'");
check(recordExitDeparture($db,$id,'ALPR','TEST')['departure_recorded']===true,'paid departure');
check((int)$db->query("SELECT active_parked_count FROM spatial_allocation WHERE zone_id='D'")->fetchColumn()===0,'zone count after departure');
check((int)$db->query("SELECT is_occupied FROM parking_slots WHERE slot_number='D-05'")->fetchColumn()===0,'slot released');

$liveDb = new PDO('sqlite::memory:');
$liveDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$liveDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$liveDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('B',1);
    INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES('B-01','B','Standard')");
$liveEntry = simulationEntry($liveDb,'ABC1234','live-gate-test','ENTRANCE_CAMERA_CHANNEL_1');
check($liveEntry['slot_number']==='B-01' && $liveEntry['entry_token']!=='' && $liveEntry['payment_status']==='UNPAID',
    'live gate allocates from database vacancy without sectional observations and issues an entry QR');

$priorityDb = new PDO('sqlite::memory:');
$priorityDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$priorityDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$priorityDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('A',2),('B',1),('C',1);
    INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES('A-05','A','VIP'),('A-06','A','VIP'),('B-01','B','Standard'),('C-01','C','Standard');
    INSERT INTO vehicles(plate_number) VALUES('DBF2489');
    INSERT INTO accounts(email,password_hash,plate_number,is_vip) VALUES('driver@example.test','test','DBF2489',0)");
try {
    simulationEntry($priorityDb,'DBF2489','live-zone-a-test','TEST','A-06');
    throw new RuntimeException('live non-VIP allocation reached Zone A');
} catch (RuntimeException $expected) {
    check($expected->getCode()===409,'live non-VIP allocation still excludes Zone A');
}
$priorityScenario = 'sim_grace_a_priority';
simulationOccupancyStart($priorityDb,$priorityScenario,'A');
$baseline = eventData($priorityScenario,0,'A-06','',1,'VACANT');
$baseline['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($priorityDb,$baseline);
$baselineOccupant = eventData($priorityScenario,0,'A-05','',3,'OCCUPIED');
$baselineOccupant['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($priorityDb,$baselineOccupant);
check((int)$priorityDb->query('SELECT COUNT(*) FROM sectional_alerts')->fetchColumn()===0,
    'pre-entry Zone A snapshot occupants are assumed authorized and raise no priority alerts');
$priorityEntry = simulationEntry($priorityDb,'DBF2489',$priorityScenario,'TEST','A-06');
check($priorityEntry['slot_number']==='B-01','recorded non-VIP entrant receives nearest eligible Zone B bay');
$nextPriorityEntry = simulationEntry($priorityDb,'XYZ9876',$priorityScenario,'TEST','A-06');
check($nextPriorityEntry['slot_number']==='C-01','allocator falls through to the next available eligible zone');
check((int)$priorityDb->query("SELECT is_occupied FROM parking_slots WHERE slot_number='A-06'")->fetchColumn()===0,
    'entry does not allocate the unauthorized Zone A bay');
try {
    simulationZoneAPriorityCheck($priorityDb,$priorityScenario,(int)$priorityEntry['session_id'],
        'simulation_zone_a','A-06','/tmp/priority.jpg','DBF2489');
    throw new RuntimeException('priority alert raised before parking');
} catch (RuntimeException $expected) {
    check($expected->getCode()===409,'priority alert waits for occupied sectional observation');
}
$parkedPriority = eventData($priorityScenario,(int)$priorityEntry['session_id'],'A-06','DBF2489',2);
$parkedPriority['camera_id'] = 'simulation_zone_a';
check(simulationSectionalEvent($priorityDb,$parkedPriority)['result']==='ZONE_A_PRIORITY_PENDING',
    'Zone A parking waits for one confirmed priority review rather than an extra wrong-bay fine');
check((int)$priorityDb->query('SELECT COUNT(*) FROM sectional_alerts')->fetchColumn()===0,
    'transient occupancy alone does not create the priority alert');
check(simulationZoneAPriorityCheck($priorityDb,$priorityScenario,(int)$priorityEntry['session_id'],
    'simulation_zone_a','A-06','/tmp/priority.jpg','OTHER123')['result']==='IDENTITY_UNKNOWN',
    'unrelated parking clip does not accuse the active vehicle');
$priority = simulationZoneAPriorityCheck($priorityDb,$priorityScenario,(int)$priorityEntry['session_id'],
    'simulation_zone_a','A-06','/tmp/priority.jpg','DBF2489');
check($priority['result']==='PENDING_REVIEW' && $priority['alert_id']>0,
    'confirmed Zone A parking creates priority review alert');
check(simulationZoneAPriorityCheck($priorityDb,$priorityScenario,(int)$priorityEntry['session_id'],
    'simulation_zone_a','A-06','/tmp/priority.jpg','DBF2489')['alert_id']===$priority['alert_id'],
    'repeated parking check does not duplicate alert');
check((int)$priorityDb->query('SELECT COUNT(*) FROM violations')->fetchColumn()===0,
    'priority alert alone creates no fine');
$priorityFine = simulationReviewAlert($priorityDb,$priority['alert_id'],'CONFIRMED',null,'staff@example.test');
check($priorityFine['violation_id']>0 &&
    (float)$priorityDb->query('SELECT penalty_amount FROM violations')->fetchColumn()===200.0,
    'confirmed Zone A priority alert creates a 200-peso fine');

$reservationDb = new PDO('sqlite::memory:');
$reservationDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$reservationDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$reservationDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('A',1),('B',1);
    INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES('A-06','A','VIP'),('B-01','B','Standard');
    INSERT INTO vehicles(plate_number) VALUES('DBF2489');
    INSERT INTO accounts(email,password_hash,plate_number,is_vip) VALUES('reserved@example.test','test','DBF2489',0);
    INSERT INTO reservations(vehicle_id,slot_id,status,payment_state,token_id)
        VALUES(1,1,'ACTIVE','PAID','QR_PAID_ZONE_A')");
$reservationScenario = 'sim_reservation_a_relocated';
simulationOccupancyStart($reservationDb,$reservationScenario,'A');
$reservationBaseline = eventData($reservationScenario,0,'A-06','',1,'VACANT');
$reservationBaseline['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($reservationDb,$reservationBaseline);
$relocated = simulationEntry($reservationDb,'DBF2489',$reservationScenario,'TEST','A-06');
check($relocated['slot_number']==='B-01' && $relocated['entry_token']==='QR_PAID_ZONE_A'
    && $relocated['payment_status']==='PAID_RESERVATION',
    'revoked VIP status redirects paid Zone A reservation while preserving its payment and QR');
check($reservationDb->query("SELECT s.slot_number FROM reservations r JOIN parking_slots s ON s.slot_id=r.slot_id")->fetchColumn()==='B-01'
    && $reservationDb->query("SELECT status FROM reservations")->fetchColumn()==='FULFILLED',
    'paid reservation record follows the allocated bay');
$reservationDb->exec("INSERT INTO vehicles(plate_number) VALUES('VIP1234');
    INSERT INTO accounts(email,password_hash,plate_number,is_vip)
        VALUES('vip@example.test','test','VIP1234',1)");
check(simulationEntry($reservationDb,'VIP1234',$reservationScenario,'TEST','A-06')['slot_number']==='A-06',
    'eligible VIP entry still receives the observed vacant Zone A bay');

$allocationDb = new PDO('sqlite::memory:');
$allocationDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$allocationDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$allocationDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('D',3)");
$allocationDb->exec("INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES
    ('D-01','D','Standard'),('D-02','D','Standard'),('D-03','D','Standard')");
$allocationDb->exec("INSERT INTO vehicles(plate_number,vehicle_type) VALUES('BBB1234','Transient')");
$allocationDb->exec("INSERT INTO reservations(vehicle_id,slot_id,status,payment_state,token_id)
    VALUES(1,1,'ACTIVE','PAID','QR_RESERVED_BAY')");
ensureSimulationSchema($allocationDb);
$scenario = 'sim_reserved_slot_d_snapshot';
$observe = $allocationDb->prepare("INSERT INTO slot_observations
    (slot_id,camera_id,scenario_id,occupancy_state,observed_at,frame_index,source)
    VALUES(?,'simulation_zone_d',?,?,CURRENT_TIMESTAMP,2,'{}')");
$observe->execute([1,$scenario,'VACANT']);
$observe->execute([2,$scenario,'OCCUPIED']);
try {
    simulationEntry($allocationDb,'AAA1234',$scenario,'TEST','','UNKNOWN',0,'D',true,'D-01');
    throw new RuntimeException('reserved-bay test allocated an observed occupied or unobserved bay');
} catch (RuntimeException $expected) {
    check($expected->getCode()===409,'reserved-bay allocation requires observed vacancy');
}
$allocationDb->exec("UPDATE slot_observations SET occupancy_state='VACANT' WHERE slot_id=2");
$wrongBayEntry = simulationEntry($allocationDb,'AAA1234',$scenario,'TEST','','UNKNOWN',0,'D',true,'D-01');
check($wrongBayEntry['slot_number']==='D-02','reserved-bay test assigned a separately observed vacant bay');

$mirrorDb = new PDO('sqlite::memory:');
$mirrorDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$mirrorDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$mirrorDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('A',2)");
$mirrorDb->exec("INSERT INTO parking_slots(slot_number,zone,is_occupied) VALUES('A-01','A',1),('A-02','A',0)");
$mirrorScenario = 'sim_grace_a_mirror';
check(simulationOccupancyStart($mirrorDb,$mirrorScenario,'A')['saved_slots']===2,'saved original Zone A flags');
$vacant = eventData($mirrorScenario,0,'A-01','',1,'VACANT');
$vacant['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($mirrorDb,$vacant);
$occupied = eventData($mirrorScenario,0,'A-02','',2,'OCCUPIED');
$occupied['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($mirrorDb,$occupied);
check($mirrorDb->query("SELECT GROUP_CONCAT(is_occupied,',') FROM parking_slots WHERE zone='A' ORDER BY slot_id")->fetchColumn()==='0,1',
    'sectional feed mirrors both vacancy and occupancy');
check((int)$mirrorDb->query("SELECT active_parked_count FROM spatial_allocation WHERE zone_id='A'")->fetchColumn()===1,
    'zone count follows temporary camera occupancy');
check(simulationOccupancyFinish($mirrorDb,$mirrorScenario)['restored_slots']===2,'restored original slot flags');
check($mirrorDb->query("SELECT GROUP_CONCAT(is_occupied,',') FROM parking_slots WHERE zone='A' ORDER BY slot_id")->fetchColumn()==='1,0',
    'original Zone A flags returned after run');
check((int)$mirrorDb->query("SELECT active_parked_count FROM spatial_allocation WHERE zone_id='A'")->fetchColumn()===0,
    'original zone count returned after run');
$mirrorDb->exec("INSERT INTO vehicles(plate_number) VALUES('XYZ1234')");
$mirrorDb->exec("INSERT INTO parking_sessions(vehicle_id,slot_id) VALUES(1,2)");
$persistentScenario = 'snapshot_a_persistent';
simulationOccupancyStart($mirrorDb,$persistentScenario,'A',true);
$persistentEvent = eventData($persistentScenario,0,'A-02','',11,'OCCUPIED');
$persistentEvent['camera_id'] = 'simulation_zone_a';
simulationSectionalEvent($mirrorDb,$persistentEvent);
check((int)$mirrorDb->query("SELECT is_occupied FROM parking_slots WHERE slot_number='A-02'")->fetchColumn()===1,
    'occupied snapshot repairs a slot with an open session');
check(simulationOccupancyFinish($mirrorDb,$persistentScenario)['persisted']===true,
    'persistent snapshot completes without restoration');
check((int)$mirrorDb->query("SELECT is_occupied FROM parking_slots WHERE slot_number='A-02'")->fetchColumn()===1,
    'observed occupancy persists after snapshot');
check((int)$mirrorDb->query("SELECT COUNT(*) FROM audit_logs WHERE action_type='SECTIONAL_SNAPSHOT_SYNC'")->fetchColumn()===1,
    'snapshot summary is audited');
$qrDb = new PDO('sqlite::memory:');
$qrDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$qrDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$qrDb->exec("INSERT INTO spatial_allocation(zone_id,max_capacity) VALUES('B',1)");
$qrDb->exec("INSERT INTO parking_slots(slot_number,zone,slot_type) VALUES('B-01','B','Standard')");
ensureSimulationSchema($qrDb);
$qrDb->exec("INSERT INTO slot_observations
    (slot_id,camera_id,scenario_id,occupancy_state,observed_at,frame_index,source)
    VALUES(1,'simulation_zone_b','qr_fallback_test','VACANT',CURRENT_TIMESTAMP,2,'{}')");
$qrEntry = simulationEntry($qrDb,'QR_ONLY_1234567890ABCDEF','qr_fallback_test','TEST',
    'B-01','WHITE',.91,'',false,'B-01',true);
check($qrEntry['qr_only']===true && $qrEntry['entry_token']!=='' && $qrEntry['vehicle_color']==='WHITE',
    'unreadable plate receives QR token and entrance color');
check($qrDb->query("SELECT vehicle_type FROM vehicles WHERE plate_number='QR_ONLY_1234567890ABCDEF'")->fetchColumn()==='QR_ONLY',
    'unreadable plate has a distinct internal vehicle record');
check($qrDb->query("SELECT vehicle_color FROM parking_sessions WHERE session_id={$qrEntry['session_id']}")->fetchColumn()==='WHITE',
    'QR-only entry persists the observed color');
$zoneBOverstay = [
    'event_id'=>'00000000-0000-4000-8000-000000000020',
    'scenario_id'=>'qr_fallback_test','session_id'=>$qrEntry['session_id'],
    'camera_id'=>'simulation_zone_b','event_type'=>'parking.restricted.overstay',
    'frame_index'=>20,'timestamp'=>gmdate('c'),'violation_type'=>'DRIVEWAY',
    'duration_seconds'=>20,'region'=>'driveway_front_b','track_id'=>'track-b',
];
check(simulationSectionalEvent($qrDb,$zoneBOverstay)['result']==='PENDING_REVIEW',
    '20-second driveway rule applies outside Zone C');
$policyDb = new PDO('sqlite::memory:');
$policyDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$policyDb->exec(file_get_contents(__DIR__ . '/../backend/database/Schema.sql'));
$policyDb->exec("INSERT INTO vehicles(plate_number) VALUES('ABC1234');
    INSERT INTO parking_slots(slot_number,zone) VALUES('B-01','B');
    INSERT INTO parking_sessions(vehicle_id,slot_id) VALUES(1,1);
    INSERT INTO violations(session_id,violation_type,penalty_amount,status)
        VALUES(1,'Old fine',300,'PAID'),(1,'Dismissed fine',300,'DISMISSED')");
ensureSimulationSchema($policyDb);
$policyDb->exec("INSERT INTO sectional_alerts(event_id,scenario_id,session_id,camera_id,violation_type,region,details,status)
    VALUES('policy-1','policy-test',1,'simulation_zone_b','WRONG_SLOT','B-02','first','CONFIRMED'),
          ('policy-2','policy-test',1,'simulation_zone_b','WRONG_SLOT','B-02','second','CONFIRMED'),
          ('policy-3','policy-test',1,'simulation_zone_b','WRONG_SLOT','B-03','rejected','REJECTED')");
ensureSimulationSchema($policyDb);
check((int)$policyDb->query('SELECT COUNT(DISTINCT violation_id) FROM sectional_alerts WHERE status=\'CONFIRMED\'')->fetchColumn()===1,
    'duplicate confirmed alerts share one fine');
check((int)$policyDb->query('SELECT COUNT(*) FROM violations WHERE penalty_amount=200')->fetchColumn()===2,
    'old paid fine and confirmed alert use 200-peso policy');
check((float)$policyDb->query("SELECT penalty_amount FROM violations WHERE status='DISMISSED'")->fetchColumn()===0.0,
    'dismissed violation has no penalty');
check($policyDb->query("SELECT violation_id FROM sectional_alerts WHERE status='REJECTED'")->fetchColumn()===null,
    'rejected alert remains without a fine');
$policyDb->exec("INSERT INTO sectional_alerts(event_id,scenario_id,session_id,camera_id,violation_type,region,details)
    VALUES('policy-4','policy-test',1,'simulation_zone_b','WRONG_SLOT','B-04','new wrong bay'),
          ('policy-5','policy-test',1,'simulation_zone_b','WRONG_SLOT','B-04','same incident')");
$firstFine = simulationReviewAlert($policyDb,4,'CONFIRMED',null,'staff@example.test')['violation_id'];
$secondFine = simulationReviewAlert($policyDb,5,'CONFIRMED',null,'staff@example.test')['violation_id'];
check($firstFine > 0 && $secondFine === $firstFine,
    'confirmed wrong-slot alerts for one incident create one 200-peso fine');
check((float)$policyDb->query("SELECT penalty_amount FROM violations WHERE violation_id={$firstFine}")->fetchColumn()===200.0,
    'new wrong-slot fine is 200 pesos');
echo "Simulation integration checks passed.\n";
