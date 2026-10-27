<?php
// 1. Force PHP to output errors as JSON instead of HTML to prevent frontend crashes
error_reporting(E_ALL);
ini_set('display_errors', 0);
// Keep persisted application timestamps in UTC; the UI presents them in PHT.
date_default_timezone_set('UTC');
header('Content-Type: application/json');

// Authentication is backed by a server-side PHP session. The browser only holds
// the opaque session cookie; role and ownership decisions never come from it.
$isSecureRequest = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isSecureRequest,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(["error" => "PHP Error: $errstr on line $errline"]);
    exit;
});
set_exception_handler(function($e) {
    echo json_encode(["error" => "Exception: " . $e->getMessage()]);
    exit;
});

function normalizeLicensePlate($value) {
    if (!is_string($value)) return '';
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value)));
}

function isValidLicensePlate($value) {
    return is_string($value) && preg_match('/^[A-Z]{3}[0-9]{3,4}$/D', $value) === 1;
}

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

    // Upgrade older local databases on first API request without replacing data.
    $accountColumns = array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('role', $accountColumns, true)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN role TEXT NOT NULL DEFAULT 'DRIVER'");
        $db->exec("UPDATE accounts SET role = CASE WHEN COALESCE(is_admin, 0) = 1 THEN 'ADMIN' ELSE 'DRIVER' END");
    }
} catch (PDOException $e) {
    echo json_encode(["error" => "Database connection failed."]);
    exit;
}

// 3. Define method and action BEFORE running the IF statements
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Default-deny API policy. Static pages are only a user interface; these checks
// protect the data and operations even when callers bypass the browser.
$publicActions = ['login', 'register'];
$actionRoles = [
    'session' => ['*'],
    'logout' => ['*'],
    'map' => ['DRIVER', 'ADMIN'],
    'reserve' => ['DRIVER', 'ADMIN'],
    'active_reservation' => ['DRIVER', 'ADMIN'],
    'violations' => ['DRIVER', 'ADMIN'],
    'vendor_lookup' => ['VENDOR', 'ADMIN'],
    'vendor_validate' => ['VENDOR', 'ADMIN'],
    'vendor_recent_validations' => ['VENDOR', 'ADMIN'],
    'vendor_active_vehicles' => ['VENDOR', 'ADMIN'],
    'checkout' => ['CASHIER', 'ADMIN'],
    'admin_stats' => ['ADMIN'],
    'admin_analytics' => ['ADMIN'],
    'admin_reservations' => ['ADMIN'],
    'override_reservation' => ['ADMIN'],
    'admin_accounts' => ['ADMIN'],
    'set_vip' => ['ADMIN'],
    'set_account_role' => ['ADMIN'],
    'alpr_logs' => ['ADMIN'],
    'toggle_slot' => ['ADMIN'],
    'admin_audit_logs' => ['ADMIN'],
    'admin_violations_list' => ['ADMIN'],
    'admin_resolve_violation' => ['ADMIN'],
    'cashier_lookup' => ['CASHIER', 'ADMIN'],
    'cashier_payment' => ['CASHIER', 'ADMIN'],
    'exit_barrier_clearance' => ['CASHIER', 'ADMIN'],
    'confirm_vehicle_departure' => ['CASHIER', 'ADMIN'],
    'cashier_recent_transactions' => ['CASHIER', 'ADMIN']
];

if (!in_array($action, $publicActions, true)) {
    if (!array_key_exists($action, $actionRoles)) {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid API route requested.']);
        exit;
    }

    $sessionUser = $_SESSION['auth_user'] ?? null;
    if (!$sessionUser) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required. Please sign in again.']);
        exit;
    }

    $now = time();
    if (($now - (int)($_SESSION['last_activity'] ?? 0)) > 1800 || ($now - (int)($_SESSION['authenticated_at'] ?? 0)) > 43200) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['error' => 'Your session expired. Please sign in again.']);
        exit;
    }

    // Re-read the role so privilege changes take effect on the next request.
    $identity = $db->prepare('SELECT account_id, email, plate_number, role, is_vip FROM accounts WHERE account_id = ?');
    $identity->execute([(int)$sessionUser['account_id']]);
    $currentIdentity = $identity->fetch(PDO::FETCH_ASSOC);
    if (!$currentIdentity) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['error' => 'This account is no longer available.']);
        exit;
    }
    $_SESSION['auth_user'] = [
        'account_id' => (int)$currentIdentity['account_id'],
        'email' => $currentIdentity['email'],
        'plate_number' => $currentIdentity['plate_number'],
        'role' => strtoupper($currentIdentity['role']),
        'is_vip' => (bool)$currentIdentity['is_vip']
    ];
    $_SESSION['last_activity'] = $now;
    $sessionUser = $_SESSION['auth_user'];

    $requiredRoles = $actionRoles[$action];
    if (!in_array('*', $requiredRoles, true) && !in_array($sessionUser['role'], $requiredRoles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to perform this action.']);
        exit;
    }

    if ($method === 'POST') {
        $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expectedToken = $_SESSION['csrf_token'] ?? '';
        if (!$providedToken || !$expectedToken || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token. Refresh your session and try again.']);
            exit;
        }
    }
}

