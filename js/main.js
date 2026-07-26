// --- DOM Elements ---
const authForm = document.getElementById('authForm');
const registerForm = document.getElementById('registerForm'); // Added for registration
const liveMap = document.getElementById('liveMap');
const reservationForm = document.getElementById('reservationForm');
const reservationFormContainer = document.getElementById('reservationFormContainer');
const durationInput = document.getElementById('duration');
const feeDisplay = document.getElementById('feeDisplay');
const qrWalletSection = document.getElementById('qrWalletSection');
const qrCodeImage = document.getElementById('qrCodeImage');
const qrZone = document.getElementById('qrZone');
const qrSlotNumber = document.getElementById('qrSlotNumber');
const qrDates = document.getElementById('qrDates');
const qrFee = document.getElementById('qrFee');
const violationForm = document.getElementById('violationForm');
const violationResults = document.getElementById('violationResults');
const logoutBtn = document.getElementById('logoutBtn');

// --- Global State ---
// Pull user data from the browser's session storage so it survives page reloads
let currentUser = {
    isVIP: sessionStorage.getItem('isVIP') === 'true',
    licensePlate: sessionStorage.getItem('licensePlate') || ''
};

// ---------------- AUTH (LOGIN) ----------------
if (authForm) {
    authForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;

        try {
            const response = await fetch('api/endpoints.php?action=login', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }) 
            });
            const data = await response.json();

            if (data.success) {
                // Save to session memory
                sessionStorage.setItem('licensePlate', data.license_plate);
                sessionStorage.setItem('isVIP', data.is_vip);
                
                // Physically route the browser to the dashboard
                window.location.href = 'dashboard.html';
            } else {
                alert(data.error || 'Invalid credentials');
            }
        } catch (error) {
            console.error(error);
            alert('Server communication failed.');
        }
    });
}

// ---------------- AUTH (REGISTER) ----------------
if (registerForm) {
    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('regEmail').value;
        const password = document.getElementById('regPassword').value;
        const plate = document.getElementById('regPlate').value;

        try {
            const response = await fetch('api/endpoints.php?action=register', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password, plate }) 
            });
            const data = await response.json();

            if (data.success) {
                alert('Account created successfully! Please log in.');
                window.location.href = 'login.html';
            } else {
                alert(data.error || 'Registration failed.');
            }
        } catch (error) {
            console.error(error);
            alert('Server communication failed.');
        }
    });
}

// ---------------- LOGOUT ----------------
if (logoutBtn) {
    logoutBtn.addEventListener('click', (e) => {
        e.preventDefault();
        sessionStorage.clear(); // Wipe the saved plate
        window.location.href = 'login.html'; // Send back to login
    });
}

// ---------------- MAP ----------------
async function fetchLiveMap() {
    if (!liveMap) return;

    try {
        const response = await fetch('api/endpoints.php?action=map');
        const data = await response.json();

        const zones = {};

        // Group slots by zone
        data.forEach(slot => {
            if (!zones[slot.zone]) {
                zones[slot.zone] = [];
            }
            zones[slot.zone].push(slot);
        });

        let mapHTML = "";

        Object.keys(zones).forEach(zone => {

            mapHTML += `
                <div class="zone-card">
                    <h3>Zone ${zone}</h3>
            `;

            zones[zone].forEach(slot => {

                mapHTML += `
                    <div class="slot-row">
                        <span>${slot.slot_number}</span>

                        <span class="${slot.is_occupied == 1 ? 'occupied' : 'free'}"></span>

                        <span>${slot.is_occupied == 1 ? 'Occupied' : 'Free'}</span>
                    </div>
                `;

            });

            mapHTML += `</div>`;
        });

        liveMap.innerHTML = mapHTML;

    } catch (error) {
        console.error(error);
        liveMap.innerHTML = "<p>Error loading live map.</p>";
    }
}

// Auto-load map if we are on the dashboard
if (liveMap) {
    fetchLiveMap();
}

// ---------------- BILLING & DATE LOGIC ----------------
const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');

