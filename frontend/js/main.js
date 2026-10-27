// --- API Configuration ---
const API_BASE = (function() {
    if (window.location.pathname.includes('/frontend/')) {
        return '../backend/api/endpoints.php';
    }
    return 'backend/api/endpoints.php';
})();

function normalizeLicensePlateInput(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function normalizePlateOrTokenInput(value) {
    const input = String(value || '').trim();
    if (/^QR_/i.test(input)) return input.toUpperCase().replace(/\s+/g, '');
    return normalizeLicensePlateInput(input);
}

function formatPhtDateTime(value) {
    if (!value) return '—';
    const raw = String(value).trim();
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const withTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized)
        ? normalized
        : `${normalized}Z`;
    const date = new Date(withTimezone);
    if (Number.isNaN(date.getTime())) return raw;

    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hourCycle: 'h23'
    }).formatToParts(date).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second} PHT`;
}

document.querySelectorAll('[data-license-plate-input]').forEach((input) => {
    const normalize = () => { input.value = normalizeLicensePlateInput(input.value); };
    input.addEventListener('input', normalize);
    normalize();
});

document.querySelectorAll('[data-license-plate-or-token]').forEach((input) => {
    const normalize = () => { input.value = normalizePlateOrTokenInput(input.value); };
    input.addEventListener('input', normalize);
    normalize();
});

let csrfToken = sessionStorage.getItem('csrfToken') || '';

// Centralize same-origin credentials and CSRF handling for all API requests.
async function apiFetch(url, options = {}) {
    const requestUrl = new URL(url, window.location.href);
    const action = requestUrl.searchParams.get('action');
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});

    if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && !['login', 'register'].includes(action)) {
        if (!csrfToken) {
            try {
                const sessionResponse = await fetch(`${API_BASE}?action=session`, { credentials: 'same-origin' });
                if (sessionResponse.ok) {
                    const sessionData = await sessionResponse.json();
                    csrfToken = sessionData.csrf_token || '';
                    if (csrfToken) sessionStorage.setItem('csrfToken', csrfToken);
                }
            } catch (error) {
                console.error('Unable to restore the authenticated session.', error);
            }
        }
        if (csrfToken) headers.set('X-CSRF-Token', csrfToken);
    }

    return fetch(url, { ...options, headers, credentials: 'same-origin' });
}

async function enforcePageRole() {
    const page = document.body.dataset.page || '';
    const allowedRoles = {
        dashboard: ['DRIVER', 'ADMIN'],
        'live-map': ['DRIVER', 'ADMIN'],
        wallet: ['DRIVER', 'ADMIN'],
        reserve: ['DRIVER', 'ADMIN'],
        violations: ['DRIVER', 'ADMIN'],
        admin: ['ADMIN'],
        cashier: ['CASHIER', 'ADMIN'],
        vendor: ['VENDOR', 'ADMIN']
    }[page];
    if (!allowedRoles) return;

    try {
        const response = await fetch(`${API_BASE}?action=session`, { credentials: 'same-origin' });
        if (!response.ok) {
            sessionStorage.clear();
            window.location.replace('login.html');
            return;
        }

        const sessionData = await response.json();
        const user = sessionData.user || {};
        user.plate_number = normalizeLicensePlateInput(user.plate_number);
        csrfToken = sessionData.csrf_token || '';
        currentUser.licensePlate = user.plate_number || '';
        currentUser.isVIP = Boolean(user.is_vip);
        currentUser.role = user.role || '';
        sessionStorage.setItem('csrfToken', csrfToken);
        sessionStorage.setItem('role', user.role || '');
        sessionStorage.setItem('licensePlate', user.plate_number || '');
        sessionStorage.setItem('isVIP', String(Boolean(user.is_vip)));
        sessionStorage.setItem('isAdmin', String(user.role === 'ADMIN'));
        sessionStorage.setItem('adminEmail', user.email || '');

        if (!allowedRoles.includes(user.role)) {
            const destination = {
                DRIVER: 'dashboard.html',
                ADMIN: 'admin.html',
                CASHIER: 'cashier.html',
                VENDOR: 'vendor.html'
            }[user.role] || 'login.html';
            window.location.replace(destination);
            return;
        }

        // Dynamically maintain Administrator authority across customer & operational views
        AppLayout.updateIdentity(user);
        adaptAdminPersona(user, page);
        await checkActiveReservation();
        return user;
    } catch (error) {
        console.error('Unable to verify the current session.', error);
    }
}

function adaptAdminPersona(user, page) {
    if (user.role !== 'ADMIN' || !['dashboard', 'live-map', 'wallet', 'reserve', 'violations'].includes(page)) return;
    const container = document.querySelector('.container');
    if (!container || document.getElementById('adminPersonaBanner')) return;
    const banner = document.createElement('div');
    banner.id = 'adminPersonaBanner';
    banner.className = 'admin-session-notice';
    const identity = document.createElement('span');
    identity.textContent = `Administrator session · ${user.email}`;
    const link = document.createElement('a');
    link.href = 'admin.html';
    link.textContent = 'Return to Administration';
    banner.append(identity, link);
    container.prepend(banner);
}

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

// --- Global State ---
let currentUser = {
    isVIP: sessionStorage.getItem('isVIP') === 'true',
    licensePlate: sessionStorage.getItem('licensePlate') || '',
    role: sessionStorage.getItem('role') || ''
};

// ---------------- AUTH (LOGIN) ----------------
if (authForm) {
    authForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;

        try {
            const response = await apiFetch(`${API_BASE}?action=login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });
            const data = await response.json();

            if (data.success) {
                sessionStorage.setItem('licensePlate', normalizeLicensePlateInput(data.license_plate));
                sessionStorage.setItem('isVIP', data.is_vip);
                sessionStorage.setItem('isAdmin', data.is_admin);
                sessionStorage.setItem('adminEmail', data.email || '');
                sessionStorage.setItem('role', data.role || 'DRIVER');
                csrfToken = data.csrf_token || '';
                sessionStorage.setItem('csrfToken', csrfToken);

                const destination = {
                    ADMIN: 'admin.html',
                    DRIVER: 'dashboard.html',
                    CASHIER: 'cashier.html',
                    VENDOR: 'vendor.html'
                }[data.role] || 'dashboard.html';
                window.location.href = destination;
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
        const plate = normalizeLicensePlateInput(document.getElementById('regPlate').value);

        try {
            const response = await apiFetch(`${API_BASE}?action=register`, {
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
async function signOut(event) {
    event.preventDefault();
    try {
        const response = await apiFetch(`${API_BASE}?action=logout`, { method: 'POST' });
        // An expired session is already signed out. Other failures must remain visible.
        if (response.status !== 401) {
            if (!response.ok) throw new Error('Logout was rejected.');
            const data = await response.json();
            if (!data.success) throw new Error('Logout was not confirmed.');
        }
        sessionStorage.clear();
        csrfToken = '';
        window.location.href = 'login.html';
    } catch (error) {
        alert('Unable to end your session. Refresh the page and try again.');
    }
}

document.querySelectorAll('[data-signout]').forEach(button => button.addEventListener('click', signOut));

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
        const response = await apiFetch(`${API_BASE}?action=map`);
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
    const todayStr = formatPhtDateTime(new Date().toISOString()).slice(0, 10);
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
    ['walletLoading', 'walletEmpty', 'walletError'].forEach(id => {
        const element = document.getElementById(id);
        if (element) element.hidden = true;
    });
    if (reservationFormContainer) reservationFormContainer.style.display = currentUser.role === 'ADMIN' ? 'block' : 'none';
}

function reservationPlate() {
    const target = document.getElementById('bookingPlate');
    return currentUser.role === 'ADMIN' && target
        ? normalizeLicensePlateInput(target.value)
        : normalizeLicensePlateInput(currentUser.licensePlate);
}

async function checkActiveReservation() {
    const walletOrForm = document.getElementById('qrWalletSection') || document.getElementById('reservationForm');
    const plate = reservationPlate();
    if (!walletOrForm) return;
    const loading = document.getElementById('walletLoading');
    const empty = document.getElementById('walletEmpty');
    const error = document.getElementById('walletError');
    if (!plate) {
        if (loading) loading.hidden = true;
        if (error) error.hidden = false;
        return;
    }
    if (loading) loading.hidden = false;
    if (empty) empty.hidden = true;
    if (error) error.hidden = true;

    try {
        const response = await apiFetch(`${API_BASE}?action=active_reservation&plate=${encodeURIComponent(plate)}`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Unable to load the reservation.');
        if (loading) loading.hidden = true;

        if (data.success && data.active) {
            renderReservationDetails(data, plate);
        } else if (data.success) {
            if (empty) empty.hidden = false;
            if (qrWalletSection) qrWalletSection.style.display = 'none';
            if (reservationFormContainer) reservationFormContainer.style.display = 'block';
        }
    } catch (error) {
        console.error('Failed to check active reservation:', error);
        if (loading) loading.hidden = true;
        const walletError = document.getElementById('walletError');
        if (walletError) {
            walletError.hidden = false;
            if (qrWalletSection) qrWalletSection.style.display = 'none';
        }
    }
}

document.getElementById('bookingPlate')?.addEventListener('change', checkActiveReservation);
document.getElementById('retryWallet')?.addEventListener('click', checkActiveReservation);

// ---------------- RESERVATION ----------------
if (reservationForm) {
    reservationForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!await pageSessionReady) return;
        const plate = reservationPlate();
        if (!plate) {
            alert("Session expired. Please log in again.");
            window.location.href = 'login.html';
            return;
        }

        const zone = document.getElementById('zoneSelect').value;

        // STRICT VIP VALIDATION CHECK - Intercepts non-VIP users instantly (Admins possess universal VIP clearance)
        if (zone === 'E' && !currentUser.isVIP && currentUser.role !== 'ADMIN') {
            alert("Error: Zone E reservations are restricted to VIP accounts only.");
            return; // Stops the form from proceeding to the backend
        }

        const days = calculateDays();
        const sDate = document.getElementById('startDate').value;
        const eDate = document.getElementById('endDate').value;
        const replaceExisting = currentUser.role === 'ADMIN' && Boolean(document.getElementById('replaceExistingReservation')?.checked);
        if (replaceExisting && !confirm(`Replace the active reservation for ${plate}? The existing booking is cancelled only if the new booking succeeds.`)) return;

        try {
            const response = await apiFetch(`${API_BASE}?action=reserve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    plate: plate,
                    zone: zone,
                    days: days,
                    startDate: sDate,
                    endDate: eDate,
                    replace_existing: replaceExisting
                })
            });
            const data = await response.json();

            if (data.success) {
                alert(`Reservation saved. Tariff: ₱${data.fee.toFixed(2)}.`);
                renderReservationDetails(data, plate);
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
        const plate = normalizeLicensePlateInput(document.getElementById("searchPlate").value);

        try {
            const response = await apiFetch(`${API_BASE}?action=violations&plate=${encodeURIComponent(plate)}`);
            const data = await response.json();
            if (!response.ok || !Array.isArray(data)) throw new Error(data.error || 'Unable to load violations.');

            if (data.length === 0) {
                violationResults.innerHTML = `
                    <div style="background: var(--success-bg); border: 1px solid var(--success-border); border-radius: var(--radius-md); padding: 14px 16px; color: var(--success-text); display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <strong style="font-size: 0.875rem;">Clear Record</strong>
                            <p style="margin: 2px 0 0 0; font-size: 0.78rem;">No active violations or outstanding penalties found for plate <code>${plate}</code>.</p>
                        </div>
                        <span class="badge badge-paid">IN COMPLIANCE</span>
                    </div>
                `;
                return;
            }

            let html = "";
            data.forEach(v => {
                const isPaid = v.status === 'PAID';
                const statusBadge = isPaid
                    ? '<span class="badge badge-paid">PAID / SETTLED</span>'
                    : '<span class="badge badge-unpaid">UNPAID / OUTSTANDING</span>';

                html += `
                    <div class="violation-card-item">
                        <div class="violation-card-info">
                            <h4>${v.violation_type}</h4>
                            <p>Location: Zone ${v.zone || '—'} &bull; Slot ${v.slot_number || 'Driveway/Perimeter'} &bull; Issued: ${formatPhtDateTime(v.issued_at)}</p>
                        </div>
                        <div class="violation-card-meta">
                            <div class="violation-fine-amount">₱${parseFloat(v.penalty_amount || 0).toFixed(2)}</div>
                            <div style="margin-top: 4px;">${statusBadge}</div>
                        </div>
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

// ---------------- ADMIN PORTAL & BUSINESS INTELLIGENCE ----------------
const adminPage = document.querySelector('body[data-page="admin"]');
let adminSlotsCache = [];
let adminCurrentZoneFilter = 'ALL';

// --- 1. OVERVIEW KPIS ---
async function loadAdminStats() {
    try {
        const response = await apiFetch(`${API_BASE}?action=admin_stats`);
        const data = await response.json();

        if (data.success) {
            if (document.getElementById('statTotalCapacity')) document.getElementById('statTotalCapacity').textContent = data.total;
            if (document.getElementById('statOccupied')) document.getElementById('statOccupied').textContent = data.occupied;
            if (document.getElementById('statAvailable')) document.getElementById('statAvailable').textContent = data.available;
            if (document.getElementById('statViolations')) document.getElementById('statViolations').textContent = data.violations;
            if (document.getElementById('statReservations')) document.getElementById('statReservations').textContent = data.active_reservations;
            if (document.getElementById('statGrossRevenue')) document.getElementById('statGrossRevenue').textContent = Number(data.gross_revenue).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (document.getElementById('statTodayRevenue')) document.getElementById('statTodayRevenue').textContent = '₱' + Number(data.today_revenue).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (document.getElementById('statOccupancyRate')) document.getElementById('statOccupancyRate').textContent = `${data.occupancy_rate}%`;
        }
    } catch (err) {
        console.error('Error fetching admin stats:', err);
    }
}

// --- 2. BUSINESS INTELLIGENCE & REVENUE ANALYTICS ---
async function loadAdminAnalytics() {
    try {
        const response = await apiFetch(`${API_BASE}?action=admin_analytics`);
        const data = await response.json();

        if (!data.success) {
            console.error('Failed to load business analytics:', data.error);
            return;
        }

        const rev = data.revenue;

        // Populate Timeline Revenue Cards
        if (document.getElementById('revToday')) document.getElementById('revToday').textContent = Number(rev.today).toLocaleString('en-US', { minimumFractionDigits: 2 });
        if (document.getElementById('revThisWeek')) document.getElementById('revThisWeek').textContent = Number(rev.this_week).toLocaleString('en-US', { minimumFractionDigits: 2 });
        if (document.getElementById('revThisMonth')) document.getElementById('revThisMonth').textContent = Number(rev.this_month).toLocaleString('en-US', { minimumFractionDigits: 2 });
        if (document.getElementById('revArpv')) document.getElementById('revArpv').textContent = Number(rev.arpv).toFixed(2);
        if (document.getElementById('revAvgDuration')) document.getElementById('revAvgDuration').textContent = Math.round(rev.avg_duration_minutes);

        // Populate Revenue Streams Grid
        const streamsGrid = document.getElementById('revenueStreamsGrid');
        if (streamsGrid && rev.streams) {
            const streams = rev.streams;
            const gross = rev.gross_total || 1;

            const streamItems = [
                {
                    name: 'Daytime Transient Parking',
                    badge: streams.transient.rate,
                    amount: streams.transient.total,
                    countText: `${streams.transient.count} Completed Sessions`,
                    color: '#3182ce'
                },
                {
                    name: 'Overnight Stays & Surcharges',
                    badge: streams.overnight.rate,
                    amount: streams.overnight.total,
                    countText: `${streams.overnight.count} Vehicles Past 23:00`,
                    color: '#6b46c1'
                },
                {
                    name: 'Pre-Booked Slot Reservations',
                    badge: streams.reservations.rate,
                    amount: streams.reservations.total,
                    countText: `${streams.reservations.count} Guaranteed Bookings`,
                    color: '#2b6cb0'
                },
                {
                    name: 'Enforcement Penalties Collected',
                    badge: 'Recovered Fines',
                    amount: streams.violations.total,
                    countText: `${streams.violations.count} Settled Infractions`,
                    color: '#38a169'
                },
                {
                    name: 'Tenant Vendor Subsidies',
                    badge: streams.vendor_subsidies.rate,
                    amount: streams.vendor_subsidies.waived_total,
                    countText: `${streams.vendor_subsidies.count} Validations (Joey's Restaurant, etc.)`,
                    color: '#d69e2e',
                    subtext: '(Waived revenue credited by commercial tenants)'
                },
                {
                    name: 'Grace Period Bypasses',
                    badge: streams.bypassed_grace.rate,
                    amount: 0,
                    countText: `${streams.bypassed_grace.count} Drop-offs / Departures < 15m`,
                    color: '#718096',
                    subtext: '(Free turnaround to prevent roadside staging)'
                }
            ];

            streamsGrid.innerHTML = streamItems.map(item => {
                const pct = item.amount > 0 ? Math.min(100, Math.round((item.amount / gross) * 100)) : 0;
                return `
                    <div class="stream-card">
                        <div class="stream-header">
                            <span class="stream-name">${item.name}</span>
                            <span class="stream-rate-badge">${item.badge}</span>
                        </div>
                        <div class="stream-amount">₱${Number(item.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                        <div class="stream-count">${item.countText}</div>
                        ${item.subtext ? `<div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;">${item.subtext}</div>` : ''}
                        <div style="height: 5px; background: var(--slate-200); border-radius: 3px; margin-top: 8px; overflow: hidden;">
                            <div style="width: ${pct}%; height: 100%; background: ${item.color}; border-radius: 3px;"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Populate Sectional Capacity Heatmap
        const heatmapGrid = document.getElementById('capacityHeatmapGrid');
        if (heatmapGrid && data.capacity_heatmap) {
            const zones = data.capacity_heatmap.zones;
            heatmapGrid.innerHTML = zones.map(z => {
                const heatClass = `heat-level-${z.heat_level}`;
                let heatLabel = 'Normal Flow';
                if (z.heat_level === 'moderate') heatLabel = 'Moderate';
                else if (z.heat_level === 'high') heatLabel = 'High Occupancy';
                else if (z.heat_level === 'critical') heatLabel = 'Near Capacity / Overflow';

                return `
                    <div class="heatmap-card">
                        <div class="heatmap-header">
                            <span class="heatmap-title">${z.name}</span>
                            <span class="heat-level-badge ${heatClass}">${heatLabel} (${z.utilization_rate}%)</span>
                        </div>
                        <div class="heatmap-role">${z.role} &bull; <small style="color: var(--text-muted);">${z.target}</small></div>
                        <div class="heat-progress-wrap">
                            <div class="heat-progress-bar ${heatClass}" style="width: ${Math.min(100, z.utilization_rate)}%;"></div>
                        </div>
                        <div class="heatmap-stats-row">
                            <span>Occupied: <strong>${z.occupied_count} / ${z.total_capacity}</strong></span>
                            <span>Reserved: <strong>${z.reserved_count}</strong></span>
                            <span>Available: <strong style="color: var(--success);">${z.available_count}</strong></span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Populate Peak Hours 24-Hour Bar Chart
        const hourlyChart = document.getElementById('hourlyChartContainer');
        if (hourlyChart && data.hourly_distribution) {
            hourlyChart.innerHTML = data.hourly_distribution.map(h => {
                const barHeight = Math.max(6, h.pct);
                const isPeak = h.is_peak;
                return `
                    <div class="chart-bar-col" title="${h.label} — ${h.count} vehicle entries">
                        ${h.count > 0 ? `<div class="chart-tooltip">${h.count}</div>` : ''}
                        <div class="chart-bar ${isPeak ? 'peak' : ''}" style="height: ${barHeight}%;"></div>
                        <div class="chart-hour-label">${h.label}</div>
                    </div>
                `;
            }).join('');
        }

        // Populate Violations Summary
        if (data.violations_summary) {
            const vs = data.violations_summary;
            if (document.getElementById('violationTotalImposed')) document.getElementById('violationTotalImposed').textContent = Number(vs.total_imposed).toLocaleString('en-US', { minimumFractionDigits: 2 });
            if (document.getElementById('violationTotalCollected')) document.getElementById('violationTotalCollected').textContent = Number(vs.total_collected).toLocaleString('en-US', { minimumFractionDigits: 2 });
            if (document.getElementById('violationOutstanding')) document.getElementById('violationOutstanding').textContent = Number(vs.outstanding_balance).toLocaleString('en-US', { minimumFractionDigits: 2 });
            if (document.getElementById('violationAvgFine')) document.getElementById('violationAvgFine').textContent = Number(vs.avg_fine).toLocaleString('en-US', { minimumFractionDigits: 2 });
        }

    } catch (err) {
        console.error('Error loading admin analytics:', err);
    }
}

// --- 3. VIOLATIONS MANAGEMENT TABLE ---
async function loadAdminViolations() {
    const table = document.getElementById('adminViolationsTable');
    if (!table) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_violations_list`);
        const data = await response.json();

        if (!data.success || !data.violations) {
            table.innerHTML = `<tr><td colspan="8">${data.error || 'Failed to load violations.'}</td></tr>`;
            return;
        }

        const violations = data.violations;
        if (violations.length === 0) {
            table.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 15px;">No violations on record.</td></tr>`;
            return;
        }

        table.innerHTML = violations.map(v => {
            const isPaid = v.status === 'PAID';
            const statusBadge = isPaid
                ? `<span class="badge badge-paid">PAID / SETTLED</span>`
                : `<span class="badge badge-unpaid">UNPAID / PENDING</span>`;

            const actionCell = isPaid
                ? `<span style="font-size: 0.75rem; color: var(--text-muted);">Settled</span>`
                : `<button class="btn btn-sm btn-success" onclick="resolveAdminViolation(${v.violation_id}, 'PAID')">Mark as Paid</button>
                   <button class="btn btn-sm btn-outline" style="margin-left: 4px;" onclick="resolveAdminViolation(${v.violation_id}, 'DISMISSED')">Dismiss</button>`;

            return `
                <tr>
                    <td><strong>#V-${v.violation_id}</strong></td>
                    <td><code>${v.plate_number || 'N/A'}</code></td>
                    <td>Zone ${v.zone || '—'} &bull; ${v.slot_number || 'Driveway/Lane'}</td>
                    <td>${v.violation_type}</td>
                    <td><strong>₱${Number(v.penalty_amount).toFixed(2)}</strong></td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 0.78rem; color: var(--text-muted);">${formatPhtDateTime(v.issued_at)}</td>
                    <td>${actionCell}</td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading admin violations:', err);
        table.innerHTML = `<tr><td colspan="8">Failed to load violations.</td></tr>`;
    }
}

async function resolveAdminViolation(violationId, status) {
    const actionLabel = status === 'PAID' ? 'confirm cash receipt and mark this penalty as PAID' : 'DISMISS this infraction';
    if (!confirm(`Are you sure you want to ${actionLabel}? This action will be logged in the immutable audit trail.`)) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_resolve_violation`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                violation_id: violationId,
                status: status,
            })
        });
        const data = await response.json();

        if (data.success) {
            loadAdminViolations();
            loadAdminStats();
            loadAdminAnalytics();
            loadAdminAuditLogs();
        } else {
            alert(data.error || 'Failed to update violation.');
        }
    } catch (err) {
        alert('Server communication error.');
    }
}

// --- 4. IMMUTABLE AUDIT TRAIL LOGS ---
async function loadAdminAuditLogs() {
    const table = document.getElementById('adminAuditLogsTable');
    if (!table) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_audit_logs`);
        const data = await response.json();

        if (!data.success || !data.logs) {
            table.innerHTML = `<tr><td colspan="5">${data.error || 'Failed to load audit logs.'}</td></tr>`;
            return;
        }

        const logs = data.logs;
        if (logs.length === 0) {
            table.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 15px;">No audit trail records logged.</td></tr>`;
            return;
        }

        table.innerHTML = logs.map(l => {
            const actionClass = `audit-action-${l.action_type}`;
            return `
                <tr>
                    <td style="white-space: nowrap; font-size: 0.78rem; color: var(--text-muted);">${formatPhtDateTime(l.created_at)}</td>
                    <td><strong>${l.admin_email}</strong></td>
                    <td><span class="audit-action-tag ${actionClass}">${l.action_type}</span></td>
                    <td><code>${l.target_entity} #${l.target_id || ''}</code></td>
                    <td>${l.details}</td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading audit logs:', err);
        table.innerHTML = `<tr><td colspan="5">Failed to load audit logs.</td></tr>`;
    }
}

// --- 5. ONGOING RESERVATIONS ---
async function loadAdminReservations() {
    const table = document.getElementById('adminReservationsTable');
    if (!table) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_reservations`);
        const data = await response.json();

        if (!data.success) {
            table.innerHTML = `<tr><td colspan="7">${data.error || 'Failed to load reservations.'}</td></tr>`;
            return;
        }

        const reservations = data.reservations;

        if (!reservations || reservations.length === 0) {
            table.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 15px;">No active reservations on record.</td></tr>`;
            return;
        }

        table.innerHTML = reservations.map(r => `
            <tr>
                <td><code>${r.plate_number}</code></td>
                <td>${r.email || '—'} ${r.is_vip ? '<span class="badge badge-vip">VIP</span>' : ''}</td>
                <td>Zone ${r.zone} &bull; Slot ${r.slot_number}</td>
                <td>${r.start_date || 'N/A'}</td>
                <td>${r.end_date || 'N/A'}</td>
                <td><strong>₱${Number(r.fee).toFixed(2)}</strong></td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="overrideReservation(${r.reservation_id})">Override / Cancel</button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error('Error loading admin reservations:', err);
        table.innerHTML = `<tr><td colspan="7">Failed to load reservations.</td></tr>`;
    }
}

async function overrideReservation(reservationId) {
    if (!confirm('Override this reservation? This will cancel it, free the assigned slot, and log the action in the audit trail.')) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=override_reservation`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                reservation_id: reservationId,
                status: 'CANCELLED',
                release_slot: true,
            })
        });
        const data = await response.json();

        if (data.success) {
            loadAdminReservations();
            loadAdminStats();
            loadAdminAnalytics();
            loadAdminMap();
            loadAdminAuditLogs();
        } else {
            alert(data.error || 'Failed to override reservation.');
        }
    } catch (err) {
        alert('Server connection failed.');
    }
}

// --- 6. USER ACCOUNTS & VIP MANAGEMENT ---
async function loadAdminAccounts(search = '') {
    const table = document.getElementById('adminAccountsTable');
    if (!table) return;

    try {
        const url = `${API_BASE}?action=admin_accounts` + (search ? `&search=${encodeURIComponent(search)}` : '');
        const response = await apiFetch(url);
        const data = await response.json();

        if (!data.success) {
            table.innerHTML = `<tr><td colspan="5">${data.error || 'Failed to load accounts.'}</td></tr>`;
            return;
        }

        const accounts = data.accounts;

        if (!accounts || accounts.length === 0) {
            table.innerHTML = `<tr><td colspan="5">No accounts found matching query.</td></tr>`;
            return;
        }

        table.innerHTML = accounts.map(a => {
            const isVip = Number(a.is_vip) === 1;
            const role = String(a.role || (Number(a.is_admin) === 1 ? 'ADMIN' : 'DRIVER')).toUpperCase();
            return `
                <tr>
                    <td><strong>${a.email}</strong></td>
                    <td><code>${a.plate_number}</code></td>
                    <td>${isVip ? '<span class="badge badge-vip">VIP / PWD PRIORITY</span>' : '<span class="badge badge-bypassed">STANDARD</span>'}</td>
                    <td>
                        <select id="accountRole-${a.account_id}" aria-label="Role for ${a.email}">
                            ${['ADMIN', 'DRIVER', 'CASHIER', 'VENDOR'].map(option => `<option value="${option}" ${role === option ? 'selected' : ''}>${option}</option>`).join('')}
                        </select>
                        <button class="btn btn-sm btn-outline" onclick="setAccountRole(${a.account_id})">Save Role</button>
                    </td>
                    <td>
                        <button class="btn btn-sm ${isVip ? 'btn-outline' : 'btn-primary'}" onclick="setVip(${a.account_id}, ${isVip ? 0 : 1})">
                            ${isVip ? 'Revoke VIP Status' : 'Grant VIP / PWD'}
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

async function setAccountRole(accountId) {
    const roleSelect = document.getElementById(`accountRole-${accountId}`);
    if (!roleSelect) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=set_account_role`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ account_id: accountId, role: roleSelect.value })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            alert(data.error || 'Failed to update account role.');
            return;
        }
        const searchInput = document.getElementById('accountSearchInput');
        loadAdminAccounts(searchInput ? searchInput.value.trim() : '');
        loadAdminAuditLogs();
    } catch (error) {
        alert('Server connection failed.');
    }
}

async function setVip(accountId, newVipState) {
    try {
        const response = await apiFetch(`${API_BASE}?action=set_vip`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                account_id: accountId,
                is_vip: newVipState
            })
        });
        const data = await response.json();

        if (data.success) {
            const searchInput = document.getElementById('accountSearchInput');
            loadAdminAccounts(searchInput ? searchInput.value.trim() : '');
            loadAdminAuditLogs();
        } else {
            alert(data.error || 'Failed to update VIP status.');
        }
    } catch (err) {
        alert('Server connection failed.');
    }
}

// --- 7. LIVE ALPR DETECTION FEED ---
async function loadAlprLogs() {
    const alprTable = document.getElementById('alprLogsTable');
    if (!alprTable) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=alpr_logs`);
        const logs = await response.json();

        if (!logs || logs.length === 0) {
            alprTable.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 15px;">No recent ALPR detections logged.</td></tr>`;
            return;
        }

        alprTable.innerHTML = logs.map(log => {
            const isPaid = log.status === 'PAID_RESERVATION' || log.status === 'PAID' || log.status === 'PAID_OVERNIGHT' || log.status === 'PAID_VENDOR';
            let badgeClass = 'badge-unpaid';
            if (log.status === 'PAID') badgeClass = 'badge-paid';
            else if (log.status === 'PAID_OVERNIGHT') badgeClass = 'badge-overnight';
            else if (log.status === 'PAID_VENDOR') badgeClass = 'badge-vendor';
            else if (log.status === 'BYPASSED') badgeClass = 'badge-bypassed';
            else if (log.status === 'PAID_RESERVATION') badgeClass = 'badge-paid';

            const locStatus = log.location_status === 'EXITED'
                ? '<span style="color: var(--text-muted);">Departed (Exit Gate)</span>'
                : '<span style="color: var(--primary); font-weight: 600;">Parked (Entrance Gate)</span>';

            return `
                <tr>
                    <td style="white-space: nowrap; font-size: 0.78rem; color: var(--text-muted);">${formatPhtDateTime(log.timestamp)}</td>
                    <td><code>${log.plate_number}</code></td>
                    <td>${log.zone ? 'Zone ' + log.zone + ' &bull; ' : ''}Slot ${log.slot_number || 'Unassigned'}</td>
                    <td><span style="font-weight: 600; color: var(--primary);">98.5%</span></td>
                    <td>${locStatus}</td>
                    <td><span class="badge ${badgeClass}">${log.status || 'UNPAID'}</span></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading ALPR logs:', err);
    }
}

// --- 8. INTERACTIVE SLOT MANAGEMENT & OVERRIDES ---
async function loadAdminMap() {
    const adminMap = document.getElementById('adminMapControls');
    if (!adminMap) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=map`);
        const slots = await response.json();

        if (!slots || slots.length === 0) {
            adminMap.innerHTML = "<p>No slots configured in database.</p>";
            return;
        }

        adminSlotsCache = slots;
        renderFilteredSlots();

    } catch (err) {
        console.error('Error loading admin map controls:', err);
    }
}