// Values used in audit trails are taken from the authenticated session, never
// from client-supplied admin_email or staff_id fields.
$actorEmail = $_SESSION['auth_user']['email'] ?? '';

function isExitPaymentCleared($paymentStatus, $vendorScanTime = null) {
    $clearedStatuses = ['PAID', 'BYPASSED', 'PAID_OVERNIGHT', 'PAID_RESERVATION'];
    if (in_array($paymentStatus, $clearedStatuses, true)) return true;
    if ($paymentStatus !== 'PAID_VENDOR' || empty($vendorScanTime)) return false;

    $vendorTimestamp = strtotime($vendorScanTime);
    if ($vendorTimestamp === false) return false;
    $minutesSinceVendor = time() - $vendorTimestamp;
    return $minutesSinceVendor >= 0 && $minutesSinceVendor <= (20 * 60);
}

if ($action === 'session' && $method === 'GET') {
    echo json_encode([
        'success' => true,
        'user' => $_SESSION['auth_user'],
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ]);
    exit;
}

if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax'
        ]);
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// --- ROUTE: Register Account ---
if ($method === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(["error" => "Invalid data received."]);
        exit;
    }

    $email = $data['email'] ?? '';
    $password = password_hash($data['password'] ?? '', PASSWORD_DEFAULT);
    $plate = normalizeLicensePlate($data['plate'] ?? '');
    if (!isValidLicensePlate($plate)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter three letters followed by three or four digits, without spaces (for example, ABC1234).']);
        exit;
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT OR IGNORE INTO vehicles (plate_number) VALUES (?)");
        $stmt->execute([$plate]);

        $stmt = $db->prepare("INSERT INTO accounts (email, password_hash, plate_number, role, is_admin) VALUES (?, ?, ?, 'DRIVER', 0)");
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
            $role = strtoupper($user['role'] ?? ((int)($user['is_admin'] ?? 0) === 1 ? 'ADMIN' : 'DRIVER'));
            if (!in_array($role, ['ADMIN', 'DRIVER', 'CASHIER', 'VENDOR'], true)) $role = 'DRIVER';
            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'account_id' => (int)$user['account_id'],
                'email' => $user['email'],
                'plate_number' => $user['plate_number'],
                'role' => $role,
                'is_vip' => (bool)($user['is_vip'] ?? 0)
            ];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['authenticated_at'] = time();
            $_SESSION['last_activity'] = time();
            echo json_encode([
                "success" => true,
                "email" => $user['email'],
                "license_plate" => $user['plate_number'],
                "is_vip" => (bool)($user['is_vip'] ?? 0),
                "is_admin" => $role === 'ADMIN',
                "role" => $role,
                "csrf_token" => $_SESSION['csrf_token']
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

    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (($_SESSION['auth_user']['role'] ?? '') !== 'ADMIN') {
        foreach ($slots as &$slot) unset($slot['plate_number']);
        unset($slot);
    }
    echo json_encode($slots);
    exit;
}

// --- ROUTE: Handle Reservation ---
if ($method === 'POST' && $action === 'reserve') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'A reservation request must be a JSON object.']);
        exit;
    }
    $isAdmin = (($_SESSION['auth_user']['role'] ?? '') === 'ADMIN');

    $providedPlate = $data['plate'] ?? $data['plate_number'] ?? '';
    if ($isAdmin && !is_string($providedPlate)) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide a valid vehicle plate.']);
        exit;
    }
    $customPlate = $isAdmin ? normalizeLicensePlate($providedPlate) : '';
    if ($isAdmin && $customPlate !== '') {
        $plate = $customPlate;
    } else {
        $plate = normalizeLicensePlate($_SESSION['auth_user']['plate_number'] ?? '');
    }

    if ($plate === '') {
        http_response_code(400);
        echo json_encode(["error" => "A registered vehicle plate is required."]);
        exit;
    }
    $zone = $data['zone'] ?? '';
    $startDate = $data['startDate'] ?? '';
    $endDate = $data['endDate'] ?? '';
    $replaceExisting = $isAdmin && ($data['replace_existing'] ?? false) === true;
    $start = is_string($startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $startDate)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $startDate) : false;
    $end = is_string($endDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $endDate)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) : false;
    if (!isValidLicensePlate($plate) ||
        !in_array($zone, ['A', 'B', 'C', 'D', 'E'], true) ||
        !$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $end < $start) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide a valid plate, zone, and reservation date range.']);
        exit;
    }
    // Calculate the tariff duration on the server rather than trusting client days.
    $days = (int)$start->diff($end)->days + 1;
    if ($days > 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Reservations are limited to three days.']);
        exit;
    }

    // VIP SECURITY CHECK
    $accountCheck = $db->prepare("SELECT is_vip FROM accounts WHERE UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?");
    $accountCheck->execute([$plate]);
    $account = $accountCheck->fetch(PDO::FETCH_ASSOC);

    $is_vip = ($isAdmin || ($account && (int)$account['is_vip'] === 1)) ? 1 : 0;

    if ($zone === 'E' && $is_vip !== 1) {
        http_response_code(403);
        echo json_encode(["error" => "Zone E reservations are restricted to VIP accounts only."]);
        exit;
    }

    // END VIP SECURITY CHECK

    try {
        $db->beginTransaction();
        $vehicle = $db->prepare("SELECT vehicle_id FROM vehicles WHERE UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?");
        $vehicle->execute([$plate]);
        $vehicle = $vehicle->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            if ($isAdmin) {
                $createVeh = $db->prepare("INSERT INTO vehicles (plate_number) VALUES (?)");
                $createVeh->execute([$plate]);
                $vehicleId = $db->lastInsertId();
            } else {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(["error" => "Vehicle not registered."]);
                exit;
            }
        } else {
            $vehicleId = $vehicle['vehicle_id'];
        }

        $overlapCheck = $db->prepare("SELECT reservation_id FROM reservations WHERE vehicle_id = ? AND status = 'ACTIVE'");
        $overlapCheck->execute([$vehicleId]);
        $oldReservationIds = $overlapCheck->fetchAll(PDO::FETCH_COLUMN);
        if ($oldReservationIds && !$replaceExisting) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => $isAdmin
                ? 'This vehicle has an active reservation. Explicitly confirm replacement to change it.'
                : 'You already have an active reservation.']);
            exit;
        }

        $slot = $db->prepare("SELECT s.slot_id, s.slot_number FROM parking_slots s
            WHERE s.zone = ? AND s.is_occupied = 0 AND NOT EXISTS (
                SELECT 1 FROM reservations r WHERE r.slot_id = s.slot_id AND r.status = 'ACTIVE'
                AND (r.vehicle_id <> ? OR ? = 0)
            ) ORDER BY s.slot_number LIMIT 1");
        $slot->execute([$zone, $vehicleId, $replaceExisting ? 1 : 0]);
        $slot = $slot->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(["error" => "No available slots in Zone " . $zone]);
            exit;
        }

        if ($oldReservationIds) {
            $expireOld = $db->prepare("UPDATE reservations SET status = 'CANCELLED' WHERE vehicle_id = ? AND status = 'ACTIVE'");
            $expireOld->execute([$vehicleId]);
        }

        $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));
        $token = 'QR_' . bin2hex(random_bytes(16));

        $reserve = $db->prepare("
            INSERT INTO reservations(vehicle_id, slot_id, expiry_time, status, token_id, start_date, end_date)
            VALUES(?, ?, ?, 'ACTIVE', ?, ?, ?)
        ");

        $reserve->execute([$vehicleId, $slot["slot_id"], $expiry, $token, $startDate, $endDate]);
        $reservationId = $db->lastInsertId();

        $actionType = $oldReservationIds ? 'RESERVATION_REPLACE' : 'RESERVATION_CREATE';
        $details = "Reservation #{$reservationId} for {$plate}: Zone {$zone}, Slot {$slot['slot_number']}, {$startDate} to {$endDate}.";
        if ($oldReservationIds) $details .= ' Replaced reservation(s): ' . implode(', ', $oldReservationIds) . '.';
        if ($isAdmin && $zone === 'E' && (!$account || !(int)$account['is_vip'])) $details .= ' Administrator overrode the VIP restriction.';
        $audit = $db->prepare('INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details) VALUES (?, ?, ?, ?, ?)');
        $audit->execute([$actorEmail, $actionType, 'reservation', $reservationId, $details]);
        $db->commit();

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
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to save this reservation. No booking changes were applied.']);
    }
    exit;
}

