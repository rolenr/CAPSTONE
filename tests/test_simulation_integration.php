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
$db->exec("INSERT INTO reservations(vehicle_id,slot_id,status,payment_state) VALUES(1,1,'ACTIVE','PAID')");
$entry = simulationEntry($db,'LAC7863','recorded1','TEST');
check($entry['slot_number']==='D-05' && $entry['payment_status']==='PAID_RESERVATION','paid reservation assignment');
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
check(simulationSectionalEvent($db,eventData('recorded1',$id,'D-05','LAC7863',6,'VACANT'))['result']==='OBSERVED','vacancy transition');
$overstay = ['event_id'=>'00000000-0000-4000-8000-000000000007','scenario_id'=>'recorded1',
    'session_id'=>$id,'camera_id'=>'section_d_sample1','event_type'=>'parking.restricted.overstay',
    'frame_index'=>300,'timestamp'=>gmdate('c'),'violation_type'=>'DRIVEWAY',
    'duration_seconds'=>299,'region'=>'driveway','track_id'=>'track1'];
try { simulationSectionalEvent($db,$overstay); throw new RuntimeException('299-second alert accepted'); }
catch (InvalidArgumentException $expected) {}
$overstay['duration_seconds']=300;
check(simulationSectionalEvent($db,$overstay)['result']==='PENDING_REVIEW','300-second alert');
check((int)$db->query('SELECT COUNT(*) FROM violations')->fetchColumn()===0,'no automatic fine');
$alertId = (int)$db->query("SELECT alert_id FROM sectional_alerts WHERE violation_type='DRIVEWAY'")->fetchColumn();
check(simulationReviewAlert($db,$alertId,'CONFIRMED',null,'staff@example.test')['violation_id']>0,'staff fine');
check((float)$db->query('SELECT penalty_amount FROM violations')->fetchColumn()===300.0,'fine amount');
try { recordExitDeparture($db,$id,'ALPR','TEST'); throw new RuntimeException('exit with unpaid fine accepted'); }
catch (RuntimeException $expected) { check($expected->getCode()===409,'unpaid fine exit denial'); }
$db->exec("UPDATE violations SET status='PAID'");
check(recordExitDeparture($db,$id,'ALPR','TEST')['departure_recorded']===true,'paid departure');
check((int)$db->query("SELECT active_parked_count FROM spatial_allocation WHERE zone_id='D'")->fetchColumn()===0,'zone count after departure');
check((int)$db->query("SELECT is_occupied FROM parking_slots WHERE slot_number='D-05'")->fetchColumn()===0,'slot released');
echo "Simulation integration checks passed.\n";