function renderFilteredSlots() {
    const adminMap = document.getElementById('adminMapControls');
    if (!adminMap || !adminSlotsCache) return;

    let filteredSlots = adminSlotsCache;
    if (adminCurrentZoneFilter !== 'ALL') {
        filteredSlots = adminSlotsCache.filter(s => s.zone === adminCurrentZoneFilter);
    }

    const countLabel = document.getElementById('filteredSlotsCount');
    if (countLabel) {
        countLabel.textContent = adminCurrentZoneFilter === 'ALL'
            ? `Showing all ${filteredSlots.length} spaces`
            : `Showing ${filteredSlots.length} spaces in Zone ${adminCurrentZoneFilter}`;
    }

    // Group filtered slots by zone
    const zones = {};
    filteredSlots.forEach(slot => {
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
                    <button type="button" class="btn-action slot-override-button" aria-label="Mark ${slot.slot_number} ${isOccupied ? 'available' : 'occupied'}" onclick="toggleSlotOverride(${slot.slot_id}, ${isOccupied ? 0 : 1})">
                        Mark ${isOccupied ? 'available' : 'occupied'}
                    </button>
                </div>
            `;
        }).join('');

        return `
            <div class="zone-section">
                <div class="zone-header">
                    <h3>Zone ${zone}</h3>
                    <span class="zone-badge"><strong>${totalCount - occupiedCount}</strong> available <span aria-hidden="true">·</span> ${occupiedCount} occupied</span>
                </div>
                <div class="admin-grid">
                    ${cards}
                </div>
            </div>
        `;
    }).join('');
}

async function toggleSlotOverride(slotId, newOccupiedState) {
    const stateName = newOccupiedState ? 'occupied' : 'available';
    if (!confirm(`Mark this space as ${stateName}? This manual override will be recorded in the audit trail.`)) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=toggle_slot`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                slot_id: slotId,
                is_occupied: newOccupiedState,
            })
        });
        const data = await response.json();

        if (data.success) {
            loadAdminStats();
            loadAdminAnalytics();
            loadAdminMap();
            loadAdminAuditLogs();
        } else {
            alert(data.error || 'Failed to update slot.');
        }
    } catch (err) {
        alert('Server connection failed.');
    }
}

