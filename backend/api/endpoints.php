<?php
// 1. Force PHP to output errors as JSON instead of HTML to prevent frontend crashes
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(["error" => "PHP Error: $errstr on line $errline"]);
    exit;
});
set_exception_handler(function($e) {
    echo json_encode(["error" => "Exception: " . $e->getMessage()]);
    exit;
});

// 2. Initialize SQLite Connection
try {
    $dbPath = __DIR__ . '/../database/parking.db';
    if (!file_exists($dbPath)) {
        if (file_exists(__DIR__ . '/../parking.db')) {
            $dbPath = __DIR__ . '/../parking.db';
        }
    }
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["error" => "Database connection failed."]);
    exit;
}

// 3. Define method and action BEFORE running the IF statements
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- ROUTE: Register Account ---
if ($method === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(["error" => "Invalid data received."]);
        exit;
    }

    $email = $data['email'] ?? '';
    $password = password_hash($data['password'] ?? '', PASSWORD_DEFAULT); 
    $plate = strtoupper(trim($data['plate'] ?? ''));
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT OR IGNORE INTO vehicles (plate_number) VALUES (?)");
        $stmt->execute([$plate]);
        
        $stmt = $db->prepare("INSERT INTO accounts (email, password_hash, plate_number) VALUES (?, ?, ?)");
        $stmt->execute([$email, $password, $plate]);
        
        $db->commit();
        echo json_encode(["success" => true]);
    } catch (PDOException $e) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(["error" => "Registration failed. Email or Plate may already exist. Details: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Login ---
if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    
    try {
        $stmt = $db->prepare("SELECT * FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            echo json_encode([
                "success" => true, 
                "email" => $user['email'],
                "license_plate" => $user['plate_number'],
                "is_vip" => (bool)($user['is_vip'] ?? 0),
                "is_admin" => (bool)($user['is_admin'] ?? 0)
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Invalid email or password."]);
        }
    } catch (PDOException $e) {
        echo json_encode(["error" => "Database error during login: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Live Map True Availability ---
if ($method === 'GET' && $action === 'map') {

    $stmt = $db->query("
        SELECT
            s.slot_id,
            s.slot_number,
            s.zone,
            s.is_occupied,
            v.plate_number
        FROM parking_slots s
        LEFT JOIN parking_sessions ps 
            ON s.slot_id = ps.slot_id AND ps.exit_time IS NULL
        LEFT JOIN vehicles v 
            ON ps.vehicle_id = v.vehicle_id
        ORDER BY s.zone, s.slot_number
    ");

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- ROUTE: Handle Reservation ---
if ($method === 'POST' && $action === 'reserve') {
    $data = json_decode(file_get_contents("php://input"), true);
    $plate = strtoupper(trim($data["plate"]));
    $zone = $data["zone"];
    $days = (int)$data["days"];
    $startDate = $data["startDate"]; 
    $endDate = $data["endDate"];

    // VIP SECURITY CHECK
    $accountCheck = $db->prepare("SELECT is_vip FROM accounts WHERE plate_number = ?");
    $accountCheck->execute([$plate]);
    $account = $accountCheck->fetch(PDO::FETCH_ASSOC);

    $is_vip = $account ? (int)$account['is_vip'] : 0;

    if ($zone === 'E' && $is_vip !== 1) {
        echo json_encode(["error" => "Zone E reservations are restricted to VIP accounts only."]);
        exit;
    }

    if (!in_array($zone, ['A', 'B', 'C', 'D', 'E'])) {
        echo json_encode(["error" => "Invalid zone selected."]);
        exit;
    }
    // END VIP SECURITY CHECK

    $vehicle = $db->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ?");
    $vehicle->execute([$plate]);
    $vehicle = $vehicle->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle) {
        echo json_encode(["error" => "Vehicle not registered."]);
        exit;
    }

    $overlapCheck = $db->prepare("SELECT reservation_id FROM reservations WHERE vehicle_id = ? AND status = 'ACTIVE'");
    $overlapCheck->execute([$vehicle['vehicle_id']]);
    if ($overlapCheck->fetch()) {
        echo json_encode(["error" => "You already have an active reservation."]);
        exit;
    }

    $slot = $db->prepare("SELECT slot_id, slot_number FROM parking_slots WHERE zone = ? AND is_occupied = 0 AND slot_id NOT IN (SELECT slot_id FROM reservations WHERE status='ACTIVE') LIMIT 1");
    $slot->execute([$zone]);
    $slot = $slot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        echo json_encode(["error" => "No available slots in Zone " . $zone]);
        exit;
    }

    $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));
    $token = "QR_" . uniqid(); 

    $reserve = $db->prepare("
        INSERT INTO reservations(vehicle_id, slot_id, expiry_time, status, token_id, start_date, end_date)
        VALUES(?, ?, ?, 'ACTIVE', ?, ?, ?)
    ");
    
    $reserve->execute([$vehicle["vehicle_id"], $slot["slot_id"], $expiry, $token, $startDate, $endDate]);
    $reservationId = $db->lastInsertId();

    $base_fee = 60.00 * $days;
    $overnight_surcharge = ($days >= 2) ? 100.00 : 0.00; 
    $fee = $base_fee + $overnight_surcharge;

    echo json_encode([
        "success" => true,
        "message" => "Reservation Successful!",
        "reservation_id" => $reservationId,
        "zone" => $zone,
        "slot" => $slot["slot_number"],
        "start_date" => $startDate,
        "end_date" => $endDate,
        "fee" => $fee,
        "qr_token" => $token
    ]);
    exit;
}

// --- ROUTE: Check Active Reservation ---
if ($method === 'GET' && $action === 'active_reservation') {
    $plate = strtoupper(trim($_GET['plate'] ?? ''));

    if (!$plate) {
        echo json_encode(["error" => "Plate is required."]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT
            r.reservation_id,
            r.token_id,
            r.start_date,
            r.end_date,
            s.zone,
            s.slot_number
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.vehicle_id
        JOIN parking_slots s ON r.slot_id = s.slot_id
        WHERE v.plate_number = ? AND r.status = 'ACTIVE'
        LIMIT 1
    ");
    $stmt->execute([$plate]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        echo json_encode(["success" => true, "active" => false]);
        exit;
    }

    $start = new DateTime($reservation['start_date']);
    $end = new DateTime($reservation['end_date']);
    $days = (int)$start->diff($end)->days + 1;
    if ($days < 1) $days = 1;

    $base_fee = 60.00 * $days;
    $overnight_surcharge = ($days >= 2) ? 100.00 : 0.00;
    $fee = $base_fee + $overnight_surcharge;

    echo json_encode([
        "success" => true,
        "active" => true,
        "reservation_id" => $reservation['reservation_id'],
        "qr_token" => $reservation['token_id'],
        "zone" => $reservation['zone'],
        "slot" => $reservation['slot_number'],
        "start_date" => $reservation['start_date'],
        "end_date" => $reservation['end_date'],
        "fee" => $fee
    ]);
    exit;
}

// --- ROUTE: Vendor Digital Validation ---
if ($method === 'POST' && $action === 'vendor_validate') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['qr_token'] ?? null;

    if (!$token) {
        echo json_encode(["error" => "Customer QR Token is required."]);
        exit;
    }

    $stmt = $db->prepare("UPDATE parking_sessions SET payment_status = 'PAID_VENDOR', vendor_scan_time = CURRENT_TIMESTAMP WHERE entry_token = ? AND location_status = 'PARKED'");
    $stmt->execute([$token]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(["success" => true, "message" => "Vendor validation successful. Customer has 20 minutes to exit."]);
    } else {
        echo json_encode(["error" => "Invalid token or vehicle already exited."]);
    }
    exit;
}

// --- ROUTE: Exit Billing & State Finalization ---
if ($method === 'POST' && $action === 'checkout') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['qr_token'] ?? null;
    $is_lost_ticket = $data['is_lost_ticket'] ?? false;
    
    if (!$token) {
        echo json_encode(["error" => "QR Token is required for checkout."]);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM parking_sessions WHERE entry_token = ? AND location_status = 'PARKED'");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(["error" => "No active parking session found for this token."]);
        exit;
    }

    $entry_time = new DateTime($session['entry_time']);
    $exit_time = new DateTime();
    $interval = $entry_time->diff($exit_time);
    $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    $fee = 0.00;
    $status = $session['payment_status'];

    if ($status === 'PAID_VENDOR') {
        $vendor_time = new DateTime($session['vendor_scan_time']);
        $minutes_since_vendor = ($vendor_time->diff($exit_time)->days * 24 * 60) + ($vendor_time->diff($exit_time)->h * 60) + $vendor_time->diff($exit_time)->i;
        
        if ($minutes_since_vendor > 20) {
            $fee = 30.00; 
            $status = 'PAID';
        }
    } elseif ($status === 'PAID_RESERVATION') {
        $fee = 0.00; 
    } elseif ($total_minutes <= 15) {
        $fee = 0.00;
        $status = 'BYPASSED'; 
    } else {
        $entry_date = $entry_time->format('Y-m-d');
        $exit_date = $exit_time->format('Y-m-d');
        $exit_hour = (int)$exit_time->format('H');

        if ($exit_date > $entry_date || $exit_hour >= 23) {
            $fee = 100.00;
            $status = 'PAID_OVERNIGHT';
        } else {
            $fee = 30.00;
            $status = 'PAID';
        }
    }

    if ($is_lost_ticket) $fee += 100.00;

    $update = $db->prepare("UPDATE parking_sessions SET exit_time = CURRENT_TIMESTAMP, total_fee = ?, payment_status = ?, location_status = 'EXITED' WHERE session_id = ?");
    
    if ($update->execute([$fee, $status, $session['session_id']])) {
        echo json_encode(["success" => true, "duration_minutes" => $total_minutes, "final_fee" => $fee, "payment_status" => $status]);
    } else {
        echo json_encode(["error" => "Failed to finalize transaction audit."]);
    }
    exit;
}

// --- ROUTE: Admin Overview Statistics ---
if ($method === 'GET' && $action === 'admin_stats') {
    try {
        $totalSlots = (int)$db->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();
        $occupiedSlots = (int)$db->query("SELECT COUNT(*) FROM parking_slots WHERE is_occupied = 1")->fetchColumn();
        $pendingViolations = (int)$db->query("SELECT COUNT(*) FROM violations WHERE status != 'PAID'")->fetchColumn();
        $activeReservations = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE status = 'ACTIVE'")->fetchColumn();

        // Calculate Revenue from sessions
        $sessionRevenue = (float)$db->query("SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION')")->fetchColumn();

        // Calculate Reservation revenue
        $reservationRows = $db->query("SELECT start_date, end_date FROM reservations WHERE status IN ('ACTIVE', 'FULFILLED', 'COMPLETED')")->fetchAll(PDO::FETCH_ASSOC);
        $reservationRevenue = 0.0;
        foreach ($reservationRows as $r) {
            $st = new DateTime($r['start_date']);
            $en = new DateTime($r['end_date']);
            $d = (int)$st->diff($en)->days + 1;
            if ($d < 1) $d = 1;
            $reservationRevenue += (60.0 * $d) + (($d >= 2) ? 100.0 : 0.0);
        }

        // Calculate Paid Violations
        $violationRevenue = (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID'")->fetchColumn();

        $grossRevenue = $sessionRevenue + $reservationRevenue + $violationRevenue;

        // Today's revenue
        $todaySessionRev = (float)$db->query("SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION') AND DATE(entry_time) = DATE('now', 'localtime')")->fetchColumn();
        $todayViolationRev = (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND DATE(issued_at) = DATE('now', 'localtime')")->fetchColumn();
        $todayRevenue = $todaySessionRev + $todayViolationRev;

        $occupancyRate = $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100, 1) : 0;

        echo json_encode([
            "success" => true,
            "total" => $totalSlots,
            "occupied" => $occupiedSlots,
            "available" => ($totalSlots - $occupiedSlots),
            "occupancy_rate" => $occupancyRate,
            "violations" => $pendingViolations,
            "active_reservations" => $activeReservations,
            "gross_revenue" => $grossRevenue,
            "today_revenue" => $todayRevenue
        ]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch stats: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Business Intelligence & Executive Analytics ---
if ($method === 'GET' && $action === 'admin_analytics') {
    try {
        // 1. Financial Revenue Summary
        $transientRev = (float)$db->query("SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions WHERE payment_status = 'PAID'")->fetchColumn();
        $transientCount = (int)$db->query("SELECT COUNT(*) FROM parking_sessions WHERE payment_status = 'PAID'")->fetchColumn();

        $overnightRev = (float)$db->query("SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions WHERE payment_status = 'PAID_OVERNIGHT'")->fetchColumn();
        $overnightCount = (int)$db->query("SELECT COUNT(*) FROM parking_sessions WHERE payment_status = 'PAID_OVERNIGHT'")->fetchColumn();

        $vendorCount = (int)$db->query("SELECT COUNT(*) FROM parking_sessions WHERE payment_status = 'PAID_VENDOR'")->fetchColumn();
        $vendorWaived = $vendorCount * 30.0;

        $bypassedCount = (int)$db->query("SELECT COUNT(*) FROM parking_sessions WHERE payment_status = 'BYPASSED'")->fetchColumn();

        // Reservations Revenue
        $resQuery = $db->query("SELECT start_date, end_date, status FROM reservations");
        $resAll = $resQuery->fetchAll(PDO::FETCH_ASSOC);
        $resTotalRevenue = 0.0;
        $resActive = 0;
        $resCompleted = 0;
        $resCancelled = 0;
        foreach ($resAll as $r) {
            if ($r['status'] === 'ACTIVE') $resActive++;
            elseif ($r['status'] === 'CANCELLED') $resCancelled++;
            else $resCompleted++;

            if ($r['status'] !== 'CANCELLED') {
                $st = new DateTime($r['start_date']);
                $en = new DateTime($r['end_date']);
                $d = (int)$st->diff($en)->days + 1;
                if ($d < 1) $d = 1;
                $resTotalRevenue += (60.0 * $d) + (($d >= 2) ? 100.0 : 0.0);
            }
        }

        // Violations Revenue
        $violationStats = $db->query("
            SELECT 
                COUNT(*) as total_count,
                COALESCE(SUM(penalty_amount), 0) as total_imposed,
                COALESCE(SUM(CASE WHEN status = 'PAID' THEN penalty_amount ELSE 0 END), 0) as total_collected,
                COALESCE(SUM(CASE WHEN status != 'PAID' THEN penalty_amount ELSE 0 END), 0) as outstanding,
                COALESCE(AVG(penalty_amount), 0) as avg_fine,
                SUM(CASE WHEN status = 'PAID' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status != 'PAID' THEN 1 ELSE 0 END) as unpaid_count
            FROM violations
        ")->fetch(PDO::FETCH_ASSOC);

        $grossTotal = $transientRev + $overnightRev + $resTotalRevenue + (float)$violationStats['total_collected'];

        // Period revenues
        $todayRev = (float)$db->query("
            SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions 
            WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION') 
            AND DATE(entry_time) = DATE('now', 'localtime')
        ")->fetchColumn();

        $weekRev = (float)$db->query("
            SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions 
            WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION') 
            AND entry_time >= DATE('now', '-7 days', 'localtime')
        ")->fetchColumn();

        $monthRev = (float)$db->query("
            SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions 
            WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION') 
            AND entry_time >= DATE('now', '-30 days', 'localtime')
        ")->fetchColumn();

        // Add violations to periods
        $todayRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND DATE(issued_at) = DATE('now', 'localtime')")->fetchColumn();
        $weekRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND issued_at >= DATE('now', '-7 days', 'localtime')")->fetchColumn();
        $monthRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND issued_at >= DATE('now', '-30 days', 'localtime')")->fetchColumn();

        // Average Revenue Per Vehicle (ARPV)
        $completedSessions = (int)$db->query("SELECT COUNT(*) FROM parking_sessions WHERE location_status = 'EXITED'")->fetchColumn();
        $arpv = $completedSessions > 0 ? round(($transientRev + $overnightRev) / $completedSessions, 2) : 0;

        // Average Stay Duration (Minutes)
        $avgDuration = (float)$db->query("
            SELECT COALESCE(AVG((strftime('%s', exit_time) - strftime('%s', entry_time)) / 60.0), 0)
            FROM parking_sessions 
            WHERE exit_time IS NOT NULL
        ")->fetchColumn();

        // 2. Capacity Heatmap by Zone
        $zonesMeta = [
            'A' => ['name' => 'Zone A', 'role' => 'PWD / Priority Accessible', 'target' => 'Monitored Overhead'],
            'B' => ['name' => 'Zone B', 'role' => 'Standard Monitored Paved', 'target' => 'Monitored Overhead'],
            'C' => ['name' => 'Zone C', 'role' => 'Standard Monitored Paved', 'target' => 'Monitored Overhead'],
            'D' => ['name' => 'Zone D', 'role' => 'Standard Monitored Paved', 'target' => 'Monitored Overhead'],
            'E' => ['name' => 'Zone E', 'role' => 'VIP & Reserved Paved', 'target' => 'Monitored Overhead'],
            'F' => ['name' => 'Zone F', 'role' => 'Rough Road Overflow Section', 'target' => 'Unmonitored Perimeter']
        ];

        $zoneSlots = $db->query("
            SELECT 
                s.zone, 
                COUNT(*) as total, 
                SUM(CASE WHEN s.is_occupied = 1 THEN 1 ELSE 0 END) as occupied
            FROM parking_slots s
            GROUP BY s.zone
            ORDER BY s.zone
        ")->fetchAll(PDO::FETCH_ASSOC);

        $activeResByZone = $db->query("
            SELECT s.zone, COUNT(*) as res_count
            FROM reservations r
            JOIN parking_slots s ON r.slot_id = s.slot_id
            WHERE r.status = 'ACTIVE'
            GROUP BY s.zone
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $capacityHeatmap = [];
        $totalLotSlots = 0;
        $totalLotOccupied = 0;

        foreach ($zoneSlots as $zs) {
            $z = $zs['zone'];
            $tot = (int)$zs['total'];
            $occ = (int)$zs['occupied'];
            $res = (int)($activeResByZone[$z] ?? 0);
            $avail = max(0, $tot - $occ);
            $utilization = $tot > 0 ? round(($occ / $tot) * 100, 1) : 0;

            $totalLotSlots += $tot;
            $totalLotOccupied += $occ;

            $heat = 'low';
            if ($utilization >= 85) $heat = 'critical';
            elseif ($utilization >= 65) $heat = 'high';
            elseif ($utilization >= 35) $heat = 'moderate';

            $capacityHeatmap[] = [
                'zone' => $z,
                'name' => $zonesMeta[$z]['name'] ?? ("Zone " . $z),
                'role' => $zonesMeta[$z]['role'] ?? "General Parking",
                'target' => $zonesMeta[$z]['target'] ?? "Monitored",
                'total_capacity' => $tot,
                'occupied_count' => $occ,
                'reserved_count' => $res,
                'available_count' => $avail,
                'utilization_rate' => $utilization,
                'heat_level' => $heat
            ];
        }

        // 3. Peak Hours & Hourly Influx (24 hours)
        $hourlyCounts = $db->query("
            SELECT CAST(strftime('%H', entry_time) AS INTEGER) as hr, COUNT(*) as cnt
            FROM parking_sessions
            GROUP BY hr
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $hourlyDistribution = [];
        $maxHourly = 0;
        for ($h = 0; $h < 24; $h++) {
            $cnt = (int)($hourlyCounts[$h] ?? 0);
            if ($cnt > $maxHourly) $maxHourly = $cnt;
            $hourlyDistribution[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $cnt
            ];
        }
        foreach ($hourlyDistribution as &$hd) {
            $hd['pct'] = $maxHourly > 0 ? round(($hd['count'] / $maxHourly) * 100) : 0;
            $hd['is_peak'] = $maxHourly > 0 && ($hd['count'] >= $maxHourly * 0.7);
        }
        unset($hd);

        // 4. Violations Breakdown by Type
        $violationsByType = $db->query("
            SELECT 
                violation_type,
                COUNT(*) as count,
                COALESCE(SUM(penalty_amount), 0) as total_penalty,
                SUM(CASE WHEN status = 'PAID' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status != 'PAID' THEN 1 ELSE 0 END) as unpaid_count
            FROM violations
            GROUP BY violation_type
            ORDER BY count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "revenue" => [
                "gross_total" => round($grossTotal, 2),
                "today" => round($todayRev, 2),
                "this_week" => round($weekRev, 2),
                "this_month" => round($monthRev, 2),
                "arpv" => $arpv,
                "avg_duration_minutes" => round($avgDuration, 1),
                "streams" => [
                    "transient" => ["count" => $transientCount, "total" => round($transientRev, 2), "rate" => "₱30.00 Flat"],
                    "overnight" => ["count" => $overnightCount, "total" => round($overnightRev, 2), "rate" => "₱100.00 Surcharge"],
                    "reservations" => ["count" => count($resAll) - $resCancelled, "total" => round($resTotalRevenue, 2), "rate" => "₱60/day + ₱100"],
                    "violations" => ["count" => (int)$violationStats['paid_count'], "total" => round((float)$violationStats['total_collected'], 2), "rate" => "Variable Fine"],
                    "vendor_subsidies" => ["count" => $vendorCount, "waived_total" => round($vendorWaived, 2), "rate" => "Tenant Validation"],
                    "bypassed_grace" => ["count" => $bypassedCount, "rate" => "Free (<15 min)"]
                ]
            ],
            "capacity_heatmap" => [
                "zones" => $capacityHeatmap,
                "total_capacity" => $totalLotSlots,
                "total_occupied" => $totalLotOccupied,
                "total_available" => max(0, $totalLotSlots - $totalLotOccupied),
                "overall_utilization" => $totalLotSlots > 0 ? round(($totalLotOccupied / $totalLotSlots) * 100, 1) : 0
            ],
            "hourly_distribution" => $hourlyDistribution,
            "reservations_summary" => [
                "total_bookings" => count($resAll),
                "active" => $resActive,
                "completed" => $resCompleted,
                "cancelled" => $resCancelled,
                "total_revenue" => round($resTotalRevenue, 2)
            ],
            "violations_summary" => [
                "total_count" => (int)$violationStats['total_count'],
                "paid_count" => (int)$violationStats['paid_count'],
                "unpaid_count" => (int)$violationStats['unpaid_count'],
                "total_imposed" => round((float)$violationStats['total_imposed'], 2),
                "total_collected" => round((float)$violationStats['total_collected'], 2),
                "outstanding_balance" => round((float)$violationStats['outstanding'], 2),
                "avg_fine" => round((float)$violationStats['avg_fine'], 2),
                "by_type" => $violationsByType
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to generate business analytics: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin - List Ongoing Reservations ---
if ($method === 'GET' && $action === 'admin_reservations') {
    try {
        $stmt = $db->query("
            SELECT
                r.reservation_id,
                r.status,
                r.token_id,
                r.reservation_time,
                r.expiry_time,
                r.start_date,
                r.end_date,
                v.plate_number,
                a.email,
                a.is_vip,
                s.slot_id,
                s.zone,
                s.slot_number
            FROM reservations r
            JOIN vehicles v ON r.vehicle_id = v.vehicle_id
            LEFT JOIN accounts a ON a.plate_number = v.plate_number
            JOIN parking_slots s ON r.slot_id = s.slot_id
            WHERE r.status = 'ACTIVE'
            ORDER BY r.start_date ASC
        ");
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reservations as &$r) {
            $start = new DateTime($r['start_date']);
            $end = new DateTime($r['end_date']);
            $days = (int)$start->diff($end)->days + 1;
            if ($days < 1) $days = 1;
            $base_fee = 60.00 * $days;
            $overnight_surcharge = ($days >= 2) ? 100.00 : 0.00;
            $r['fee'] = $base_fee + $overnight_surcharge;
            $r['is_vip'] = (bool)($r['is_vip'] ?? 0);
        }
        unset($r);

        echo json_encode(["success" => true, "reservations" => $reservations]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch reservations: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin - Override / Cancel a Reservation ---
if ($method === 'POST' && $action === 'override_reservation') {
    $data = json_decode(file_get_contents('php://input'), true);
    $reservationId = $data['reservation_id'] ?? null;
    $newStatus = $data['status'] ?? 'CANCELLED';
    $releaseSlot = $data['release_slot'] ?? true;
    $adminEmail = $data['admin_email'] ?? 'rorocruz@gmail.com';

    if (!$reservationId) {
        echo json_encode(["error" => "Reservation ID required."]);
        exit;
    }

    if (!in_array($newStatus, ['CANCELLED', 'COMPLETED'])) {
        echo json_encode(["error" => "Invalid override status."]);
        exit;
    }

    try {
        $db->beginTransaction();

        $res = $db->prepare("SELECT slot_id, status FROM reservations WHERE reservation_id = ?");
        $res->execute([$reservationId]);
        $reservation = $res->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) {
            $db->rollBack();
            echo json_encode(["error" => "Reservation not found."]);
            exit;
        }

        if ($reservation['status'] !== 'ACTIVE') {
            $db->rollBack();
            echo json_encode(["error" => "Reservation is not currently active."]);
            exit;
        }

        $update = $db->prepare("UPDATE reservations SET status = ? WHERE reservation_id = ?");
        $update->execute([$newStatus, $reservationId]);

        if ($releaseSlot) {
            $freeSlot = $db->prepare("UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ?");
            $freeSlot->execute([$reservation['slot_id']]);
        }

        // Write Audit Log
        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'RESERVATION_CANCEL', 'reservation', ?, ?)
        ");
        $audit->execute([$adminEmail, $reservationId, "Admin cancelled reservation #{$reservationId} and freed assigned slot"]);

        $db->commit();
        echo json_encode(["success" => true, "message" => "Reservation overridden and slot released."]);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(["error" => "Failed to override reservation: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin - List / Search Accounts ---
if ($method === 'GET' && $action === 'admin_accounts') {
    try {
        $search = trim($_GET['search'] ?? '');

        if ($search !== '') {
            $like = "%$search%";
            $stmt = $db->prepare("
                SELECT account_id, email, plate_number, is_vip, is_admin
                FROM accounts
                WHERE email LIKE ? OR plate_number LIKE ?
                ORDER BY email ASC
            ");
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $db->query("
                SELECT account_id, email, plate_number, is_vip, is_admin
                FROM accounts
                ORDER BY email ASC
            ");
        }

        echo json_encode(["success" => true, "accounts" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch accounts: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin - Set / Unset VIP Status ---
if ($method === 'POST' && $action === 'set_vip') {
    $data = json_decode(file_get_contents('php://input'), true);
    $accountId = $data['account_id'] ?? null;
    $isVip = array_key_exists('is_vip', $data) ? (int)!!$data['is_vip'] : null;
    $adminEmail = $data['admin_email'] ?? 'rorocruz@gmail.com';

    if (!$accountId || $isVip === null) {
        echo json_encode(["error" => "Account ID and VIP status are required."]);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE accounts SET is_vip = ? WHERE account_id = ?");
        $stmt->execute([$isVip, $accountId]);

        if ($stmt->rowCount() > 0) {
            $audit = $db->prepare("
                INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
                VALUES (?, 'VIP_TOGGLE', 'account', ?, ?)
            ");
            $statusText = $isVip ? "Granted VIP status" : "Revoked VIP status";
            $audit->execute([$adminEmail, $accountId, "{$statusText} for account #{$accountId}"]);

            echo json_encode(["success" => true, "message" => $isVip ? "Account upgraded to VIP." : "VIP status removed."]);
        } else {
            echo json_encode(["error" => "Account not found or status unchanged."]);
        }
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to update VIP status: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: ALPR / Parking Session Logs ---
if ($method === 'GET' && $action === 'alpr_logs') {
    try {
        $stmt = $db->query("
            SELECT ps.entry_time AS timestamp, ve.plate_number, sl.slot_number, sl.zone, ps.payment_status AS status, ps.location_status
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            ORDER BY ps.entry_time DESC
            LIMIT 15
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

// --- ROUTE: Admin Manual Slot Toggle (Override) ---
if ($method === 'POST' && $action === 'toggle_slot') {
    $data = json_decode(file_get_contents('php://input'), true);
    $slotId = $data['slot_id'] ?? null;
    $isOccupied = $data['is_occupied'] ?? 0;
    $adminEmail = $data['admin_email'] ?? 'rorocruz@gmail.com';

    if (!$slotId) {
        echo json_encode(["error" => "Slot ID required."]);
        exit;
    }

    try {
        $db->beginTransaction();

        $slotInfo = $db->prepare("SELECT slot_number, zone FROM parking_slots WHERE slot_id = ?");
        $slotInfo->execute([$slotId]);
        $slot = $slotInfo->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("UPDATE parking_slots SET is_occupied = ? WHERE slot_id = ?");
        $stmt->execute([$isOccupied, $slotId]);

        if ($slot) {
            // Sync spatial_allocation count
            $activeCountStmt = $db->prepare("SELECT COUNT(*) FROM parking_slots WHERE zone = ? AND is_occupied = 1");
            $activeCountStmt->execute([$slot['zone']]);
            $cnt = $activeCountStmt->fetchColumn();

            $updateSpatial = $db->prepare("UPDATE spatial_allocation SET active_parked_count = ? WHERE zone_id = ?");
            $updateSpatial->execute([$cnt, $slot['zone']]);

            // Log in audit_logs
            $actionStr = $isOccupied ? "Forced Occupied" : "Forced Available";
            $audit = $db->prepare("
                INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
                VALUES (?, 'SLOT_OVERRIDE', 'parking_slot', ?, ?)
            ");
            $audit->execute([$adminEmail, $slotId, "Slot {$slot['slot_number']} (Zone {$slot['zone']}) {$actionStr}"]);
        }

        $db->commit();
        echo json_encode(["success" => true]);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(["error" => "Failed to update slot status: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin Audit Trail Logs ---
if ($method === 'GET' && $action === 'admin_audit_logs') {
    try {
        $stmt = $db->query("
            SELECT log_id, admin_email, action_type, target_entity, target_id, details, created_at
            FROM audit_logs
            ORDER BY created_at DESC
            LIMIT 30
        ");
        echo json_encode(["success" => true, "logs" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch audit logs: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin Violations Management List ---
if ($method === 'GET' && $action === 'admin_violations_list') {
    try {
        $stmt = $db->query("
            SELECT 
                v.violation_id,
                v.session_id,
                v.violation_type,
                v.penalty_amount,
                v.status,
                v.issued_at,
                ve.plate_number,
                sl.slot_number,
                sl.zone
            FROM violations v
            LEFT JOIN parking_sessions ps ON v.session_id = ps.session_id
            LEFT JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            ORDER BY v.issued_at DESC
        ");
        echo json_encode(["success" => true, "violations" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch violations: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Admin Resolve Violation ---
if ($method === 'POST' && $action === 'admin_resolve_violation') {
    $data = json_decode(file_get_contents('php://input'), true);
    $violationId = $data['violation_id'] ?? null;
    $newStatus = $data['status'] ?? 'PAID';
    $adminEmail = $data['admin_email'] ?? 'rorocruz@gmail.com';

    if (!$violationId) {
        echo json_encode(["error" => "Violation ID is required."]);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE violations SET status = ? WHERE violation_id = ?");
        $stmt->execute([$newStatus, $violationId]);

        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'VIOLATION_SETTLED', 'violation', ?, ?)
        ");
        $audit->execute([$adminEmail, $violationId, "Violation #{$violationId} marked as {$newStatus} by admin"]);

        echo json_encode(["success" => true, "message" => "Violation updated to {$newStatus}."]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to update violation: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Violations Tracker (Customer Search) ---
if ($method === 'GET' && $action === 'violations') {
    $plate = strtoupper(trim($_GET['plate'] ?? ''));

    $stmt = $db->prepare("
        SELECT v.violation_type, v.penalty_amount, v.status, v.issued_at
        FROM violations v
        JOIN parking_sessions ps ON v.session_id = ps.session_id
        JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
        WHERE ve.plate_number = ?
    ");

    $stmt->execute([$plate]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- ROUTE: Cashier Lookup (Active Sessions & Tariff Computation) ---
if ($method === 'GET' && $action === 'cashier_lookup') {
    $query = strtoupper(trim($_GET['query'] ?? ''));

    try {
        if (empty($query)) {
            // Return all active parked vehicles for quick selection chips
            $stmt = $db->query("
                SELECT ps.session_id, ps.entry_time, ps.payment_status, ps.location_status, ps.entry_token,
                       ve.plate_number, ve.owner_name,
                       sl.slot_number, sl.zone
                FROM parking_sessions ps
                JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
                JOIN parking_slots sl ON ps.slot_id = sl.slot_id
                WHERE ps.exit_time IS NULL
                ORDER BY ps.session_id DESC
            ");
            echo json_encode(["success" => true, "active_vehicles" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // Search for specific active vehicle
        $stmt = $db->prepare("
            SELECT ps.session_id, ps.vehicle_id, ps.slot_id, ps.entry_time, ps.total_fee, 
                   ps.payment_status, ps.location_status, ps.entry_token, ps.vendor_scan_time, ps.staff_id,
                   ve.plate_number, ve.owner_name,
                   sl.slot_number, sl.zone
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE ps.exit_time IS NULL 
              AND (ve.plate_number = ? OR ps.session_id = ? OR ps.entry_token = ?)
            ORDER BY ps.session_id DESC LIMIT 1
        ");
        $stmt->execute([$query, $query, $query]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            echo json_encode(["success" => false, "error" => "No active, parked session found matching '{$query}'."]);
            exit;
        }

        // Calculate stay duration
        $entryTimestamp = strtotime($session['entry_time']);
        $now = time();
        $durationMinutes = max(1, round(($now - $entryTimestamp) / 60));
        $durationHours = round($durationMinutes / 60, 1);

        // Tariff computation according to Capstone Proposal Section 5.5.6:
        // 1. Transient flat rate: ₱30.00
        $isGracePeriod = ($durationMinutes <= 15);
        $baseFee = $isGracePeriod ? 0.00 : 30.00;

        // 2. Overnight surcharge: +₱100.00 if vehicle crosses 23:00 or duration > 12 hours
        $entryHour = (int)date('H', $entryTimestamp);
        $currentHour = (int)date('H', $now);
        $daysDiff = (int)floor(($now - $entryTimestamp) / 86400);
        $isOvernight = ($daysDiff > 0 || ($entryHour < 23 && $currentHour >= 23) || $durationMinutes > 720);
        $overnightSurcharge = $isOvernight ? 100.00 : 0.00;

        // 3. Vendor validation discount (e.g. Joey's Restaurant, 20 mins free)
        $hasVendorCredit = !empty($session['vendor_scan_time']);
        $vendorCreditAmount = $hasVendorCredit ? 30.00 : 0.00;

        // 4. Prepaid online reservation
        $isPrepaid = ($session['payment_status'] === 'PAID_RESERVATION');

        // Net parking fee
        if ($isPrepaid) {
            $computedParkingFee = 0.00;
        } elseif ($isGracePeriod) {
            $computedParkingFee = 0.00;
        } elseif ($hasVendorCredit) {
            $computedParkingFee = max(0.00, $overnightSurcharge);
        } else {
            $computedParkingFee = $baseFee + $overnightSurcharge;
        }

        // 5. Query any unpaid violations for this vehicle
        $vStmt = $db->prepare("
            SELECT violation_id, violation_type, penalty_amount, issued_at 
            FROM violations 
            WHERE session_id = ? AND status = 'UNPAID'
        ");
        $vStmt->execute([$session['session_id']]);
        $unpaidViolations = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        $violationsTotal = array_sum(array_column($unpaidViolations, 'penalty_amount'));

        $totalDue = $computedParkingFee + $violationsTotal;

        echo json_encode([
            "success" => true,
            "session" => $session,
            "duration" => [
                "minutes" => $durationMinutes,
                "hours" => $durationHours,
                "human" => ($durationMinutes < 60) ? "{$durationMinutes} mins" : floor($durationMinutes/60) . "h " . ($durationMinutes%60) . "m"
            ],
            "tariff" => [
                "base_fee" => $baseFee,
                "is_grace" => $isGracePeriod,
                "is_overnight" => $isOvernight,
                "overnight_surcharge" => $overnightSurcharge,
                "has_vendor_credit" => $hasVendorCredit,
                "vendor_credit" => $vendorCreditAmount,
                "is_prepaid" => $isPrepaid,
                "parking_fee" => $computedParkingFee,
                "violations" => $unpaidViolations,
                "violations_total" => $violationsTotal,
                "total_due" => $totalDue
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Cashier Payment Processing ---
if ($method === 'POST' && $action === 'cashier_payment') {
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionId = $data['session_id'] ?? null;
    $amountPaid = floatval($data['amount_paid'] ?? 0);
    $staffId = trim($data['staff_id'] ?? 'ATT-01');
    $lostTicket = !empty($data['lost_ticket']);
    $settleViolations = !empty($data['settle_violations']);
    $paymentMethod = $data['payment_method'] ?? 'CASH';

    if (!$sessionId) {
        echo json_encode(["success" => false, "error" => "Session ID is required."]);
        exit;
    }

    try {
        $stmt = $db->prepare("
            SELECT ps.*, ve.plate_number 
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            WHERE ps.session_id = ?
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            echo json_encode(["success" => false, "error" => "Session not found."]);
            exit;
        }

        $newTotal = $amountPaid;
        $newStatus = ($amountPaid > 0) ? 'PAID' : ($session['payment_status'] === 'PAID_RESERVATION' ? 'PAID_RESERVATION' : 'BYPASSED');

        $up = $db->prepare("
            UPDATE parking_sessions 
            SET total_fee = ?, payment_status = ?, staff_id = ?
            WHERE session_id = ?
        ");
        $up->execute([$newTotal, $newStatus, $staffId, $sessionId]);

        if ($settleViolations) {
            $vUp = $db->prepare("UPDATE violations SET status = 'PAID' WHERE session_id = ? AND status = 'UNPAID'");
            $vUp->execute([$sessionId]);
        }

        // Write to audit trail
        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'CASHIER_PAYMENT', 'parking_session', ?, ?)
        ");
        $details = "Payment of ₱" . number_format($amountPaid, 2) . " received via {$paymentMethod} by Staff {$staffId}. Vehicle plate: {$session['plate_number']}. Financial status: CLEARED.";
        $audit->execute([$staffId, $sessionId, $details]);

        echo json_encode([
            "success" => true,
            "session_id" => $sessionId,
            "plate_number" => $session['plate_number'],
            "entry_token" => $session['entry_token'],
            "status" => "CLEARED",
            "message" => "Payment cleared successfully. Vehicle authorized for exit barrier."
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Exit Barrier Clearance (Priority 1: ALPR Optical Scan, Fallback: QR Scan) ---
if ($method === 'POST' && $action === 'exit_barrier_clearance') {
    $data = json_decode(file_get_contents('php://input'), true);
    $clearanceMode = strtoupper(trim($data['method'] ?? 'ALPR')); // 'ALPR' (Priority) or 'QR_FALLBACK'
    $plate = strtoupper(trim($data['plate_number'] ?? ''));
    $tokenOrSession = trim($data['token_or_session'] ?? '');
    $staffId = trim($data['staff_id'] ?? 'ATT-01');

    try {
        $session = null;

        if ($clearanceMode === 'ALPR') {
            if (empty($plate)) {
                echo json_encode(["success" => false, "barrier_actuated" => false, "error" => "ALPR Optical Scan error: No plate string received from camera."]);
                exit;
            }

            // Lookup active session by License Plate (Priority 1)
            $stmt = $db->prepare("
                SELECT ps.session_id, ps.slot_id, ps.total_fee, ps.payment_status, ps.entry_token,
                       ve.plate_number, sl.slot_number, sl.zone
                FROM parking_sessions ps
                JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
                JOIN parking_slots sl ON ps.slot_id = sl.slot_id
                WHERE ve.plate_number = ? AND ps.exit_time IS NULL
                ORDER BY ps.session_id DESC LIMIT 1
            ");
            $stmt->execute([$plate]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode([
                    "success" => false, 
                    "barrier_actuated" => false, 
                    "error" => "ALPR optical detection failed to match active parking record for plate '{$plate}'. Please use QR Fallback."
                ]);
                exit;
            }
        } else {
            // Fallback Priority 2: QR Token or Session ID
            if (empty($tokenOrSession)) {
                echo json_encode(["success" => false, "barrier_actuated" => false, "error" => "Fallback error: QR Token or Session ID required."]);
                exit;
            }

            $stmt = $db->prepare("
                SELECT ps.session_id, ps.slot_id, ps.total_fee, ps.payment_status, ps.entry_token,
                       ve.plate_number, sl.slot_number, sl.zone
                FROM parking_sessions ps
                JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
                JOIN parking_slots sl ON ps.slot_id = sl.slot_id
                WHERE (ps.entry_token = ? OR ps.session_id = ? OR ve.plate_number = ?) AND ps.exit_time IS NULL
                ORDER BY ps.session_id DESC LIMIT 1
            ");
            $stmt->execute([$tokenOrSession, $tokenOrSession, $tokenOrSession]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode([
                    "success" => false, 
                    "barrier_actuated" => false, 
                    "error" => "Invalid QR token or session ID. No active parking session found."
                ]);
                exit;
            }
        }

        // Financial Clearance Check
        $clearedStatuses = ['PAID', 'PAID_RESERVATION', 'PAID_OVERNIGHT', 'PAID_VENDOR', 'BYPASSED'];
        $isCleared = in_array($session['payment_status'], $clearedStatuses);

        if (!$isCleared) {
            echo json_encode([
                "success" => false,
                "barrier_actuated" => false,
                "session_id" => $session['session_id'],
                "plate_number" => $session['plate_number'],
                "error" => "BARRIER DENIED: Vehicle payment status is {$session['payment_status']}. Direct driver to cashier booth to settle payment."
            ]);
            exit;
        }

        // Close session, mark exited, free slot, decrement zone occupancy
        $now = date('Y-m-d H:i:s');
        $up = $db->prepare("
            UPDATE parking_sessions 
            SET exit_time = ?, location_status = 'EXITED', exit_method = ?, staff_id = ?
            WHERE session_id = ?
        ");
        $up->execute([$now, $clearanceMode, $staffId, $session['session_id']]);

        // Free slot
        $freeSlot = $db->prepare("UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ?");
        $freeSlot->execute([$session['slot_id']]);

        // Decrement zone active parked count
        $decZone = $db->prepare("UPDATE spatial_allocation SET active_parked_count = MAX(0, active_parked_count - 1) WHERE zone_id = ?");
        $decZone->execute([$session['zone']]);

        // Write to audit logs
        $methodLabel = ($clearanceMode === 'ALPR') ? "ALPR Camera (Priority)" : "QR Token Scanner (Fallback)";
        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'EXIT_CLEARANCE', 'parking_session', ?, ?)
        ");
        $auditDetails = "Vehicle {$session['plate_number']} cleared via {$methodLabel}. Exit barrier ACTUATED: OPEN. Slot {$session['slot_number']} (Zone {$session['zone']}) freed.";
        $audit->execute([$staffId, $session['session_id'], $auditDetails]);

        echo json_encode([
            "success" => true,
            "barrier_actuated" => true,
            "clearance_mode" => $clearanceMode,
            "session_id" => $session['session_id'],
            "plate_number" => $session['plate_number'],
            "slot_number" => $session['slot_number'],
            "zone" => $session['zone'],
            "exit_time" => $now,
            "message" => "Exit barrier actuated: OPEN ({$methodLabel} verified). Vehicle departs."
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "barrier_actuated" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Cashier Recent Transactions ---
if ($method === 'GET' && $action === 'cashier_recent_transactions') {
    try {
        $stmt = $db->query("
            SELECT ps.session_id, ps.entry_time, ps.exit_time, ps.total_fee, 
                   ps.payment_status, ps.exit_method, ps.staff_id,
                   ve.plate_number, sl.slot_number, sl.zone
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE ps.exit_time IS NOT NULL
            ORDER BY ps.exit_time DESC
            LIMIT 15
        ");
        echo json_encode(["success" => true, "transactions" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// Catch-all for undefined routes
echo json_encode(["error" => "Invalid API route requested."]);
exit;
?>
