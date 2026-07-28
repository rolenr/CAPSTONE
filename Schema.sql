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
    is_occupied INTEGER DEFAULT 0
);

CREATE TABLE parking_sessions (
    session_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER,
    slot_id INTEGER,
    entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME,
    total_fee REAL DEFAULT 0,
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
    FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
);

CREATE TABLE reservations (
    reservation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER,
    slot_id INTEGER,
    reservation_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_time DATETIME,
    status TEXT DEFAULT 'ACTIVE', 
    token_id INTEGER, 
    start_date datetime, 
    end_date datetime,
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
    FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
);

CREATE TABLE violations (
    violation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER,
    violation_type TEXT,
    penalty_amount REAL,
    status TEXT DEFAULT 'UNPAID',
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(session_id) REFERENCES parking_sessions(session_id)
);