// Default both date inputs to today, and prevent picking a date in the past.
// (Doing this in JS instead of a hardcoded HTML value keeps it correct on
// whatever day the page is actually opened.)
if (startDate && endDate) {
    const todayStr = new Date().toISOString().split('T')[0];
    startDate.min = todayStr;
    endDate.min = todayStr;
    startDate.value = todayStr;
    endDate.value = todayStr;
}

function calculateDays() {
    const start = new Date(startDate.value);
    const end = new Date(endDate.value);
    
    // Calculate difference in milliseconds and convert to days
    let diffTime = end - start;
    let days = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 to include start day

    if (days < 1) days = 1;
    if (days > 3) {
        alert("Maximum reservation period is 3 days.");
        endDate.value = startDate.value; // Reset end date
        days = 1;
    }

    // Fee calculation logic
    const base_fee = 60.00 * days;
    const surcharge = (days >= 2) ? 100.00 : 0.00;
    feeDisplay.textContent = (base_fee + surcharge).toFixed(2);
    
    return days;
}

if (startDate && endDate) {
    startDate.addEventListener('change', () => {
        endDate.min = startDate.value; // can't pick an end date before the new start date
        calculateDays();
    });
    endDate.addEventListener('change', calculateDays);
}

// ---------------- RESERVATION DETAILS / QR RENDERING ----------------
function renderReservationDetails(data, plate) {
    if (!qrCodeImage || !qrWalletSection) return;

    // Encode the reservation id + plate (not just the raw token) into the QR
    const qrPayload = JSON.stringify({
        reservation_id: data.reservation_id,
        plate: plate
    });

    qrCodeImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(qrPayload)}`;

    if (qrZone) qrZone.textContent = data.zone || '';
    if (qrSlotNumber) qrSlotNumber.textContent = data.slot || '';
    if (qrDates) qrDates.textContent = `${data.start_date} to ${data.end_date}`;
    if (qrFee) qrFee.textContent = Number(data.fee).toFixed(2);

    // Show the QR/details view, hide the booking form
    qrWalletSection.style.display = 'block';
    if (reservationFormContainer) reservationFormContainer.style.display = 'none';
}

// If we're on the reservation page, check whether this plate already has
// an active reservation, and if so, show the QR + details instead of the form.
async function checkActiveReservation() {
    if (!reservationForm || !currentUser.licensePlate) return;

    try {
        const response = await fetch(`api/endpoints.php?action=active_reservation&plate=${encodeURIComponent(currentUser.licensePlate)}`);
        const data = await response.json();

        if (data.success && data.active) {
            renderReservationDetails(data, currentUser.licensePlate);
        }
    } catch (error) {
        console.error('Failed to check active reservation:', error);
    }
}

checkActiveReservation();

// ---------------- RESERVATION ----------------
if (reservationForm) {
    reservationForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Ensure user is actually logged in
        if (!currentUser.licensePlate) {
            alert("Session expired. Please log in again.");
            window.location.href = 'login.html';
            return;
        }

        const days = calculateDays(); 
        const zone = document.getElementById('zoneSelect').value;
        // Grab the dates from the new inputs
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        try {
            const response = await fetch('api/endpoints.php?action=reserve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    plate: currentUser.licensePlate, 
                    zone: zone, 
                    days: days,
                    startDate: startDate, // Added
                    endDate: endDate      // Added
                })
            });
            const data = await response.json();

            if (data.success) {
                alert(`Payment of ₱${data.fee.toFixed(2)} processed successfully!`);
                // Render the digital QR wallet with slot allocation details
                renderReservationDetails(data, currentUser.licensePlate);
            } else {
                alert(data.error || 'Reservation failed.');
            }
        } catch (error) {
            alert('Failed to process reservation.');
        }
    });
}

// ---------------- VIOLATIONS ----------------
if (violationForm) {
    violationForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        const plate = document.getElementById("searchPlate").value;

        try {
            const response = await fetch(`api/endpoints.php?action=violations&plate=${encodeURIComponent(plate)}`);
            const data = await response.json();

            if (!data || data.length === 0) {
                violationResults.innerHTML = "<p style='color: green;'>No outstanding violations found.</p>";
                return;
            }

            let html = "";
            data.forEach(v => {
                html += `
                    <div class="card" style="background-color: #ffeaea; border: 1px solid red;">
                        <h3 style="color: red;">${v.violation_type}</h3>
                        <p><strong>Penalty:</strong> ₱${parseFloat(v.fine_amount).toFixed(2)}</p>
                        <p><strong>Status:</strong> ${v.status}</p>
                        <p><strong>Issued:</strong> ${v.issued_at}</p>
                    </div>
                `;
            });
            violationResults.innerHTML = html;
        } catch (err) {
            console.error(err);
            violationResults.innerHTML = "<p>Failed to load violations.</p>";
        }
    });
}

// ---------------- ADMIN PORTAL ----------------
const adminPage = document.querySelector('body[data-page="admin"]');

async function loadAdminStats() {
    try {
        const response = await fetch('api/endpoints.php?action=admin_stats');
        const data = await response.json();

        if (data.success) {
            document.getElementById('statTotalCapacity').textContent = data.total;
            document.getElementById('statOccupied').textContent = data.occupied;
            document.getElementById('statAvailable').textContent = data.available;
            document.getElementById('statViolations').textContent = data.violations;
        }
    } catch (err) {
        console.error('Error fetching admin stats:', err);
    }
}

async function loadAlprLogs() {
    const alprTable = document.getElementById('alprLogsTable');
    if (!alprTable) return;

    try {
        const response = await fetch('api/endpoints.php?action=alpr_logs');
        const logs = await response.json();

        if (!logs || logs.length === 0) {
            alprTable.innerHTML = `<tr><td colspan="5">No active detections found.</td></tr>`;
            return;
        }

        alprTable.innerHTML = logs.map(log => {
            const isPaid = log.status === 'PAID_RESERVATION' || log.status === 'PAID';
            return `
                <tr>
                    <td>${log.timestamp || 'N/A'}</td>
                    <td><strong>${log.plate_number}</strong></td>
                    <td>Slot ${log.slot_number || 'Unassigned'}</td>
                    <td>98.5%</td>
                    <td class="status-text ${isPaid ? 'available' : 'occupied'}">
                        ${log.status}
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading ALPR logs:', err);
    }
}

