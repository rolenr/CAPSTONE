<?php
declare(strict_types=1);

// Run explicitly from the command line. Demo credentials also appear in the
// login page's quick-fill buttons; do not use these accounts in production.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dbPath = $argv[1] ?? __DIR__ . '/parking.db';
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database does not exist: {$dbPath}\n");
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec('PRAGMA foreign_keys = ON');

try {
    $db->beginTransaction();
    $columns = $db->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_ASSOC);
    $plateColumn = null;
    foreach ($columns as $column) {
        if ($column['name'] === 'plate_number') $plateColumn = $column;
    }
    if (!$plateColumn || !in_array('role', array_column($columns, 'name'), true)) {
        throw new RuntimeException('The accounts table needs the current role schema.');
    }

    // Older databases require a vehicle plate on every account. Rebuild this
    // one table to allow staff accounts with no vehicle, preserving existing IDs.
    if ((int)$plateColumn['notnull'] === 1) {
        $db->exec("CREATE TABLE accounts_new (
            account_id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            plate_number TEXT UNIQUE,
            is_vip INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_admin INTEGER DEFAULT 0,
            role TEXT NOT NULL DEFAULT 'DRIVER' CHECK (role IN ('ADMIN','DRIVER','CASHIER','VENDOR')),
            CHECK (role <> 'DRIVER' OR plate_number IS NOT NULL),
            FOREIGN KEY(plate_number) REFERENCES vehicles(plate_number)
        )");
        $db->exec('INSERT INTO accounts_new (account_id,email,password_hash,plate_number,is_vip,created_at,is_admin,role)
                   SELECT account_id,email,password_hash,plate_number,is_vip,created_at,is_admin,role FROM accounts');
        $db->exec('DROP TABLE accounts');
        $db->exec('ALTER TABLE accounts_new RENAME TO accounts');
    }

    $accounts = [
        ['driver1@gmail.com', 'password123', 'DRIVER', 'DVR1001'],
        ['cashier@parking.test', 'cashier123', 'CASHIER', null],
        ['vendor@joeys.test', 'vendor123', 'VENDOR', null],
    ];
    $find = $db->prepare('SELECT role, password_hash, plate_number FROM accounts WHERE email = ?');
    $findVehicle = $db->prepare('SELECT 1 FROM vehicles WHERE plate_number = ?');
    $insertVehicle = $db->prepare('INSERT INTO vehicles (plate_number,owner_name,vehicle_type) VALUES (?,?,?)');
    $insert = $db->prepare('INSERT INTO accounts (email,password_hash,plate_number,is_admin,role) VALUES (?,?,?,0,?)');
    foreach ($accounts as [$email, $password, $role, $plate]) {
        $find->execute([$email]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['role'] !== $role || $existing['plate_number'] !== $plate ||
                !password_verify($password, $existing['password_hash'])) {
                throw new RuntimeException("Existing account {$email} differs from the demo account; it was not changed.");
            }
            continue;
        }
        if ($plate !== null) {
            $findVehicle->execute([$plate]);
            if ($findVehicle->fetchColumn()) {
                throw new RuntimeException("Demo plate {$plate} already exists without its demo account.");
            }
            $insertVehicle->execute([$plate, 'Demo Driver', 'Car']);
        }
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $plate, $role]);
    }

    if ($db->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_NUM)) {
        throw new RuntimeException('Foreign key check failed.');
    }
    $db->commit();
    echo "Demo driver, cashier, and tenant vendor accounts are ready.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