// Zone Filter Buttons Listener
const filterButtonsContainer = document.getElementById('zoneFilterButtons');
if (filterButtonsContainer) {
    filterButtonsContainer.addEventListener('click', (e) => {
        if (e.target.classList.contains('zone-filter-btn')) {
            filterButtonsContainer.querySelectorAll('.zone-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            });
            e.target.classList.add('active');
            e.target.setAttribute('aria-pressed', 'true');
            adminCurrentZoneFilter = e.target.dataset.filter || 'ALL';
            renderFilteredSlots();
        }
    });
}

// Account Search Debounce
const accountSearchInput = document.getElementById('accountSearchInput');
if (accountSearchInput) {
    let searchDebounce;
    accountSearchInput.addEventListener('input', (e) => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => loadAdminAccounts(e.target.value.trim()), 300);
    });
}

const pageSessionReady = enforcePageRole();

// --- ADMIN SIDEBAR ACCORDION / DROPDOWN CONTROLS ---
function initAdminSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar) return;

    // 1. Group toggle clicks (collapsible dropdowns)
    const groupToggles = sidebar.querySelectorAll('.group-header-btn');
    groupToggles.forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            e.preventDefault();
            const group = toggle.closest('.sidebar-nav-group');
            if (!group) return;
            const isOpen = group.classList.toggle('open');
            toggle.setAttribute('aria-expanded', String(isOpen));
        });
    });

    // 2. User profile card dropdown popover toggle
    const userToggle = document.getElementById('sidebarUserDropdownToggle');
    const userMenu = document.getElementById('sidebarUserMenu');
    if (userToggle && userMenu) {
        userToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isVisible = userMenu.style.display === 'flex';
            userMenu.style.display = isVisible ? 'none' : 'flex';
            userToggle.setAttribute('aria-expanded', String(!isVisible));
        });

        document.addEventListener('click', (e) => {
            if (!userToggle.contains(e.target) && !userMenu.contains(e.target)) {
                userMenu.style.display = 'none';
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // 3. Smooth scrolling for internal sidebar section links
    const allSectionLinks = document.querySelectorAll(
        '.sidebar-nav-item[href^="#"], .dropdown-item[href^="#"]'
    );
    allSectionLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const targetId = link.getAttribute('href');
            const targetEl = document.querySelector(targetId);
            if (targetEl) {
                e.preventDefault();
                const offset = (document.querySelector('.app-header')?.getBoundingClientRect().height || 64) + 24;
                const elementPosition = targetEl.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - offset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });

                // Update the active sidebar section
                allSectionLinks.forEach(l => {
                    if (l.getAttribute('href') === targetId) {
                        l.classList.add('active');
                    } else {
                        l.classList.remove('active');
                    }
                });
            }
        });
    });
}