// --- ROUTE: Check Active Reservation ---
if ($method === 'GET' && $action === 'active_reservation') {
    $requestedPlate = normalizeLicensePlate($_GET['plate'] ?? '');
    if (($_SESSION['auth_user']['role'] ?? '') === 'ADMIN' && $requestedPlate !== '') {
        $plate = $requestedPlate;
    } else {
        $plate = normalizeLicensePlate($_SESSION['auth_user']['plate_number'] ?? '');
    }

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
        WHERE UPPER(REPLACE(REPLACE(v.plate_number, ' ', ''), '-', '')) = ? AND r.status = 'ACTIVE'
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

// --- ROUTE: Commercial Tenant Lookup (Plate or Token) ---
if (($method === 'GET' || $method === 'POST') && $action === 'vendor_lookup') {
    $inputVal = trim($_GET['query'] ?? '');
    if (empty($inputVal) && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $inputVal = trim($body['query'] ?? $body['plate_number'] ?? $body['qr_token'] ?? '');
    }

    if (empty($inputVal)) {
        echo json_encode(["success" => false, "error" => "License plate or QR Token is required for lookup."]);
        exit;
    }

    try {
        $cleanPlate = normalizeLicensePlate($inputVal);
        $stmt = $db->prepare("
            SELECT ps.session_id, ps.entry_time, ps.payment_status, ps.location_status,
                   ps.entry_token, ps.vendor_scan_time, ps.total_fee,
                   ve.plate_number, ve.vehicle_type, ve.owner_name,
                   sl.slot_number, sl.zone
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE (ps.entry_token = ? OR UPPER(REPLACE(REPLACE(ve.plate_number, ' ', ''), '-', '')) = ?)
              AND ps.location_status = 'PARKED'
            ORDER BY ps.session_id DESC LIMIT 1
        ");
        $stmt->execute([$inputVal, $cleanPlate]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            echo json_encode([
                "success" => false,
                "error" => "No active parked session found for '{$inputVal}'. The vehicle may have already exited or was not recorded."
            ]);
            exit;
        }

        $now = time();
        $entryTs = strtotime($session['entry_time']);
        $elapsedMins = max(0, (int)round(($now - $entryTs) / 60));

        $isValidated = !empty($session['vendor_scan_time']);
        $remainingGraceMins = 0;
        if ($isValidated) {
            $scanTs = strtotime($session['vendor_scan_time']);
            $elapsedSinceScan = max(0, (int)round(($now - $scanTs) / 60));
            $remainingGraceMins = max(0, 20 - $elapsedSinceScan);
        }

        echo json_encode([
            "success" => true,
            "session" => $session,
            "duration" => [
                "minutes" => $elapsedMins,
                "human" => ($elapsedMins < 60) ? "{$elapsedMins} mins" : floor($elapsedMins / 60) . "h " . ($elapsedMins % 60) . "m"
            ],
            "validation" => [
                "is_validated" => $isValidated,
                "vendor_scan_time" => $session['vendor_scan_time'],
                "remaining_grace_minutes" => $remainingGraceMins,
                "is_grace_active" => ($remainingGraceMins > 0),
                "subsidy_value" => 30.00
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Vendor Digital Validation (Apply 20-min Exemption) ---
if ($method === 'POST' && $action === 'vendor_validate') {
    $data = json_decode(file_get_contents('php://input'), true);
    $inputVal = trim($data['token_or_plate'] ?? $data['qr_token'] ?? $data['plate_number'] ?? '');
    $tenantName = $actorEmail;
    $staffName = $actorEmail;
    $receiptNo = trim($data['receipt_number'] ?? "RCP-" . rand(10000, 99999));

    if (empty($inputVal)) {
        echo json_encode(["success" => false, "error" => "Customer QR Token or License Plate is required."]);
        exit;
    }

    try {
        $cleanPlate = normalizeLicensePlate($inputVal);
        $stmt = $db->prepare("
            SELECT ps.session_id, ps.entry_time, ps.payment_status, ps.location_status,
                   ps.entry_token, ps.vendor_scan_time,
                   ve.plate_number, sl.slot_number, sl.zone
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE (ps.entry_token = ? OR UPPER(REPLACE(REPLACE(ve.plate_number, ' ', ''), '-', '')) = ?)
              AND ps.location_status = 'PARKED'
            ORDER BY ps.session_id DESC LIMIT 1
        ");
        $stmt->execute([$inputVal, $cleanPlate]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            echo json_encode([
                "success" => false,
                "error" => "No active vehicle session found for '{$inputVal}'. The vehicle may have already departed."
            ]);
            exit;
        }

        // Apply vendor validation timestamp and update status
        $up = $db->prepare("
            UPDATE parking_sessions
            SET payment_status = 'PAID_VENDOR', vendor_scan_time = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");
        $up->execute([$session['session_id']]);

        // Write to audit trail
        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'VENDOR_VALIDATION', 'parking_session', ?, ?)
        ");
        $adminTag = $actorEmail;
        $auditDetails = "Commercial dining voucher validated for vehicle {$session['plate_number']} (Slot {$session['slot_number']}, Zone {$session['zone']}). Receipt Ref: {$receiptNo}. 20-min departure window active. Base tariff (₱30) subsidized.";
        $audit->execute([$adminTag, $session['session_id'], $auditDetails]);

        echo json_encode([
            "success" => true,
            "session_id" => $session['session_id'],
            "plate_number" => $session['plate_number'],
            "slot_number" => $session['slot_number'],
            "zone" => $session['zone'],
            "tenant_name" => $tenantName,
            "staff_name" => $staffName,
            "receipt_number" => $receiptNo,
            "grace_minutes" => 20,
            "subsidy_amount" => 30.00,
            "timestamp" => date('Y-m-d H:i:s'),
            "message" => "Validation approved! 20-minute departure window activated for vehicle {$session['plate_number']}. Base parking tariff (₱30.00) subsidized."
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Commercial Tenant Recent Validations & Stats ---
if ($method === 'GET' && $action === 'vendor_recent_validations') {
    try {
        $stmt = $db->query("
            SELECT ps.session_id, ps.entry_time, ps.vendor_scan_time, ps.exit_time,
                   ps.payment_status, ps.location_status,
                   ve.plate_number, sl.slot_number, sl.zone,
                   (SELECT details FROM audit_logs WHERE target_id = ps.session_id AND action_type = 'VENDOR_VALIDATION' ORDER BY log_id DESC LIMIT 1) as audit_detail,
                   (SELECT admin_email FROM audit_logs WHERE target_id = ps.session_id AND action_type = 'VENDOR_VALIDATION' ORDER BY log_id DESC LIMIT 1) as staff_info
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            LEFT JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE ps.vendor_scan_time IS NOT NULL
            ORDER BY ps.vendor_scan_time DESC
            LIMIT 25
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $activeGraceCount = 0;
        $formatted = [];

        foreach ($rows as $r) {
            $scanTs = strtotime($r['vendor_scan_time']);
            $elapsedMins = max(0, (int)round(($now - $scanTs) / 60));
            $remainingGrace = max(0, 20 - $elapsedMins);
            $isGraceActive = ($remainingGrace > 0 && $r['location_status'] === 'PARKED');

            if ($isGraceActive) {
                $activeGraceCount++;
            }

            // Extract receipt number from audit detail if present
            $receipt = "N/A";
            if (!empty($r['audit_detail']) && preg_match('/Receipt Ref:\s*([A-Za-z0-9\-]+)/', $r['audit_detail'], $m)) {
                $receipt = $m[1];
            }

            $formatted[] = [
                "session_id" => $r['session_id'],
                "plate_number" => $r['plate_number'],
                "slot_number" => $r['slot_number'] ?? 'N/A',
                "zone" => $r['zone'] ?? 'N/A',
                "entry_time" => $r['entry_time'],
                "vendor_scan_time" => $r['vendor_scan_time'],
                "exit_time" => $r['exit_time'],
                "location_status" => $r['location_status'],
                "elapsed_since_scan_mins" => $elapsedMins,
                "remaining_grace_mins" => $remainingGrace,
                "is_grace_active" => $isGraceActive,
                "receipt_number" => $receipt,
                "staff_info" => $r['staff_info'] ?? "Joey's Restaurant",
                "subsidy_amount" => 30.00
            ];
        }

        $totalCount = count($formatted);
        $totalSubsidy = $totalCount * 30.00;

        echo json_encode([
            "success" => true,
            "stats" => [
                "total_validated" => $totalCount,
                "active_grace_windows" => $activeGraceCount,
                "total_subsidy_php" => $totalSubsidy,
                "standard_grace_minutes" => 20
            ],
            "validations" => $formatted
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Commercial Tenant Active Parked Vehicles (for easy selection) ---
if ($method === 'GET' && $action === 'vendor_active_vehicles') {
    try {
        $stmt = $db->query("
            SELECT ps.session_id, ps.entry_time, ps.vendor_scan_time, ps.payment_status,
                   ps.entry_token, ve.plate_number, ve.vehicle_type, sl.slot_number, sl.zone
            FROM parking_sessions ps
            JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
            JOIN parking_slots sl ON ps.slot_id = sl.slot_id
            WHERE ps.location_status = 'PARKED'
            ORDER BY ps.entry_time DESC
        ");
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $result = [];
        foreach ($vehicles as $v) {
            $entryTs = strtotime($v['entry_time']);
            $stayMins = max(0, (int)round(($now - $entryTs) / 60));
            $isValidated = !empty($v['vendor_scan_time']);

            $remainingGrace = 0;
            if ($isValidated) {
                $scanTs = strtotime($v['vendor_scan_time']);
                $elapsedSinceScan = max(0, (int)round(($now - $scanTs) / 60));
                $remainingGrace = max(0, 20 - $elapsedSinceScan);
            }

            $result[] = [
                "session_id" => $v['session_id'],
                "plate_number" => $v['plate_number'],
                "slot_number" => $v['slot_number'],
                "zone" => $v['zone'],
                "entry_token" => $v['entry_token'],
                "stay_minutes" => $stayMins,
                "is_validated" => $isValidated,
                "remaining_grace" => $remainingGrace
            ];
        }

        echo json_encode(["success" => true, "vehicles" => $result]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
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

    $utcTimezone = new DateTimeZone('UTC');
    $manilaTimezone = new DateTimeZone('Asia/Manila');
    $entry_time = new DateTime($session['entry_time'], $utcTimezone);
    $exit_time = new DateTime('now', $utcTimezone);
    $entry_local_time = (clone $entry_time)->setTimezone($manilaTimezone);
    $exit_local_time = (clone $exit_time)->setTimezone($manilaTimezone);
    $interval = $entry_time->diff($exit_time);
    $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    $fee = 0.00;
    $status = $session['payment_status'];

    if ($status === 'PAID_VENDOR') {
        $vendor_time = new DateTime($session['vendor_scan_time'], $utcTimezone);
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
        $entry_date = $entry_local_time->format('Y-m-d');
        $exit_date = $exit_local_time->format('Y-m-d');
        $exit_hour = (int)$exit_local_time->format('H');

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
        $todaySessionRev = (float)$db->query("SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION') AND DATE(entry_time, '+8 hours') = DATE('now', '+8 hours')")->fetchColumn();
        $todayViolationRev = (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND DATE(issued_at, '+8 hours') = DATE('now', '+8 hours')")->fetchColumn();
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
            AND DATE(entry_time, '+8 hours') = DATE('now', '+8 hours')
        ")->fetchColumn();

        $weekRev = (float)$db->query("
            SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions
            WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION')
            AND entry_time >= datetime('now', '+8 hours', '-7 days', 'start of day', '-8 hours')
        ")->fetchColumn();

        $monthRev = (float)$db->query("
            SELECT COALESCE(SUM(total_fee), 0) FROM parking_sessions
            WHERE payment_status IN ('PAID', 'PAID_OVERNIGHT', 'PAID_RESERVATION')
            AND entry_time >= datetime('now', '+8 hours', '-30 days', 'start of day', '-8 hours')
        ")->fetchColumn();

        // Add violations to periods
        $todayRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND DATE(issued_at, '+8 hours') = DATE('now', '+8 hours')")->fetchColumn();
        $weekRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND issued_at >= datetime('now', '+8 hours', '-7 days', 'start of day', '-8 hours')")->fetchColumn();
        $monthRev += (float)$db->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM violations WHERE status = 'PAID' AND issued_at >= datetime('now', '+8 hours', '-30 days', 'start of day', '-8 hours')")->fetchColumn();

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
            SELECT CAST(strftime('%H', entry_time, '+8 hours') AS INTEGER) as hr, COUNT(*) as cnt
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
    $adminEmail = $actorEmail;

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
                SELECT account_id, email, plate_number, is_vip, is_admin, role
                FROM accounts
                WHERE email LIKE ? OR plate_number LIKE ?
                ORDER BY email ASC
            ");
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $db->query("
                SELECT account_id, email, plate_number, is_vip, is_admin, role
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

// --- ROUTE: Admin - Assign an account role ---
if ($method === 'POST' && $action === 'set_account_role') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $accountId = (int)($data['account_id'] ?? 0);
    $newRole = strtoupper(trim($data['role'] ?? ''));
    $validRoles = ['ADMIN', 'DRIVER', 'CASHIER', 'VENDOR'];

    if ($accountId < 1 || !in_array($newRole, $validRoles, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A valid account and role are required.']);
        exit;
    }

    if ($accountId === (int)($_SESSION['auth_user']['account_id'] ?? 0) && $newRole !== 'ADMIN') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'You cannot remove your own administrator role.']);
        exit;
    }

    try {
        $db->beginTransaction();
        $lookup = $db->prepare('SELECT role FROM accounts WHERE account_id = ?');
        $lookup->execute([$accountId]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Account not found.']);
            exit;
        }

        if ($existing['role'] === 'ADMIN' && $newRole !== 'ADMIN') {
            $adminCount = (int)$db->query("SELECT COUNT(*) FROM accounts WHERE role = 'ADMIN'")->fetchColumn();
            if ($adminCount <= 1) {
                $db->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'The last administrator account cannot be downgraded.']);
                exit;
            }
        }

        $update = $db->prepare('UPDATE accounts SET role = ?, is_admin = ? WHERE account_id = ?');
        $update->execute([$newRole, $newRole === 'ADMIN' ? 1 : 0, $accountId]);
        $audit = $db->prepare("INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details) VALUES (?, 'ACCOUNT_ROLE_CHANGE', 'account', ?, ?)");
        $audit->execute([$actorEmail, (string)$accountId, "Account role changed from {$existing['role']} to {$newRole} by {$actorEmail}"]);
        $db->commit();

        echo json_encode(['success' => true, 'role' => $newRole]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update account role.']);
    }
    exit;
}

// --- ROUTE: Admin - Set / Unset VIP Status ---
if ($method === 'POST' && $action === 'set_vip') {
    $data = json_decode(file_get_contents('php://input'), true);
    $accountId = $data['account_id'] ?? null;
    $isVip = array_key_exists('is_vip', $data) ? (int)!!$data['is_vip'] : null;
    $adminEmail = $actorEmail;

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
    $adminEmail = $actorEmail;

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
    $adminEmail = $actorEmail;

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
    $requestedPlate = normalizeLicensePlate($_GET['plate'] ?? '');
    if (($_SESSION['auth_user']['role'] ?? '') === 'ADMIN' && $requestedPlate !== '') {
        $plate = $requestedPlate;
    } else {
        $plate = normalizeLicensePlate($_SESSION['auth_user']['plate_number'] ?? '');
    }

    $stmt = $db->prepare("
        SELECT v.violation_type, v.penalty_amount, v.status, v.issued_at
        FROM violations v
        JOIN parking_sessions ps ON v.session_id = ps.session_id
        JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
        WHERE UPPER(REPLACE(REPLACE(ve.plate_number, ' ', ''), '-', '')) = ?
    ");

    $stmt->execute([$plate]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- ROUTE: Cashier Lookup (Active Sessions & Tariff Computation) ---
if ($method === 'GET' && $action === 'cashier_lookup') {
    $rawQuery = trim($_GET['query'] ?? '');
    $query = strtoupper($rawQuery);
    $plateQuery = normalizeLicensePlate($rawQuery);

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
              AND (UPPER(REPLACE(REPLACE(ve.plate_number, ' ', ''), '-', '')) = ? OR ps.session_id = ? OR ps.entry_token = ?)
            ORDER BY ps.session_id DESC LIMIT 1
        ");
        $stmt->execute([$plateQuery, $query, $query]);
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
        $manilaTimezone = new DateTimeZone('Asia/Manila');
        $entryLocalTime = (new DateTimeImmutable('@' . $entryTimestamp))->setTimezone($manilaTimezone);
        $currentLocalTime = (new DateTimeImmutable('@' . $now))->setTimezone($manilaTimezone);
        $entryHour = (int)$entryLocalTime->format('H');
        $currentHour = (int)$currentLocalTime->format('H');
        $daysDiff = (int)floor(($now - $entryTimestamp) / 86400);
        $crossedLocalDate = $entryLocalTime->format('Y-m-d') !== $currentLocalTime->format('Y-m-d');
        $isOvernight = ($crossedLocalDate || ($entryHour < 23 && $currentHour >= 23) || $daysDiff > 0 || $durationMinutes > 720);
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
    $staffId = $actorEmail;
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

// --- ROUTE: Exit Clearance Decision (plate lookup, with issued QR fallback) ---
if ($method === 'POST' && $action === 'exit_barrier_clearance') {
    $data = json_decode(file_get_contents('php://input'), true);
    $clearanceMode = strtoupper(trim($data['method'] ?? 'ALPR'));
    $plate = normalizeLicensePlate($data['plate_number'] ?? '');
    $qrToken = trim($data['qr_token'] ?? $data['token_or_session'] ?? '');
    $staffId = $actorEmail;
    $auditMethod = in_array($clearanceMode, ['ALPR', 'QR_FALLBACK'], true) ? $clearanceMode : 'INVALID';

    // Do not include raw QR tokens or arbitrary user input in audit details.
    // The database timestamp and authenticated staff identity complete the record.
    $logClearanceAttempt = function($outcome, $paymentStatus, $reason, $sessionId = null) use ($db, $staffId, $auditMethod) {
        $details = "outcome={$outcome}; method={$auditMethod}; payment_status={$paymentStatus}; reason={$reason}";
        $stmt = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'EXIT_CLEARANCE', ?, ?, ?)
        ");
        $stmt->execute([
            $staffId,
            $sessionId ? 'parking_session' : 'exit_clearance_attempt',
            $sessionId ? (string)$sessionId : null,
            $details
        ]);
    };

    try {
        $session = null;

        if ($clearanceMode === 'ALPR') {
            if (empty($plate)) {
                $logClearanceAttempt('NOT_CLEARED', 'UNKNOWN', 'missing_plate');
                echo json_encode(["success" => false, "clearance_allowed" => false, "decision" => "NOT_CLEARED", "error" => "No plate number was provided."]);
                exit;
            }

            // Match the exact plate to a vehicle with a currently parked session.
            $stmt = $db->prepare("
                SELECT ps.session_id, ps.slot_id, ps.total_fee, ps.payment_status, ps.entry_token,
                       ps.vendor_scan_time, ps.location_status,
                       ve.plate_number, sl.slot_number, sl.zone
                FROM parking_sessions ps
                JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
                JOIN parking_slots sl ON ps.slot_id = sl.slot_id
                WHERE UPPER(REPLACE(REPLACE(ve.plate_number, ' ', ''), '-', '')) = ?
                  AND ps.exit_time IS NULL AND ps.location_status = 'PARKED'
                ORDER BY ps.session_id DESC LIMIT 1
            ");
            $stmt->execute([$plate]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                $logClearanceAttempt('NOT_CLEARED', 'UNKNOWN', 'no_active_session_for_plate');
                echo json_encode([
                    "success" => false,
                    "clearance_allowed" => false,
                    "decision" => "NOT_CLEARED",
                    "error" => "No active parked session matched that plate. Verify the plate or use the issued entry QR token."
                ]);
                exit;
            }
        } elseif ($clearanceMode === 'QR_FALLBACK') {
            if (empty($qrToken)) {
                $logClearanceAttempt('NOT_CLEARED', 'UNKNOWN', 'missing_entry_qr_token');
                echo json_encode(["success" => false, "clearance_allowed" => false, "decision" => "NOT_CLEARED", "error" => "Scan the issued entry QR token to use fallback clearance."]);
                exit;
            }

            // A QR fallback is accepted only when it matches the issued entry token.
            // Session IDs and plate numbers are not credentials for this path.
            $stmt = $db->prepare("
                SELECT ps.session_id, ps.slot_id, ps.total_fee, ps.payment_status, ps.entry_token,
                       ps.vendor_scan_time, ps.location_status,
                       ve.plate_number, sl.slot_number, sl.zone
                FROM parking_sessions ps
                JOIN vehicles ve ON ps.vehicle_id = ve.vehicle_id
                JOIN parking_slots sl ON ps.slot_id = sl.slot_id
                WHERE ps.entry_token = ?
                  AND ps.exit_time IS NULL AND ps.location_status = 'PARKED'
                ORDER BY ps.session_id DESC LIMIT 1
            ");
            $stmt->execute([$qrToken]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                $logClearanceAttempt('NOT_CLEARED', 'UNKNOWN', 'entry_token_not_for_active_session');
                echo json_encode([
                    "success" => false,
                    "clearance_allowed" => false,
                    "decision" => "NOT_CLEARED",
                    "error" => "That entry QR token does not match an active parked session."
                ]);
                exit;
            }
        } else {
            http_response_code(400);
            $logClearanceAttempt('NOT_CLEARED', 'UNKNOWN', 'unsupported_clearance_method');
            echo json_encode(["success" => false, "clearance_allowed" => false, "decision" => "NOT_CLEARED", "error" => "Unsupported clearance method."]);
            exit;
        }

        $isCleared = isExitPaymentCleared($session['payment_status'], $session['vendor_scan_time']);

        if (!$isCleared) {
            $denialReason = $session['payment_status'] === 'PAID_VENDOR'
                ? 'vendor_departure_window_expired_or_invalid'
                : 'payment_status_not_allowed';
            $logClearanceAttempt('NOT_CLEARED', $session['payment_status'], $denialReason, $session['session_id']);
            echo json_encode([
                "success" => false,
                "clearance_allowed" => false,
                "decision" => "NOT_CLEARED",
                "session_id" => $session['session_id'],
                "plate_number" => $session['plate_number'],
                "payment_status" => $session['payment_status'],
                "error" => "Not cleared to exit. Payment status is {$session['payment_status']}; direct the driver to the cashier."
            ]);
            exit;
        }

        // Record the decision only. The guard operates the physical barrier;
        // this check does not confirm that the vehicle actually departed.
        $logClearanceAttempt('CLEARED_TO_EXIT', $session['payment_status'], 'eligible_status_and_active_session', $session['session_id']);

        echo json_encode([
            "success" => true,
            "clearance_allowed" => true,
            "decision" => "CLEARED_TO_EXIT",
            "clearance_mode" => $clearanceMode,
            "session_id" => $session['session_id'],
            "plate_number" => $session['plate_number'],
            "payment_status" => $session['payment_status'],
            "slot_number" => $session['slot_number'],
            "zone" => $session['zone'],
            "message" => "Cleared to exit. Guard may operate the barrier manually; departure has not yet been recorded."
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "clearance_allowed" => false, "decision" => "NOT_CLEARED", "error" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

// --- ROUTE: Staff Confirmation of Physical Vehicle Departure ---
if ($method === 'POST' && $action === 'confirm_vehicle_departure') {
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionId = filter_var($data['session_id'] ?? null, FILTER_VALIDATE_INT);
    $staffId = $actorEmail;

    if (!$sessionId || $sessionId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A valid session ID is required.']);
        exit;
    }

    try {
        $db->beginTransaction();

        $sessionStmt = $db->prepare("
            SELECT ps.session_id, ps.slot_id, ps.payment_status, ps.vendor_scan_time,
                   ve.plate_number, sl.slot_number, sl.zone, sl.is_occupied
            FROM parking_sessions ps
            JOIN vehicles ve ON ve.vehicle_id = ps.vehicle_id
            JOIN parking_slots sl ON sl.slot_id = ps.slot_id
            WHERE ps.session_id = ? AND ps.exit_time IS NULL AND ps.location_status = 'PARKED'
        ");
        $sessionStmt->execute([$sessionId]);
        $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No active parked session was found.']);
            exit;
        }

        $clearanceStmt = $db->prepare("
            SELECT details
            FROM audit_logs
            WHERE action_type = 'EXIT_CLEARANCE'
              AND target_entity = 'parking_session'
              AND target_id = ?
            ORDER BY log_id DESC
            LIMIT 1
        ");
        $clearanceStmt->execute([(string)$sessionId]);
        $lastClearance = $clearanceStmt->fetchColumn();
        if (!is_string($lastClearance) || strpos($lastClearance, 'outcome=CLEARED_TO_EXIT;') !== 0 ||
            !preg_match('/(?:^|;\s*)method=(ALPR|QR_FALLBACK)(?:;|$)/', $lastClearance, $methodMatch)) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Run and pass an exit clearance check before confirming departure.']);
            exit;
        }

        if (!isExitPaymentCleared($session['payment_status'], $session['vendor_scan_time'])) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Payment status is no longer valid for exit clearance.']);
            exit;
        }

        if ((int)$session['is_occupied'] !== 1) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'The assigned parking slot is not marked occupied. Reconcile the slot before confirming departure.']);
            exit;
        }

        $zoneStmt = $db->prepare('SELECT zone_id FROM spatial_allocation WHERE zone_id = ?');
        $zoneStmt->execute([$session['zone']]);
        if (!$zoneStmt->fetchColumn()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Zone occupancy is not configured; departure was not recorded.']);
            exit;
        }

        $exitMethod = $methodMatch[1];
        $exitTime = date('Y-m-d H:i:s');
        $updateSession = $db->prepare("
            UPDATE parking_sessions
            SET exit_time = ?, location_status = 'EXITED', exit_method = ?, staff_id = ?
            WHERE session_id = ? AND exit_time IS NULL AND location_status = 'PARKED'
        ");
        $updateSession->execute([$exitTime, $exitMethod, $staffId, $sessionId]);
        if ($updateSession->rowCount() !== 1) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'The session changed before departure could be recorded.']);
            exit;
        }

        $freeSlot = $db->prepare('UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ? AND is_occupied = 1');
        $freeSlot->execute([$session['slot_id']]);
        if ($freeSlot->rowCount() !== 1) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'The parking slot could not be released; departure was not recorded.']);
            exit;
        }

        $decrementZone = $db->prepare("UPDATE spatial_allocation SET active_parked_count = MAX(0, active_parked_count - 1) WHERE zone_id = ?");
        $decrementZone->execute([$session['zone']]);

        $audit = $db->prepare("
            INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details)
            VALUES (?, 'EXIT_DEPARTURE_CONFIRMED', 'parking_session', ?, ?)
        ");
        $auditDetails = "Staff confirmed vehicle {$session['plate_number']} passed the exit after {$exitMethod} clearance. Session exited; slot {$session['slot_number']} (Zone {$session['zone']}) released.";
        $audit->execute([$staffId, (string)$sessionId, $auditDetails]);

        $db->commit();
        echo json_encode([
            'success' => true,
            'session_id' => (int)$sessionId,
            'plate_number' => $session['plate_number'],
            'slot_number' => $session['slot_number'],
            'zone' => $session['zone'],
            'exit_time' => $exitTime,
            'message' => 'Departure recorded and parking slot released.'
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to record vehicle departure.']);
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
