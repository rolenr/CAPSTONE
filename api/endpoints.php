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
    $db = new PDO('sqlite:../parking.db');
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
                "license_plate" => $user['plate_number'],
                "is_vip" => (bool)($user['is_vip'] ?? 0)
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
        $totalSlots = $db->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();
        $occupiedSlots = $db->query("SELECT COUNT(*) FROM parking_slots WHERE is_occupied = 1")->fetchColumn();
        $pendingViolations = $db->query("SELECT COUNT(*) FROM violations WHERE status = 'PENDING'")->fetchColumn();

        echo json_encode([
            "success" => true,
            "total" => (int)$totalSlots,
            "occupied" => (int)$occupiedSlots,
            "available" => (int)($totalSlots - $occupiedSlots),
            "violations" => (int)$pendingViolations
        ]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Failed to fetch stats: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: ALPR / Parking Session Logs ---
if ($method === 'GET' && $action === 'alpr_logs') {
    try {
        $stmt = $db->query("
            SELECT ps.entry_time AS timestamp, ve.plate_number, sl.slot_number, ps.payment_status AS status
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            ORDER BY ps.entry_time DESC
            LIMIT 10
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

    if (!$slotId) {
        echo json_encode(["error" => "Slot ID required."]);
        exit;
    }

    $stmt = $db->prepare("UPDATE parking_slots SET is_occupied = ? WHERE slot_id = ?");
    if ($stmt->execute([$isOccupied, $slotId])) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["error" => "Failed to update slot status."]);
    }
    exit;
}

// --- ROUTE: Violations Tracker ---
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

// Catch-all for undefined routes
echo json_encode(["error" => "Invalid API route requested."]);
exit;
?>