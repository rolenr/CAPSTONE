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
    $password = password_hash($data['password'] ?? '', PASSWORD_DEFAULT); // Secure hashing
    $plate = strtoupper(trim($data['plate'] ?? ''));
    
    try {
        $db->beginTransaction();
        
        // 1. Insert into vehicles table first (to satisfy the Foreign Key constraint)
        // Note: Using 'plate_number' to match your reservation logic below
        $stmt = $db->prepare("INSERT OR IGNORE INTO vehicles (plate_number) VALUES (?)");
        $stmt->execute([$plate]);
        
        // 2. Create the user account linked to that single plate
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
        
        // Verify password against the hashed database entry
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

// =====================================================================
// UNCHANGED CODE BELOW: Map, Reservations, and Violations left exactly as is
// =====================================================================

// --- ROUTE: Live Map True Availability ---
if ($method === 'GET' && $action === 'map') {
    // Computes True Availability: Max Capacity - (Physically Parked + Active Prepaid Reservations)
    $stmt = $db->query("
        SELECT 
            zone_id, 
            max_capacity, 
            (max_capacity - active_parked_count - active_reservation_count) AS true_available 
        FROM spatial_allocation
    ");
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($zones);
    exit;
}

// --- ROUTE: Handle Reservation ---
if ($method === 'POST' && $action === 'reserve') {

    $data = json_decode(file_get_contents("php://input"), true);

    $plate = strtoupper(trim($data["plate"]));
    $zone = $data["zone"];
    $days = (int)$data["days"];

    // Find vehicle
    $vehicle = $db->prepare("
        SELECT vehicle_id
        FROM vehicles
        WHERE plate_number = ?
    ");

    $vehicle->execute([$plate]);

    $vehicle = $vehicle->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle) {

        echo json_encode([
            "success"=>false,
            "message"=>"Vehicle not registered."
        ]);

        exit;
    }

    // Find first available slot
    $slot = $db->prepare("
        SELECT slot_id, slot_number
        FROM parking_slots
        WHERE zone = ?
        AND is_occupied = 0
        AND slot_id NOT IN (

            SELECT slot_id
            FROM reservations
            WHERE status='ACTIVE'

        )

        LIMIT 1
    ");

    $slot->execute([$zone]);

    $slot = $slot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {

        echo json_encode([
            "success"=>false,
            "message"=>"No available slots in Zone ".$zone
        ]);

        exit;
    }

    // Create reservation

    $expiry = date(
        "Y-m-d H:i:s",
        strtotime("+15 minutes")
    );

    $reserve = $db->prepare("

        INSERT INTO reservations(

            vehicle_id,
            slot_id,
            expiry_time,
            status

        )

        VALUES(

            ?, ?, ?, 'ACTIVE'

        )

    ");

    $reserve->execute([

        $vehicle["vehicle_id"],
        $slot["slot_id"],
        $expiry

    ]);

    $fee = $days * 60;

    echo json_encode([

        "success"=>true,
        "message"=>"Reservation Successful!",

        "slot"=>$slot["slot_number"],

        "fee"=>$fee

    ]);

    exit;
}

// --- ROUTE: Violations Tracker ---
if ($method === 'GET' && $action === 'violations') {

    $plate = strtoupper(trim($_GET['plate'] ?? ''));

    $stmt = $db->prepare("
        SELECT
            v.violation_type,
            v.penalty_amount,
            v.status,
            v.issued_at
        FROM violations v
        JOIN parking_sessions ps
            ON v.session_id = ps.session_id
        JOIN vehicles ve
            ON ps.vehicle_id = ve.vehicle_id
        WHERE ve.plate_number = ?
    ");

    $stmt->execute([$plate]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
?>
