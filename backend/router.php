<?php
declare(strict_types=1);

// Use with: php -S 127.0.0.1:8080 backend/router.php
// The API checks each action separately. This router also protects HTML pages
// before the development server sends their contents to the browser.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode((string)$path);
$requestedFile = realpath(__DIR__ . '/../' . ltrim($path, '/'));
foreach ([realpath(__DIR__ . '/database'), realpath(__DIR__ . '/../.git')] as $privateDirectory) {
    if ($privateDirectory && $requestedFile &&
        ($requestedFile === $privateDirectory || str_starts_with($requestedFile, $privateDirectory . DIRECTORY_SEPARATOR))) {
        http_response_code(404);
        return true;
    }
}
$page = basename((string)$path);
$directory = dirname((string)$path);

$pages = [
    'admin.html' => ['ADMIN'],
    'cashier.html' => ['CASHIER', 'ADMIN'],
    'vendor.html' => ['VENDOR', 'ADMIN'],
    'dashboard.html' => ['DRIVER', 'ADMIN'],
    'live-map.html' => ['DRIVER', 'ADMIN'],
    'wallet.html' => ['DRIVER', 'ADMIN'],
    'reserve.html' => ['DRIVER', 'ADMIN'],
    'violations.html' => ['DRIVER', 'ADMIN'],
    'demo-checkout.html' => ['DRIVER', 'ADMIN'],
];

if (!in_array($directory, ['/', '/frontend'], true) || !str_ends_with($page, '.html')) {
    return false;
}
if (in_array($page, ['index.html', 'login.html', 'register.html'], true)) {
    return false;
}
if (!isset($pages[$page])) {
    http_response_code(404);
    return true;
}

$isSecure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $isSecure,
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
$user = $_SESSION['auth_user'] ?? null;
$expired = !$user ||
    time() - (int)($_SESSION['last_activity'] ?? 0) > 1800 ||
    time() - (int)($_SESSION['authenticated_at'] ?? 0) > 43200;
if ($expired) {
    header('Location: /frontend/login.html', true, 302);
    return true;
}

try {
    $dbPath = getenv('PARKING_DB_PATH') ?: __DIR__ . '/database/parking.db';
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $lookup = $db->prepare('SELECT role FROM accounts WHERE account_id = ?');
    $lookup->execute([(int)$user['account_id']]);
    $role = $lookup->fetchColumn();
} catch (PDOException $error) {
    http_response_code(503);
    echo 'The application database is unavailable.';
    return true;
}

if (!in_array($role, $pages[$page], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access denied for this account role.';
    return true;
}

session_write_close();
return false;
