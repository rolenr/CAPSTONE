CREATE TABLE vehicles (
    vehicle_id INTEGER PRIMARY KEY AUTOINCREMENT,
    plate_number TEXT NOT NULL UNIQUE,
    owner_name TEXT,
    vehicle_type TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE parking_slots (
    slot_id INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_number TEXT NOT NULL UNIQUE,
    slot_type TEXT,
    is_occupied INTEGER DEFAULT 0
);

CREATE TABLE parking_sessions (
    session_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER,
    slot_id INTEGER,
    entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME,
    total_fee REAL,
    
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(vehicle_id),
    FOREIGN KEY(slot_id) REFERENCES parking_slots(slot_id)
);

CREATE TABLE violations (
    violation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER,
    violation_type TEXT,
    penalty_amount REAL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(session_id) REFERENCES parking_sessions(session_id)
);