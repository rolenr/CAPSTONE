// --- DOM Elements ---
const authForm = document.getElementById('authForm');
const registerForm = document.getElementById('registerForm');
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
                sessionStorage.setItem('licensePlate', data.license_plate);
                sessionStorage.setItem('isVIP', data.is_vip);
                sessionStorage.setItem('isAdmin', data.is_admin);

                if (data.is_admin) {
                    window.location.href = 'admin.html';
                } else {
                    window.location.href = 'dashboard.html';
                }
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
        sessionStorage.clear();
        window.location.href = 'login.html';
    });
}

// ---------------- MAP ----------------
const zonePositions = {
    'E': { top: 34, left: 30.6 },
    'D': { top: 34, left: 43.5 },
    'B': { top: 34, left: 53.6 },
    'A': { top: 34, left: 67.8 },
    'C': { top: 8,  left: 51.3 },
    'F': { top: 30, left: 85.9 }
};

const zoneStrips = {
    'E': { axis: 'vertical',   fixed: 30.6, from: 40, to: 95 },
    'D': { axis: 'vertical',   fixed: 43.5, from: 40, to: 95 },
    'B': { axis: 'vertical',   fixed: 53.6, from: 40, to: 95 },
    'A': { axis: 'vertical',   fixed: 67.8, from: 40, to: 95 },
    'C': { axis: 'horizontal', fixed: 21,   from: 40, to: 62 },
    'F': { axis: 'horizontal', fixed: 34,   from: 75, to: 98 }
};

function getSlotDotPositions(zone, count) {
    const strip = zoneStrips[zone];
    if (!strip || count <= 0) return [];

    const positions = [];
    for (let i = 0; i < count; i++) {
        const ratio = count === 1 ? 0.5 : i / (count - 1);
        const along = strip.from + (strip.to - strip.from) * ratio;

        if (strip.axis === 'vertical') {
            positions.push({ top: along, left: strip.fixed });
        } else {
            positions.push({ top: strip.fixed, left: along });
        }
    }
    return positions;
}

let zonesCache = {};