async function loadAdminMap() {
    const adminMap = document.getElementById('adminMapControls');
    if (!adminMap) return;

    try {
        const response = await fetch('api/endpoints.php?action=map');
        const slots = await response.json();

        if (!slots || slots.length === 0) {
            adminMap.innerHTML = "<p>No slots found in database.</p>";
            return;
        }

        adminMap.innerHTML = slots.map(slot => {
            const isOccupied = slot.is_occupied == 1;
            const statusClass = isOccupied ? 'slot-occupied' : 'slot-available';
            const plateDisplay = isOccupied && slot.plate_number ? slot.plate_number : '—';

            return `
                <div class="admin-slot-card ${statusClass}">
                    <strong>Zone ${slot.zone} - ${slot.slot_number}</strong>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-top: 4px;">
                        ${plateDisplay}
                    </div>
                    <p class="status-text ${statusClass}">
                        ${isOccupied ? 'Occupied' : 'Available'}
                    </p>
                    <button class="btn-action" onclick="toggleSlotOverride(${slot.slot_id}, ${isOccupied ? 0 : 1})">
                        ${isOccupied ? 'Force Free' : 'Force Occupied'}
                    </button>
                </div>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading admin map controls:', err);
    }
}

async function toggleSlotOverride(slotId, newOccupiedState) {
    try {
        const response = await fetch('api/endpoints.php?action=toggle_slot', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slot_id: slotId, is_occupied: newOccupiedState })
        });
        const data = await response.json();

        if (data.success) {
            loadAdminStats();
            loadAdminMap();
        } else {
            alert(data.error || 'Failed to update slot.');
        }
    } catch (err) {
        alert('Server connection failed.');
    }
}

// Auto-run if on admin.html
if (adminPage) {
    loadAdminStats();
    loadAlprLogs();
    loadAdminMap();
}