CREATE TABLE vehicles (
    vehicle_id INTEGER PRIMARY KEY AUTOINCREMENT,
    plate_number TEXT NOT NULL UNIQUE,
    owner_name TEXT,
    vehicle_type TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE accounts (
    account_id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    plate_number TEXT UNIQUE NOT NULL,
    is_vip INTEGER DEFAULT 0, 
    is_admin INTEGER DEFAULT 0,
    role TEXT NOT NULL DEFAULT 'DRIVER' CHECK (role IN ('ADMIN', 'DRIVER', 'CASHIER', 'VENDOR')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(plate_number) REFERENCES vehicles(plate_number)
);


CREATE TABLE spatial_allocation (
    zone_id TEXT PRIMARY KEY,
    max_capacity INTEGER NOT NULL,
    active_parked_count INTEGER DEFAULT 0,
    active_reservation_count INTEGER DEFAULT 0
);

CREATE TABLE parking_slots (
    slot_id INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_number TEXT NOT NULL UNIQUE,
    zone TEXT NOT NULL,
    slot_type TEXT,
    is_occupied INTEGER DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE parking_sessions (
    session_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER,
    slot_id INTEGER,
    entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME,
    total_fee REAL DEFAULT 0,
    payment_status TEXT DEFAULT 'UNPAID',
    location_status TEXT DEFAULT 'PARKED',
    entry_token TEXT,
    vendor_scan_time DATETIME,
    staff_id TEXT,
    exit_method TEXT,
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
    FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
);

CREATE TABLE reservations (
    reservation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER,
    slot_id INTEGER,
    reservation_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_time DATETIME,
    status TEXT DEFAULT 'PENDING_PAYMENT',
    payment_state TEXT NOT NULL DEFAULT 'UNVERIFIED',
    token_id TEXT,
    start_date datetime, 
    end_date datetime,
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
    FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
);

CREATE TABLE reservation_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reservation_id INTEGER NOT NULL UNIQUE,
    provider TEXT NOT NULL DEFAULT 'PAYMONGO',
    checkout_session_id TEXT UNIQUE,
    checkout_url TEXT,
    payment_id TEXT UNIQUE,
    amount_centavos INTEGER NOT NULL,
    currency TEXT NOT NULL DEFAULT 'PHP',
    status TEXT NOT NULL DEFAULT 'CREATING',
    refund_status TEXT,
    refund_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id)
);

CREATE TABLE paymongo_webhook_events (
    event_id TEXT PRIMARY KEY,
    event_type TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE xendit_webhook_events (
    event_id TEXT PRIMARY KEY,
    event_type TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_reservations_hold ON reservations(status, expiry_time);
CREATE UNIQUE INDEX idx_one_open_booking_per_slot ON reservations(slot_id) WHERE status IN ('ACTIVE','PENDING_PAYMENT');

CREATE TABLE violations (
    violation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER,
    violation_type TEXT,
    penalty_amount REAL,
    status TEXT DEFAULT 'UNPAID',
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(session_id) REFERENCES parking_sessions(session_id)
);

CREATE TABLE audit_logs (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_email TEXT NOT NULL,
    action_type TEXT NOT NULL,
    target_entity TEXT NOT NULL,
    target_id TEXT,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
