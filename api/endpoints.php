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

    // Insert Reservation & Generate Token
    $token = "QR_" . uniqid();
    $insert = $db->prepare("
        INSERT INTO reservations (license_plate, zone_id, start_date, duration_days, payment_status, token_id) 
        VALUES (?, ?, CURRENT_DATE, ?, 'PAID_RESERVATION', ?)
    ");
    
    if ($insert->execute([$plate, $zone, $duration, $token])) {
        echo json_encode(["success" => true, "qr_token" => $token, "fee" => $totalAmount]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to secure database transaction."]);
    }
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