// Keep the active sidebar section in sync with scrolling
window.addEventListener('scroll', () => {
    const sections = document.querySelectorAll('section[id]');
    const sidebarNavItems = document.querySelectorAll('.sidebar-nav-item[href^="#"]');
    const dropdownItems = document.querySelectorAll('.dropdown-item[href^="#"]');

    if (!sections.length) return;

    let currentId = '';
    const scrollPos = window.pageYOffset + (document.querySelector('.app-header')?.getBoundingClientRect().height || 64) + 28;

    sections.forEach(section => {
        const top = section.offsetTop;
        const height = section.offsetHeight;
        if (scrollPos >= top && scrollPos < top + height) {
            currentId = section.getAttribute('id');
        }
    });

    if (window.pageYOffset < 150 && sections.length > 0) {
        currentId = sections[0].getAttribute('id');
    }

    if (currentId) {
        const matchHref = `#${currentId}`;

        sidebarNavItems.forEach(link => {
            link.classList.toggle('active', link.getAttribute('href') === matchHref);
        });

        dropdownItems.forEach(link => {
            const isMatch = link.getAttribute('href') === matchHref;
            link.classList.toggle('active', isMatch);
            if (isMatch) {
                const parentGroup = link.closest('.sidebar-nav-group');
                if (parentGroup && !parentGroup.classList.contains('open')) {
                    parentGroup.classList.add('open');
                    parentGroup.querySelector('.group-header-btn')?.setAttribute('aria-expanded', 'true');
                }
            }
        });

    }
});

// --- ADMIN BOOTSTRAP INITIALIZATION ---
pageSessionReady.then(user => {
    if (!adminPage || !user) return;
    initAdminSidebar();
    loadAdminStats();
    loadAdminAnalytics();
    loadAdminViolations();
    loadAlprLogs();
    loadAdminMap();
    loadAdminReservations();
    loadAdminAccounts();
    loadAdminAuditLogs();

    // Auto-refresh stats & ALPR feed every 30 seconds for live gate monitoring
    setInterval(() => {
        loadAdminStats();
        loadAlprLogs();
    }, 30000);
});