async function fetchLiveMap() {
    if (!liveMap) return;

    try {
        const response = await fetch('api/endpoints.php?action=map');
        const data = await response.json();

        const zones = {};

        data.forEach(slot => {
            if (!zones[slot.zone]) {
                zones[slot.zone] = [];
            }
            zones[slot.zone].push(slot);
        });

        zonesCache = zones;

        let markersHTML = `<img src="images/parking-lot-map.png" alt="Parking Lot Zone Map" class="lot-photo">`;
        let fallbackHTML = "";

        Object.keys(zones).forEach(zone => {
            const slots = zones[zone];
            const total = slots.length;
            const free = slots.filter(s => s.is_occupied != 1).length;
            const pos = zonePositions[zone];

            if (pos) {
                markersHTML += `
                    <button type="button" class="zone-marker ${free > 0 ? 'available' : 'full'}"
                        style="top:${pos.top}%; left:${pos.left}%;"
                        data-zone="${zone}">
                        <span class="zone-marker-label">Zone ${zone}</span>
                        <span class="zone-marker-count">${free}/${total} free</span>
                    </button>
                `;

                if (zone !== 'F') {
                    const dotPositions = getSlotDotPositions(zone, slots.length);
                    slots.forEach((slot, i) => {
                        const dp = dotPositions[i];
                        if (!dp) return;
                        const isOccupied = slot.is_occupied == 1;
                        const label = `Zone ${zone} - ${slot.slot_number}: ${isOccupied ? 'Occupied' : 'Free'}`;

                        markersHTML += `
                            <button type="button" class="slot-dot ${isOccupied ? 'occupied' : 'free'}"
                                style="top:${dp.top}%; left:${dp.left}%;"
                                data-zone="${zone}"
                                title="${label}"
                                aria-label="${label}">
                            </button>
                        `;
                    });
                }
            } else {
                fallbackHTML += `
                    <div class="zone-card">
                        <h3>Zone ${zone}</h3>
                        ${slots.map(slot => `
                            <div class="slot-row">
                                <span>${slot.slot_number}</span>
                                <span class="${slot.is_occupied == 1 ? 'occupied' : 'free'}"></span>
                                <span>${slot.is_occupied == 1 ? 'Occupied' : 'Free'}</span>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
        });

        liveMap.innerHTML = `<div class="lot-photo-wrap">${markersHTML}</div>` +
            (fallbackHTML ? `<div class="map-grid">${fallbackHTML}</div>` : '');

        liveMap.querySelectorAll('.zone-marker, .slot-dot').forEach(btn => {
            btn.addEventListener('click', () => showZoneDetail(btn.dataset.zone));
        });

    } catch (error) {
        console.error(error);
        liveMap.innerHTML = "<p>Error loading live map.</p>";
    }
}

function showZoneDetail(zone) {
    const panel = document.getElementById('zoneDetailPanel');
    if (!panel || !zonesCache[zone]) return;

    const slots = zonesCache[zone];

    panel.innerHTML = `
        <h3>Zone ${zone} — Slot Details</h3>
        <div class="zone-detail-slots">
            ${slots.map(slot => `
                <div class="slot-row">
                    <span>${slot.slot_number}</span>
                    <span class="${slot.is_occupied == 1 ? 'occupied' : 'free'}"></span>
                    <span>${slot.is_occupied == 1 ? `Occupied${slot.plate_number ? ' &ndash; ' + slot.plate_number : ''}` : 'Free'}</span>
                </div>
            `).join('')}
        </div>
    `;
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

if (liveMap) {
    fetchLiveMap();
}

// ---------------- BILLING & DATE LOGIC ----------------
const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');

if (startDate && endDate) {
    const todayStr = new Date().toISOString().split('T')[0];
    startDate.min = todayStr;
    endDate.min = todayStr;
    if (!startDate.value) startDate.value = todayStr;
    if (!endDate.value) endDate.value = todayStr;
}

function calculateDays() {
    if (!startDate || !endDate) return 1;
    const start = new Date(startDate.value);
    const end = new Date(endDate.value);
    
    let diffTime = end - start;
    let days = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    if (days < 1) days = 1;
    if (days > 3) {
        alert("Maximum reservation period is 3 days.");
        endDate.value = startDate.value;
        days = 1;
    }

    const base_fee = 60.00 * days;
    const surcharge = (days >= 2) ? 100.00 : 0.00;
    if (feeDisplay) feeDisplay.textContent = (base_fee + surcharge).toFixed(2);
    
    return days;
}

if (startDate && endDate) {
    startDate.addEventListener('change', () => {
        endDate.min = startDate.value;
        calculateDays();
    });
    endDate.addEventListener('change', calculateDays);
}

// ---------------- RESERVATION DETAILS / QR RENDERING ----------------
function renderReservationDetails(data, plate) {
    if (!qrWalletSection) return;

    const qrPayload = JSON.stringify({
        reservation_id: data.reservation_id,
        plate: plate
    });

    if (qrCodeImage) {
        qrCodeImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(qrPayload)}`;
    }

    if (qrZone) qrZone.textContent = data.zone || '';
    if (qrSlotNumber) qrSlotNumber.textContent = data.slot || '';
    if (qrDates) qrDates.textContent = `${data.start_date} to ${data.end_date}`;
    if (qrFee) qrFee.textContent = Number(data.fee).toFixed(2);

    qrWalletSection.style.display = 'block';
    if (reservationFormContainer) reservationFormContainer.style.display = 'none';
}

async function checkActiveReservation() {
    const walletOrForm = document.getElementById('qrWalletSection') || document.getElementById('reservationForm');
    if (!walletOrForm || !currentUser.licensePlate) return;

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
        
        if (!currentUser.licensePlate) {
            alert("Session expired. Please log in again.");
            window.location.href = 'login.html';
            return;
        }

        const zone = document.getElementById('zoneSelect').value;

        // STRICT VIP VALIDATION CHECK - Intercepts non-VIP users instantly
        if (zone === 'E' && !currentUser.isVIP) {
            alert("Error: Zone E reservations are restricted to VIP accounts only.");
            return; // Stops the form from proceeding to the backend
        }

        const days = calculateDays(); 
        const sDate = document.getElementById('startDate').value;
        const eDate = document.getElementById('endDate').value;

        try {
            const response = await fetch('api/endpoints.php?action=reserve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    plate: currentUser.licensePlate, 
                    zone: zone, 
                    days: days,
                    startDate: sDate,
                    endDate: eDate
                })
            });
            const data = await response.json();

            if (data.success) {
                alert(`Payment of ₱${data.fee.toFixed(2)} processed successfully!`);
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
                    <div class="card" style="background-color: #ffeaea; border: 1px solid red; margin-bottom: 12px; padding: 12px;">
                        <h3 style="color: red; margin: 0 0 8px 0;">${v.violation_type}</h3>
                        <p><strong>Penalty:</strong> ₱${parseFloat(v.penalty_amount || 0).toFixed(2)}</p>
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
            if (document.getElementById('statTotalCapacity')) document.getElementById('statTotalCapacity').textContent = data.total;
            if (document.getElementById('statOccupied')) document.getElementById('statOccupied').textContent = data.occupied;
            if (document.getElementById('statAvailable')) document.getElementById('statAvailable').textContent = data.available;
            if (document.getElementById('statViolations')) document.getElementById('statViolations').textContent = data.violations;
            if (document.getElementById('statReservations')) document.getElementById('statReservations').textContent = data.active_reservations;
        }
    } catch (err) {
        console.error('Error fetching admin stats:', err);
    }
}

// ---------------- ADMIN: ONGOING RESERVATIONS ----------------
async function loadAdminReservations() {
    const table = document.getElementById('adminReservationsTable');
    if (!table) return;

    try {
        const response = await fetch('api/endpoints.php?action=admin_reservations');
        const data = await response.json();

        if (!data.success) {
            table.innerHTML = `<tr><td colspan="7">${data.error || 'Failed to load reservations.'}</td></tr>`;
            return;
        }

        const reservations = data.reservations;

        if (!reservations || reservations.length === 0) {
            table.innerHTML = `<tr><td colspan="7">No ongoing reservations.</td></tr>`;
            return;
        }

        table.innerHTML = reservations.map(r => `
            <tr>
                <td><strong>${r.plate_number}</strong></td>
                <td>${r.email || '—'} ${r.is_vip ? '<span style="color: var(--primary); font-weight: bold;">(VIP)</span>' : ''}</td>
                <td>Zone ${r.zone} - ${r.slot_number}</td>
                <td>${r.start_date || 'N/A'}</td>
                <td>${r.end_date || 'N/A'}</td>
                <td>₱${Number(r.fee).toFixed(2)}</td>
                <td>
                    <button class="btn-action" onclick="overrideReservation(${r.reservation_id})">Override / Cancel</button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error('Error loading admin reservations:', err);
        table.innerHTML = `<tr><td colspan="7">Failed to load reservations.</td></tr>`;
    }
}

async function overrideReservation(reservationId) {
    if (!confirm('Override this reservation? This will cancel it and free the assigned slot.')) return;

    try {
        const response = await fetch('api/endpoints.php?action=override_reservation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reservation_id: reservationId, status: 'CANCELLED', release_slot: true })
        });
        const data = await response.json();

        if (data.success) {
            loadAdminReservations();
            loadAdminStats();
            loadAdminMap();
        } else {
            alert(data.error || 'Failed to override reservation.');
        }
    } catch (err) {
        alert('Server connection failed.');
    }
}

