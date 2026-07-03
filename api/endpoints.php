<?php
// Initialize SQLite Connection
try {
    $db = new PDO('sqlite:../database/parking_central.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["error" => "Database connection failed."]));
}

// Ensure the request returns JSON
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

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
    $data = json_decode(file_get_contents('php://input'), true);
    
    $plate = $data['plate'];
    $zone = $data['zone'];
    $duration = (int)$data['days'];
    $baseFee = 60.00;
    $totalAmount = $baseFee * $duration;

    // Verify Zone A restrictions
    if ($zone === 'A') {
        $vipCheck = $db->prepare("SELECT is_vip FROM accounts WHERE license_plate = ?");
        $vipCheck->execute([$plate]);
        $user = $vipCheck->fetch();
        if (!$user || !$user['is_vip']) {
            http_response_code(403);
            echo json_encode(["error" => "Zone A is restricted to PWD/VIP."]);
            exit;
        }
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
    $plate = $_GET['plate'] ?? '';
    
    $stmt = $db->prepare("SELECT violation_type, fine_amount, status, image_proof_path FROM violations WHERE license_plate = ? AND status = 'UNPAID'");
    $stmt->execute([$plate]);
    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($violations);
    exit;
}
?>