// ---------------- ADMIN: ACCOUNT / VIP MANAGEMENT ----------------
async function loadAdminAccounts(search = '') {
    const table = document.getElementById('adminAccountsTable');
    if (!table) return;

    try {
        const url = 'api/endpoints.php?action=admin_accounts' + (search ? `&search=${encodeURIComponent(search)}` : '');
        const response = await fetch(url);
        const data = await response.json();

        if (!data.success) {
            table.innerHTML = `<tr><td colspan="5">${data.error || 'Failed to load accounts.'}</td></tr>`;
            return;
        }

        const accounts = data.accounts;

        if (!accounts || accounts.length === 0) {
            table.innerHTML = `<tr><td colspan="5">No accounts found.</td></tr>`;
            return;
        }

        table.innerHTML = accounts.map(a => {
            const isVip = Number(a.is_vip) === 1;
            const isAdmin = Number(a.is_admin) === 1;
            return `
                <tr>
                    <td>${a.email}</td>
                    <td>${a.plate_number}</td>
                    <td class="status-text ${isVip ? 'slot-available' : 'slot-occupied'}">${isVip ? 'VIP' : 'Standard'}</td>
                    <td>${isAdmin ? 'Admin' : 'User'}</td>
                    <td>
                        <button class="btn-action" onclick="setVip(${a.account_id}, ${isVip ? 0 : 1})">
                            ${isVip ? 'Remove VIP' : 'Make VIP'}
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading admin accounts:', err);
        table.innerHTML = `<tr><td colspan="5">Failed to load accounts.</td></tr>`;
    }
}

async function setVip(accountId, newVipState) {
    try {
        const response = await fetch('api/endpoints.php?action=set_vip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ account_id: accountId, is_vip: newVipState })
        });
        const data = await response.json();

        if (data.success) {
            const searchInput = document.getElementById('accountSearchInput');
            loadAdminAccounts(searchInput ? searchInput.value.trim() : '');
        } else {
            alert(data.error || 'Failed to update VIP status.');
        }
    } catch (err) {
        alert('Server connection failed.');
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

        // Group slots by zone, preserving sorted zone order
        const zones = {};
        slots.forEach(slot => {
            if (!zones[slot.zone]) zones[slot.zone] = [];
            zones[slot.zone].push(slot);
        });

        adminMap.innerHTML = Object.keys(zones).sort().map(zone => {
            const zoneSlots = zones[zone];
            const occupiedCount = zoneSlots.filter(s => s.is_occupied == 1).length;
            const totalCount = zoneSlots.length;

            const cards = zoneSlots.map(slot => {
                const isOccupied = slot.is_occupied == 1;
                const statusClass = isOccupied ? 'slot-occupied' : 'slot-available';
                const plateDisplay = isOccupied && slot.plate_number ? slot.plate_number : '—';

                return `
                    <div class="admin-slot-card ${statusClass}">
                        <strong>${slot.slot_number}</strong>
                        <div class="slot-plate">${plateDisplay}</div>
                        <p class="status-text ${statusClass}">
                            ${isOccupied ? 'Occupied' : 'Available'}
                        </p>
                        <button class="btn-action" onclick="toggleSlotOverride(${slot.slot_id}, ${isOccupied ? 0 : 1})">
                            ${isOccupied ? 'Force Free' : 'Force Occupied'}
                        </button>
                    </div>
                `;
            }).join('');

            return `
                <div class="zone-section">
                    <div class="zone-header">
                        <h3>Zone ${zone}</h3>
                        <span class="zone-badge">${totalCount - occupiedCount} / ${totalCount} available</span>
                    </div>
                    <div class="admin-grid">
                        ${cards}
                    </div>
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

const accountSearchInput = document.getElementById('accountSearchInput');
if (accountSearchInput) {
    let searchDebounce;
    accountSearchInput.addEventListener('input', (e) => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => loadAdminAccounts(e.target.value.trim()), 300);
    });
}

if (adminPage) {
    loadAdminStats();
    loadAlprLogs();
    loadAdminMap();
    loadAdminReservations();
    loadAdminAccounts();
}
