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
    if (/^QR_/i.test(input)) return input.replace(/\s+/g, '');
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
        sessionStorage.clear();
        window.location.replace('login.html');
        return null;
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

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
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
    'E': { top: 41, left: 11 },
    'D': { top: 76, left: 40 },
    'B': { top: 76, left: 60 },
    'A': { top: 37, left: 88 },
    'C': { top: 32, left: 50.5 },
    'F': { top: 14, left: 10 }
};

// Bay centers measured against parking-lot-mapv2.png (686 × 992).
// Each dot sits within a painted bay; the zone letter sits outside the row.
const zoneBayCenters = {
    'A': [[88, 41.6], [88, 46.3], [88, 50.9], [88, 55.5], [88, 60.2], [88, 64.8], [88, 69.5], [88, 74.1], [88, 78.7]],
    'B': [[60, 42.6], [60, 47.1], [60, 51.7], [60, 56.2], [60, 60.7], [60, 65.3], [60, 69.8]],
    'C': [[33, 22], [40, 23], [47, 24], [54, 25], [61, 26], [68, 27]],
    'D': [[40, 42.6], [40, 47.1], [40, 51.7], [40, 56.2], [40, 60.7], [40, 65.3], [40, 69.8]],
    'E': [[11, 44.5], [11, 49.1], [11, 53.6], [11, 58.3], [11, 62.9], [11, 67.5], [11, 72.2], [11, 76.8], [11, 81.6], [11, 85.7]]
};

function getSlotDotPositions(zone, count) {
    const centers = zoneBayCenters[zone] || [];
    if (centers.length !== count) return [];
    return centers.map(([left, top]) => ({ left, top }));
}

let zonesCache = {};
let selectedMapZone = null;

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
        const zoneList = document.getElementById('mapZoneList');
        let markersHTML = `<img src="images/parking-lot-mapv2.png" alt="Aerial view of the parking area, with bay markers showing availability" class="lot-photo">`;
        let zoneCards = '';

        Object.keys(zones).sort().forEach(zone => {
            const slots = zones[zone];
            const total = slots.length;
            const free = slots.filter(s => s.is_occupied != 1).length;
            const pos = zonePositions[zone];
            const isOverflow = zone === 'F';

            zoneCards += `
                <button type="button" class="map-zone-card" data-zone="${zone}"
                    aria-label="Zone ${zone}, ${free} of ${total} spaces free">
                    <span class="map-zone-letter">${zone}</span>
                    <span class="map-zone-copy"><strong>Zone ${zone}</strong><small>${isOverflow ? 'Rough-road overflow' : `${free} of ${total} free`}</small></span>
                    <span class="map-zone-availability ${free > 0 ? 'available' : 'full'}">${free}/${total}</span>
                </button>`;

            if (pos) {
                markersHTML += `<button type="button" class="zone-marker"
                    style="top:${pos.top}%; left:${pos.left}%;" data-zone="${zone}"
                    title="Zone ${zone}" aria-label="Show Zone ${zone}">Zone ${zone}</button>`;

                if (!isOverflow) {
                    const dotPositions = getSlotDotPositions(zone, slots.length);
                    slots.forEach((slot, i) => {
                        const dp = dotPositions[i];
                        if (!dp) return;
                        const status = slotStatus(slot);
                        const label = `Zone ${zone} - ${slot.slot_number}: ${status.label}`;

                        markersHTML += `
                            <button type="button" class="slot-dot ${status.key === 'vacant' ? 'free' : status.key}"
                                style="top:${dp.top}%; left:${dp.left}%;"
                                data-zone="${zone}"
                                title="${label}"
                                aria-label="${label}">
                            </button>
                        `;
                    });
                }
            }
        });

        liveMap.innerHTML = `<div class="lot-photo-wrap">${markersHTML}</div>`;
        if (zoneList) zoneList.innerHTML = zoneCards;

        document.querySelectorAll('#liveMap .zone-marker, #liveMap .slot-dot, #mapZoneList .map-zone-card').forEach(btn => {
            btn.addEventListener('click', () => showZoneDetail(btn.dataset.zone));
        });
        showZoneDetail(zones[selectedMapZone] ? selectedMapZone : (zones.A ? 'A' : Object.keys(zones)[0]));

    } catch (error) {
        console.error(error);
        liveMap.innerHTML = "<p>Error loading live map.</p>";
    }
}

function showZoneDetail(zone) {
    const panel = document.getElementById('zoneDetailPanel');
    if (!panel || !zonesCache[zone]) return;

    selectedMapZone = zone;
    const slots = zonesCache[zone];
    const free = slots.filter(slot => slot.is_occupied != 1).length;

    panel.innerHTML = `
        <div class="zone-detail-heading">
            <div><span class="map-detail-eyebrow">Selected area</span><h3>Zone ${zone}</h3></div>
            <strong>${free}/${slots.length} free</strong>
        </div>
        <div class="zone-detail-slots">
            ${slots.map(slot => {
                const status = slotStatus(slot);
                const plate = slot.plate_number ? normalizeLicensePlateInput(slot.plate_number) : '';
                return `
                <div class="slot-row">
                    <span>${slot.slot_number}</span>
                    <span class="${status.key === 'vacant' ? 'free' : status.key}"></span>
                    <span>${status.label}${plate ? ' &ndash; ' + safeSectionalText(plate) : ''}
                    <small> · ${zone === 'F' ? `Zone allocation: ${Number(slot.zone_allocated_count || 0)} vehicles` : `Camera: ${titleCaseStatus(slot.observed_state || 'NOT_OBSERVED')}`}</small></span>
                </div>
            `;
            }).join('')}
        </div>
    `;
    document.querySelectorAll('#mapZoneList .map-zone-card, #liveMap .zone-marker').forEach(marker => {
        const selected = marker.dataset.zone === zone;
        marker.classList.toggle('selected', selected);
        if (marker.classList.contains('map-zone-card') || marker.classList.contains('zone-marker')) {
            marker.setAttribute('aria-pressed', String(selected));
        }
    });
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
    if (!qrWalletSection || !data.qr_token || !['PAID', 'ADMIN_EXEMPT'].includes(data.payment_state)) return;
    const pending = document.getElementById('paymentPendingSection');
    if (pending) pending.style.display = 'none';

    const qrPayload = data.qr_token;

    if (qrCodeImage) {
        qrCodeImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(qrPayload)}`;
    }

    const passTitle = document.querySelector('.pass-ticket-header strong');
    if (passTitle) passTitle.textContent = data.payment_provider === 'LOCAL' ? 'DEMO RESERVATION PASS' : 'ACTIVE RESERVATION PASS';
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

function renderPaymentPending(data) {
    if (qrWalletSection) qrWalletSection.style.display = 'none';
    if (reservationFormContainer) reservationFormContainer.style.display = 'none';
    const pending = document.getElementById('paymentPendingSection');
    if (!pending) return;
    pending.style.display = 'block';
    const deadline = new Date(`${String(data.hold_deadline).replace(' ', 'T')}Z`);
    const expired = !Number.isNaN(deadline.getTime()) && deadline <= new Date();
    const legacyUnresolved = expired && data.payment_provider && data.payment_provider !== 'LOCAL';
    document.getElementById('paymentPendingTitle').textContent = data.payment_state === 'UNVERIFIED'
        ? 'Payment verification required'
        : legacyUnresolved ? 'Expired hold · provider review' : 'Awaiting payment';
    document.getElementById('paymentPendingTag').textContent = data.payment_state === 'UNVERIFIED' || legacyUnresolved ? 'Staff review' : '10-minute hold';
    document.getElementById('paymentPendingDescription').hidden = data.payment_state === 'UNVERIFIED';
    if (data.payment_state !== 'UNVERIFIED') {
        document.getElementById('paymentPendingDescription').innerHTML = data.payment_provider === 'LOCAL'
            ? 'Your slot is held until <strong id="paymentHoldDeadline">—</strong>. Your pass appears only after the local demo payment succeeds. Returning from checkout does not confirm it.'
            : 'Your slot is held until <strong id="paymentHoldDeadline">—</strong>. Your pass appears only after the payment provider confirms payment. Returning from checkout does not confirm it.';
    }
    document.getElementById('paymentHoldDeadline').textContent = data.payment_state === 'UNVERIFIED' ? 'staff review' : formatPhtDateTime(data.hold_deadline);
    document.getElementById('pendingSlotDetails').textContent = data.payment_state === 'UNVERIFIED'
        ? 'This older booking has no verified payment. No pass is available; contact staff.'
        : legacyUnresolved
        ? `This expired ${data.payment_provider} hold is still unresolved${data.payment_record_status ? ` (record: ${data.payment_record_status})` : ''}${data.checkout_session_exists ? '' : ' and has no stored checkout session'}. No pass was issued. The local simulator cannot verify or release this old provider hold. Use another plate for a local demo booking; staff should reconcile this record before reusing ${data.plate || 'the vehicle'}.`
        : expired
        ? 'The hold deadline has passed. Payment or checkout expiry is still being reconciled; no pass has been issued.'
        : `Zone ${data.zone}, slot ${data.slot} · ₱${Number(data.fee).toFixed(2)} · ${data.start_date} to ${data.end_date}`;
    if (reservationFormContainer) reservationFormContainer.style.display = currentUser.role === 'ADMIN' && legacyUnresolved ? 'block' : 'none';
    const link = document.getElementById('resumeCheckoutLink');
    if (data.checkout_url && /^\/frontend\/demo-checkout\.html\?session=local_[a-f0-9]{48}$/.test(data.checkout_url)) {
        link.href = data.checkout_url;
        link.hidden = false;
    } else link.hidden = true;
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
            if (data.status === 'PENDING_PAYMENT' || data.payment_state === 'UNVERIFIED') renderPaymentPending(data);
            else renderReservationDetails(data, plate);
        } else if (data.success) {
            const pending = document.getElementById('paymentPendingSection');
            if (pending) pending.style.display = 'none';
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
document.getElementById('refreshPaymentStatus')?.addEventListener('click', checkActiveReservation);

// ---------------- RESERVATION ----------------
const bookingSlotInput = document.getElementById('bookingSlot');
if (bookingSlotInput) {
    bookingSlotInput.addEventListener('input', () => {
        bookingSlotInput.value = bookingSlotInput.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    });
}
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

        // Zone A is reserved for VIP / PWD priority vehicles; admins may override.
        if (zone === 'A' && !currentUser.isVIP && currentUser.role !== 'ADMIN') {
            alert("Zone A reservations are restricted to VIP / PWD priority accounts.");
            return; // Stops the form from proceeding to the backend
        }

        const days = calculateDays();
        const sDate = document.getElementById('startDate').value;
        const eDate = document.getElementById('endDate').value;
        const adminPaymentExemption = currentUser.role === 'ADMIN' && Boolean(document.getElementById('adminPaymentExemption')?.checked);
        const requestedSlot = currentUser.role === 'ADMIN'
            ? (document.getElementById('bookingSlot')?.value || '').trim().toUpperCase() : '';
        if (requestedSlot && !new RegExp(`^${zone}-[0-9]{2}$`).test(requestedSlot)) {
            alert(`Choose a bay in Zone ${zone}, such as ${zone}-05.`);
            return;
        }

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
                    slot_number: requestedSlot,
                    admin_payment_exemption: adminPaymentExemption
                })
            });
            const data = await response.json();

            if (data.success) {
                if (data.status === 'PENDING_PAYMENT') {
                    renderPaymentPending(data);
                    if (data.checkout_url) window.location.assign(data.checkout_url);
                } else {
                    data.payment_state = 'ADMIN_EXEMPT';
                    renderReservationDetails(data, plate);
                }
            } else {
                alert(data.error || 'Reservation failed.');
            }
        } catch (error) {
            alert('Failed to process reservation.');
        }
    });
}

// ---------------- VIOLATIONS ----------------
// What each infraction means and what record supports it, shown to drivers and staff.
function violationRule(type) {
    const t = String(type || '').toLowerCase();
    if (t.includes('zone a') || t.includes('vip')) return {
        rule: 'Zone A bays are reserved for vehicles with VIP or PWD priority status.',
        basis: 'The sectional camera saw this vehicle parked in a Zone A bay, and the account for this plate has no VIP / PWD status.'
    };
    if (t.includes('wrong slot')) return {
        rule: 'Each vehicle must park in the bay assigned to it at the entrance gate.',
        basis: 'The sectional camera saw this vehicle in a different bay from the one on its parking ticket.'
    };
    if (t.includes('double')) return {
        rule: 'Vehicles may not double-park or block other parked vehicles.',
        basis: 'The camera tracked the vehicle standing in a no-parking area for at least 20 continuous seconds.'
    };
    if (t.includes('driveway') || t.includes('no parking')) return {
        rule: 'Driveways and lanes must stay clear. Stopping there is limited to under 20 seconds.',
        basis: 'The camera tracked the vehicle standing in the driveway for at least 20 continuous seconds.'
    };
    if (t.includes('bypass')) return {
        rule: 'Every vehicle must settle its parking fee before leaving through the exit gate.',
        basis: 'The exit gate camera read this plate leaving while the session was still unpaid.'
    };
    if (t.includes('reserved')) return {
        rule: 'A reserved bay may only be used by the vehicle that booked it.',
        basis: 'The plate read in the reserved bay did not match the plate on the reservation.'
    };
    if (t.includes('plate')) return {
        rule: 'The vehicle in a bay must match the plate registered at the entrance gate.',
        basis: 'The camera read a different plate in the bay than the one assigned to it.'
    };
    if (t.includes('color')) return {
        rule: 'The vehicle in a bay must match the vehicle registered at the entrance gate.',
        basis: 'The vehicle colour seen in the bay did not match the colour recorded at entry.'
    };
    if (t.includes('lost') && t.includes('ticket')) return {
        rule: 'A lost parking ticket carries a replacement fee.',
        basis: 'The driver reported the ticket lost at the cashier.'
    };
    return { rule: 'Parking regulation infraction recorded by staff.', basis: 'Recorded against the parking session for this plate.' };
}

// Where the infraction happened: the bay the camera saw versus the bay that was assigned.
function violationLocation(v) {
    const assigned = v.slot_number ? `${v.slot_number} (Zone ${v.zone || '?'})` : '';
    const seen = v.observed_region || '';
    const seenLabel = /^[A-F]-\d+$/i.test(seen) ? seen : seen.replace(/_/g, ' ');
    if (seen && assigned && seen !== v.slot_number) return { seen: seenLabel, assigned, text: `Seen in ${seenLabel} · assigned ${assigned}` };
    if (seen) return { seen: seenLabel, assigned, text: `Seen in ${seenLabel}` };
    if (assigned) return { seen: '', assigned, text: `Assigned bay ${assigned}` };
    return { seen: '', assigned: '', text: 'Driveway / lane' };
}

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
                            <p style="margin: 2px 0 0 0; font-size: 0.84rem;">No active violations or outstanding penalties found for plate <code>${plate}</code>.</p>
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

                const info = violationRule(v.violation_type);
                const place = violationLocation(v);
                html += `
                    <div class="violation-card-item">
                        <div class="violation-card-info">
                            <h4>${v.violation_id ? `Case #V-${Number(v.violation_id)} · ` : ''}${escapeHtml(v.violation_type)}</h4>
                            <p class="violation-rule">${escapeHtml(info.rule)}</p>
                            <dl class="violation-facts">
                                <dt>Plate</dt><dd><code>${escapeHtml(v.plate_number || plate)}</code></dd>
                                <dt>Location</dt><dd>${escapeHtml(place.text)}</dd>
                                ${v.entry_time ? `<dt>Entered</dt><dd>${formatPhtDateTime(v.entry_time)}</dd>` : ''}
                                ${v.detected_at ? `<dt>Detected</dt><dd>${formatPhtDateTime(v.detected_at)}</dd>` : ''}
                                <dt>Issued</dt><dd>${formatPhtDateTime(v.issued_at)}</dd>
                                <dt>Evidence</dt><dd>${escapeHtml(info.basis)}${v.alert_details ? ` <small>Camera record: ${escapeHtml(v.alert_details)}</small>` : ''}</dd>
                            </dl>
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

let sectionalAlertsCache = [];
let violationsCache = [];
let reservationsCache = [];
let refundsCache = [];
let simulationCache = [];
const SECTIONAL_ALERT_FINE = 200;

// A bay is always exactly one of: Occupied, Reserved, Vacant.
function slotStatus(slot) {
    const raw = String(slot.slot_status || slot.availability_status || '').toUpperCase();
    if (raw === 'OCCUPIED') return { key: 'occupied', label: 'Occupied' };
    if (raw === 'RESERVED' || raw === 'HELD') return { key: 'reserved', label: 'Reserved' };
    if (raw === 'VACANT' || raw === 'FREE') return { key: 'vacant', label: 'Vacant' };
    return slot.is_occupied == 1 ? { key: 'occupied', label: 'Occupied' } : { key: 'vacant', label: 'Vacant' };
}

function formatPeso(value) {
    return '₱' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function titleCaseStatus(value) {
    const text = String(value || '').replace(/_/g, ' ').toLowerCase();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

async function postAdmin(action, payload, fallbackMessage) {
    const response = await apiFetch(`${API_BASE}?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    let data = {};
    try { data = await response.json(); } catch (_) { /* handled below */ }
    if (!response.ok || data.success === false) throw new Error(data.error || fallbackMessage);
    return data;
}

// --- TABLE TOOLS: search, filter and sort for any admin table body ---
const tableTools = (() => {
    const states = new Map();
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

    function cellText(row, col, attr) {
        const cell = row.cells[col];
        if (!cell) return '';
        return String(cell.dataset[attr] ?? cell.textContent).replace(/\s+/g, ' ').trim();
    }

    function dataRows(state) {
        return Array.from(state.body.rows).filter(row => !row.classList.contains('no-match') && !row.querySelector('td[colspan]'));
    }

    function setup(bodyId, options) {
        const body = document.getElementById(bodyId);
        if (!body || states.has(bodyId)) return;
        const table = body.closest('table');
        const scroller = table.closest('.table-scroll') || table;
        const filters = options.filters || [];
        const state = { body, table, options, filters, query: '', picked: filters.map(() => ''), sortCol: -1, sortDir: 0, selects: [] };
        states.set(bodyId, state);

        const tools = document.createElement('div');
        tools.className = 'table-tools';

        if (options.search !== false) {
            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'tt-search';
            search.placeholder = options.search || 'Search this table';
            search.setAttribute('aria-label', options.search || 'Search this table');
            search.addEventListener('input', () => { state.query = search.value.trim().toLowerCase(); apply(state); });
            state.searchInput = search;
            tools.append(search);
        }

        filters.forEach((filter, index) => {
            const wrap = document.createElement('label');
            wrap.className = 'tt-filter';
            wrap.append(document.createTextNode(filter.label));
            const select = document.createElement('select');
            select.addEventListener('change', () => { state.picked[index] = select.value; apply(state); });
            wrap.append(select);
            state.selects[index] = select;
            tools.append(wrap);
        });

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'tt-clear';
        clear.textContent = 'Clear filters';
        clear.hidden = true;
        clear.addEventListener('click', () => {
            state.query = '';
            state.picked = filters.map(() => '');
            if (state.searchInput) state.searchInput.value = '';
            apply(state);
        });
        state.clearButton = clear;

        const count = document.createElement('span');
        count.className = 'tt-count';
        count.setAttribute('aria-live', 'polite');
        state.countLabel = count;
        tools.append(clear, count);
        scroller.before(tools);

        const noSort = options.noSort || [];
        Array.from(table.tHead.rows[0].cells).forEach((th, col) => {
            if ((options.numeric || []).includes(col)) th.classList.add('num');
            if (noSort.includes(col)) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'th-sort';
            button.textContent = th.textContent.trim();
            button.addEventListener('click', () => {
                if (state.sortCol !== col) { state.sortCol = col; state.sortDir = 1; }
                else if (state.sortDir === 1) state.sortDir = -1;
                else { state.sortCol = -1; state.sortDir = 0; }
                apply(state);
            });
            th.replaceChildren(button);
            th.setAttribute('aria-sort', 'none');
        });

        // Re-apply whenever a loader rewrites the rows.
        state.observer = new MutationObserver(() => apply(state));
        state.observer.observe(body, { childList: true });
        apply(state);
    }

    function refreshFilterOptions(state, rows) {
        state.filters.forEach((filter, index) => {
            const values = Array.from(new Set(rows.map(row => cellText(row, filter.col, 'filter')).filter(Boolean))).sort(collator.compare);
            const select = state.selects[index];
            if (state.picked[index] && !values.includes(state.picked[index])) state.picked[index] = '';
            select.replaceChildren(new Option('All', ''), ...values.map(value => new Option(value, value)));
            select.value = state.picked[index];
        });
    }

    function apply(state) {
        state.observer.disconnect();
        const rows = dataRows(state);
        rows.forEach((row, i) => { if (row.dataset.order === undefined) row.dataset.order = String(i); });
        refreshFilterOptions(state, rows);
        (state.options.numeric || []).forEach(col => rows.forEach(row => row.cells[col]?.classList.add('num')));

        const tokens = state.query.split(/\s+/).filter(Boolean);
        let shown = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const visible = tokens.every(token => text.includes(token))
                && state.filters.every((filter, i) => !state.picked[i] || cellText(row, filter.col, 'filter') === state.picked[i]);
            row.hidden = !visible;
            if (visible) shown += 1;
        });

        const ordered = rows.slice().sort((a, b) => {
            if (state.sortDir !== 0) {
                const x = cellText(a, state.sortCol, 'sort');
                const y = cellText(b, state.sortCol, 'sort');
                const nx = Number(x);
                const ny = Number(y);
                const result = x !== '' && y !== '' && !Number.isNaN(nx) && !Number.isNaN(ny) ? nx - ny : collator.compare(x, y);
                if (result) return result * state.sortDir;
            }
            return Number(a.dataset.order) - Number(b.dataset.order);
        });
        state.body.querySelector('.no-match')?.remove();
        state.body.append(...ordered);

        if (rows.length && !shown) {
            const empty = document.createElement('tr');
            empty.className = 'no-match';
            const cell = document.createElement('td');
            cell.colSpan = state.table.tHead.rows[0].cells.length;
            cell.textContent = 'No records match the current search and filters.';
            empty.append(cell);
            state.body.append(empty);
        }

        Array.from(state.table.tHead.rows[0].cells).forEach((th, col) => {
            if (th.hasAttribute('aria-sort')) {
                th.setAttribute('aria-sort', state.sortCol === col ? (state.sortDir === 1 ? 'ascending' : 'descending') : 'none');
            }
        });

        const noun = state.options.noun || 'records';
        state.countLabel.textContent = rows.length ? `Showing ${shown} of ${rows.length} ${noun}` : '';
        state.clearButton.hidden = !(state.query || state.picked.some(Boolean));
        state.observer.observe(state.body, { childList: true });
        state.observer.takeRecords();
    }

    return { setup };
})();

// --- REVIEW DIALOG: one place that shows the evidence, rules and consequences before a decision ---
const decisionDialog = (() => {
    const dialog = document.getElementById('decisionDialog');
    if (!dialog) return { open() {} };
    const byId = id => document.getElementById(id);
    const tones = { danger: 'btn-danger', success: 'btn-success', primary: 'btn-primary' };

    function make(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function readValues() {
        const values = {};
        dialog.querySelectorAll('[data-field]').forEach(input => {
            values[input.dataset.field] = input.type === 'checkbox' ? input.checked : input.value.trim();
        });
        return values;
    }

    function close() { if (dialog.open) dialog.close(); }

    function closeButton(label) {
        const button = make('button', 'btn btn-outline', label);
        button.type = 'button';
        button.addEventListener('click', close);
        return button;
    }

    function buildEvidence(evidence) {
        const figure = make('figure', 'decision-evidence');
        const missing = text => make('div', 'ev-missing', text);
        if (evidence && evidence.src) {
            const image = make('img');
            image.src = evidence.src;
            image.alt = evidence.alt || 'Evidence image';
            image.addEventListener('error', () => image.replaceWith(missing('The evidence image could not be loaded.')));
            const caption = make('figcaption');
            const link = make('a', '', 'Open full size');
            link.href = evidence.src;
            link.target = '_blank';
            link.rel = 'noopener';
            caption.append(link);
            figure.append(image, caption);
        } else {
            figure.append(missing((evidence && evidence.missing) || 'No evidence image was captured.'));
        }
        return figure;
    }

    function open(config) {
        byId('decisionTitle').textContent = config.title;
        const subtitle = byId('decisionSub');
        subtitle.textContent = config.subtitle || '';
        subtitle.hidden = !config.subtitle;

        const body = byId('decisionBody');
        body.className = 'decision-body';
        const info = make('div', 'decision-info');
        const errorBox = make('p', 'decision-error');
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;

        if (config.facts && config.facts.length) {
            const list = make('dl', 'decision-facts');
            config.facts.forEach(([label, value]) => {
                list.append(make('dt', '', label), make('dd', '', value === undefined || value === null || value === '' ? '—' : String(value)));
            });
            info.append(list);
        }
        if (config.notice) info.append(make('p', 'decision-notice', config.notice));
        if (config.criteria && config.criteria.length) {
            const wrap = make('div', 'decision-criteria');
            wrap.append(make('h3', '', config.criteriaTitle || 'Check before deciding'));
            const list = make('ul');
            config.criteria.forEach(text => list.append(make('li', '', text)));
            wrap.append(list);
            info.append(wrap);
        }
        (config.fields || []).forEach(field => {
            const wrap = make('div', 'decision-field' + (field.type === 'checkbox' ? ' is-check' : ''));
            const inputId = `decisionField-${field.id}`;
            const label = make('label', '', field.label);
            label.htmlFor = inputId;
            let input;
            if (field.type === 'textarea') input = make('textarea');
            else { input = make('input'); input.type = field.type || 'text'; }
            input.id = inputId;
            input.dataset.field = field.id;
            if (field.min !== undefined) input.min = field.min;
            if (field.type === 'checkbox') { input.checked = !!field.value; label.prepend(input); wrap.append(label); }
            else { input.value = field.value ?? ''; wrap.append(label, input); }
            if (field.help) wrap.append(make('span', 'field-help', field.help));
            info.append(wrap);
        });
        const actions = config.actions || [];
        if (actions.some(action => action.outcome)) {
            const wrap = make('div', 'decision-consequences');
            wrap.append(make('h3', '', actions.length > 1 ? 'What each choice does' : 'What happens'));
            const list = make('ul', 'decision-outcomes');
            actions.filter(action => action.outcome).forEach(action => {
                const item = make('li', action.tone ? `tone-${action.tone}` : '');
                item.append(make('strong', '', action.label), document.createTextNode(action.outcome));
                list.append(item);
            });
            wrap.append(list);
            info.append(wrap);
        }
        info.append(errorBox);

        const parts = [];
        if (config.evidence !== undefined) { body.classList.add('has-evidence'); parts.push(buildEvidence(config.evidence)); }
        body.replaceChildren(...parts, info);

        const footer = byId('decisionActions');
        const cancel = closeButton(actions.length ? 'Cancel' : 'Close');
        const buttons = actions.map(action => {
            const button = make('button', `btn ${tones[action.tone] || 'btn-outline'}`, action.label);
            button.type = 'button';
            button.addEventListener('click', async () => {
                errorBox.hidden = true;
                footer.querySelectorAll('button').forEach(b => { b.disabled = true; });
                try {
                    const result = await action.run(readValues());
                    if (typeof result === 'string' && result) {
                        body.className = 'decision-body';
                        body.replaceChildren(make('p', 'decision-notice', result));
                        footer.replaceChildren(closeButton('Close'));
                        footer.querySelector('button').focus();
                    } else close();
                } catch (error) {
                    errorBox.textContent = error.message || 'Something went wrong. Try again.';
                    errorBox.hidden = false;
                    footer.querySelectorAll('button').forEach(b => { b.disabled = false; });
                }
            });
            return button;
        });
        footer.replaceChildren(cancel, ...buttons);

        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
        (dialog.querySelector('[data-field]') || cancel).focus();
    }

    byId('decisionForm')?.addEventListener('submit', event => event.preventDefault());
    return { open };
})();

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
function safeSectionalText(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function loadSectionalAlerts() {
    const table = document.getElementById('sectionalAlertsTable');
    if (!table) return;
    try {
        const response = await apiFetch(`${API_BASE}?action=sectional_alerts`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Unable to load alerts');
        sectionalAlertsCache = data.alerts;
        const statusClass = { PENDING_REVIEW: 'badge-warning', CONFIRMED: 'badge-danger', REJECTED: 'badge-secondary' };
        table.innerHTML = data.alerts.length ? data.alerts.map(a => {
            const id = Number(a.alert_id);
            const pending = a.status === 'PENDING_REVIEW';
            const statusLabel = titleCaseStatus(a.status);
            const evidenceUrl = `${API_BASE}?action=sectional_evidence&alert_id=${id}`;
            return `
            <tr>
                <td data-sort="${id}" data-filter="${safeSectionalText(statusLabel)}"><strong>#${id}</strong><br><span class="badge ${statusClass[a.status] || 'badge-secondary'}">${safeSectionalText(statusLabel)}</span></td>
                <td data-sort="${safeSectionalText(a.scenario_id)}">${safeSectionalText(a.scenario_id)}<small>${safeSectionalText(a.region)}</small></td>
                <td data-filter="${safeSectionalText(a.violation_type)}"><strong>${safeSectionalText(a.violation_type)}</strong><small>${safeSectionalText(a.details)}</small></td>
                <td data-sort="${a.session_id ? Number(a.session_id) : ''}">${a.session_id ? '#' + Number(a.session_id) : '<small>Not linked</small>'}</td>
                <td>${a.evidence
                    ? `<button type="button" class="thumb-btn" onclick="reviewSectionalAlert(${id})" aria-label="Open evidence for alert ${id}"><img class="alert-thumb" loading="lazy" src="${evidenceUrl}" alt="Evidence for alert ${id}"></button>`
                    : '<small>No image</small>'}</td>
                <td>${pending
                    ? `<button type="button" class="btn btn-sm btn-primary" onclick="reviewSectionalAlert(${id})">Review</button>`
                    : `<div class="row-actions"><button type="button" class="btn btn-sm btn-outline" onclick="reviewSectionalAlert(${id})">View</button></div>${a.reviewed_by ? `<small>by ${safeSectionalText(a.reviewed_by)}</small>` : ''}`}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="6">No sectional alerts.</td></tr>';
    } catch (error) {
        table.innerHTML = `<tr><td colspan="6">${safeSectionalText(error.message)}</td></tr>`;
    }
}

function reviewSectionalAlert(alertId) {
    const a = sectionalAlertsCache.find(item => Number(item.alert_id) === alertId);
    if (!a) return;
    const pending = a.status === 'PENDING_REVIEW';
    const timed = /driveway|double/i.test(String(a.violation_type || ''));
    const facts = [
        ['Alert', `#${alertId}`],
        ['Scenario', a.scenario_id],
        ['Region', a.region],
        ['Finding', a.violation_type],
        ['Details', a.details],
        ['Status', titleCaseStatus(a.status)],
        ['Linked session', a.session_id ? `#${Number(a.session_id)}` : 'None']
    ];
    if (a.plate_number) facts.push(['Plate', a.plate_number]);
    if (a.confidence !== undefined && a.confidence !== null) facts.push(['Detection confidence', a.confidence]);
    if (a.duration_seconds !== undefined && a.duration_seconds !== null) facts.push(['Observed for', `${a.duration_seconds} seconds`]);
    const detected = a.detected_at || a.created_at;
    if (detected) facts.push(['Detected', formatPhtDateTime(detected)]);
    if (a.reviewed_by) facts.push(['Reviewed by', a.reviewed_by]);

    const criteria = [`The image shows a vehicle in region ${a.region || 'shown'} that matches the finding.`];
    if (timed) criteria.push('Driveway and double-parking alerts need 20 continuous seconds in every zone. Check the details for the observed time.');
    criteria.push('If you link a session, its plate should match the vehicle in the image.');

    decisionDialog.open({
        title: pending ? `Review alert #${alertId}` : `Alert #${alertId}`,
        subtitle: pending ? 'Compare the image with the finding, then confirm or reject.' : 'This alert has already been reviewed.',
        evidence: a.evidence
            ? { src: `${API_BASE}?action=sectional_evidence&alert_id=${alertId}`, alt: `Evidence for alert ${alertId}` }
            : { missing: 'No image was captured for this alert. Decide from the finding details only.' },
        facts,
        criteria: pending ? criteria : null,
        fields: pending ? [{ id: 'session', label: 'Vehicle session ID', type: 'number', min: 1, value: a.session_id ? Number(a.session_id) : '', help: 'Optional. Link the session this alert applies to.' }] : [],
        actions: pending ? [
            { label: 'Reject alert', tone: '', outcome: 'Closes the alert. No fine is issued.', run: values => submitAlertReview(alertId, 'REJECTED', values.session) },
            { label: `Confirm and fine ${formatPeso(SECTIONAL_ALERT_FINE)}`, tone: 'danger', outcome: `Records the violation with a ${formatPeso(SECTIONAL_ALERT_FINE)} fine.`, run: values => submitAlertReview(alertId, 'CONFIRMED', values.session) }
        ] : []
    });
}

async function submitAlertReview(alertId, decision, rawSession) {
    const sessionId = rawSession ? Number(rawSession) : null;
    if (rawSession && (!Number.isInteger(sessionId) || sessionId < 1)) throw new Error('Enter a valid session ID, or leave it blank.');
    await postAdmin('review_sectional_alert', { alert_id: alertId, decision, session_id: sessionId }, 'Review failed');
    loadSectionalAlerts();
    loadAdminViolations();
    loadAdminAnalytics();
}

async function loadAdminViolations() {
    const table = document.getElementById('adminViolationsTable');
    if (!table) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_violations_list`);
        const data = await response.json();

        if (!data.success || !data.violations) {
            table.innerHTML = `<tr><td colspan="8">${escapeHtml(data.error || 'Failed to load violations.')}</td></tr>`;
            return;
        }

        const violations = data.violations;
        violationsCache = violations;
        if (violations.length === 0) {
            table.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 15px;">No violations on record.</td></tr>`;
            return;
        }

        table.innerHTML = violations.map(v => {
            const id = Number(v.violation_id);
            const isPaid = v.status === 'PAID';
            const isDismissed = v.status === 'DISMISSED';
            const statusLabel = isPaid ? 'Paid' : isDismissed ? 'Dismissed' : 'Unpaid';
            const statusBadge = isPaid
                ? `<span class="badge badge-paid">Paid</span>`
                : isDismissed ? `<span class="badge badge-secondary">Dismissed</span>` : `<span class="badge badge-unpaid">Unpaid</span>`;

            const viewButton = `<button class="btn btn-sm btn-outline" onclick="viewAdminViolation(${id})">View case</button>`;
            const actionCell = (isPaid || isDismissed)
                ? `<div class="row-actions">${viewButton}</div><small>${isPaid ? 'Settled' : 'Closed'}</small>`
                : `<div class="row-actions">
                       ${viewButton}
                       <button class="btn btn-sm btn-success" onclick="resolveAdminViolation(${id}, 'PAID')">Mark paid</button>
                       <button class="btn btn-sm btn-outline" onclick="resolveAdminViolation(${id}, 'DISMISSED')">Dismiss</button>
                   </div>`;
            const place = violationLocation(v);
            const info = violationRule(v.violation_type);
            const evidenceNote = Number(v.has_evidence) === 1
                ? `Camera alert #${Number(v.alert_id)} with image`
                : v.alert_id ? `Camera alert #${Number(v.alert_id)}, no image` : 'No camera alert linked';

            return `
                <tr>
                    <td data-sort="${id}"><strong>#V-${id}</strong>${v.session_id ? `<small>Session #${Number(v.session_id)}</small>` : ''}</td>
                    <td><code>${escapeHtml(v.plate_number || 'N/A')}</code>${v.vehicle_color && v.vehicle_color !== 'UNKNOWN' ? `<small>${escapeHtml(v.vehicle_color.toLowerCase())}${v.vehicle_type ? ' ' + escapeHtml(v.vehicle_type.toLowerCase()) : ''}</small>` : ''}</td>
                    <td data-filter="Zone ${escapeHtml(v.zone || '—')}">${escapeHtml(place.text)}</td>
                    <td data-filter="${escapeHtml(v.violation_type)}"><strong>${escapeHtml(v.violation_type)}</strong><small>${escapeHtml(v.alert_details || info.basis)}</small><small class="violation-evidence-note">${escapeHtml(evidenceNote)}</small></td>
                    <td data-sort="${Number(v.penalty_amount)}"><strong>${formatPeso(v.penalty_amount)}</strong></td>
                    <td data-filter="${statusLabel}">${statusBadge}</td>
                    <td data-sort="${escapeHtml(v.issued_at)}" style="font-size: 0.84rem; color: var(--text-secondary);">${formatPhtDateTime(v.issued_at)}</td>
                    <td>${actionCell}</td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading admin violations:', err);
        table.innerHTML = `<tr><td colspan="8">Failed to load violations.</td></tr>`;
    }
}

function violationCaseFacts(v) {
    const place = violationLocation(v);
    const facts = [
        ['Case', `#V-${Number(v.violation_id)}`],
        ['Plate', v.plate_number || 'N/A'],
        ['Vehicle', [v.vehicle_color && v.vehicle_color !== 'UNKNOWN' ? v.vehicle_color.toLowerCase() : '', v.vehicle_type ? v.vehicle_type.toLowerCase() : ''].filter(Boolean).join(' ') || 'Not recorded'],
        ['Infraction', v.violation_type],
        ['Seen in', place.seen || 'Not recorded'],
        ['Assigned bay', place.assigned || 'None'],
        ['Penalty', formatPeso(v.penalty_amount)],
        ['Case status', titleCaseStatus(v.status || 'UNPAID')]
    ];
    if (v.session_id) facts.push(['Parking session', `#${Number(v.session_id)} · ${titleCaseStatus(v.payment_status || 'UNPAID')}`]);
    if (v.entry_time) facts.push(['Entered', formatPhtDateTime(v.entry_time)]);
    if (v.exit_time) facts.push(['Exited', formatPhtDateTime(v.exit_time)]);
    if (v.camera_id) facts.push(['Camera', v.camera_id]);
    if (v.detected_at) facts.push(['Detected', formatPhtDateTime(v.detected_at)]);
    if (v.alert_details) facts.push(['Camera record', v.alert_details]);
    if (v.reviewed_by) facts.push(['Confirmed by', `${v.reviewed_by}${v.reviewed_at ? ' · ' + formatPhtDateTime(v.reviewed_at) : ''}`]);
    facts.push(['Fine issued', formatPhtDateTime(v.issued_at)]);
    return facts;
}

function violationEvidence(v) {
    return Number(v.has_evidence) === 1 && v.alert_id
        ? { src: `${API_BASE}?action=sectional_evidence&alert_id=${Number(v.alert_id)}`, alt: `Camera frame for case #V-${Number(v.violation_id)}` }
        : { missing: v.alert_id ? 'The camera alert for this case has no saved image.' : 'This case was recorded without a camera alert, so there is no image.' };
}

function viewAdminViolation(violationId) {
    const v = violationsCache.find(item => Number(item.violation_id) === violationId);
    if (!v) return;
    const info = violationRule(v.violation_type);
    decisionDialog.open({
        title: `Case #V-${violationId}: ${v.violation_type}`,
        subtitle: info.rule,
        evidence: violationEvidence(v),
        facts: violationCaseFacts(v),
        notice: `Basis for the fine: ${info.basis}`,
        actions: []
    });
}

function resolveAdminViolation(violationId, status) {
    const v = violationsCache.find(item => Number(item.violation_id) === violationId);
    if (!v) return;
    const paying = status === 'PAID';
    const amount = formatPeso(v.penalty_amount);
    const facts = violationCaseFacts(v);
    decisionDialog.open({
        title: paying ? `Mark case #V-${violationId} as paid` : `Dismiss case #V-${violationId}`,
        subtitle: paying ? 'Record a cash payment received at the desk.' : 'Close this case without collecting the penalty.',
        evidence: violationEvidence(v),
        facts,
        criteria: paying ? null : [
            'The image or camera record does not show the vehicle committing this infraction, or',
            'The plate in the image does not match the plate on this case.'
        ],
        fields: paying ? [{ id: 'received', type: 'checkbox', label: `I have received ${amount} in cash for this penalty.` }] : [],
        actions: [{
            label: paying ? `Mark ${amount} as paid` : 'Dismiss case',
            tone: paying ? 'success' : 'danger',
            outcome: paying ? 'Settles the penalty and adds an entry to the audit trail.' : 'Closes the case with no payment and adds an entry to the audit trail.',
            run: async values => {
                if (paying && !values.received) throw new Error('Confirm that the cash was received first.');
                await postAdmin('admin_resolve_violation', { violation_id: violationId, status }, 'Failed to update violation.');
                loadAdminViolations();
                loadAdminStats();
                loadAdminAnalytics();
                loadAdminAuditLogs();
            }
        }]
    });
}

// --- 4. IMMUTABLE AUDIT TRAIL LOGS ---
async function loadAdminAuditLogs() {
    const table = document.getElementById('adminAuditLogsTable');
    if (!table) return;

    try {
        const response = await apiFetch(`${API_BASE}?action=admin_audit_logs`);
        const data = await response.json();

        if (!data.success || !data.logs) {
            table.innerHTML = `<tr><td colspan="5">${escapeHtml(data.error || 'Failed to load audit logs.')}</td></tr>`;
            return;
        }

        const logs = data.logs;
        if (logs.length === 0) {
            table.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 15px;">No audit trail records logged.</td></tr>`;
            return;
        }

        table.innerHTML = logs.map(l => {
            const actionClass = `audit-action-${escapeHtml(l.action_type)}`;
            return `
                <tr>
                    <td data-sort="${escapeHtml(l.created_at)}" style="white-space: nowrap; font-size: 0.84rem; color: var(--text-secondary);">${formatPhtDateTime(l.created_at)}</td>
                    <td data-filter="${escapeHtml(l.admin_email)}"><strong>${escapeHtml(l.admin_email)}</strong></td>
                    <td data-filter="${escapeHtml(l.action_type)}"><span class="audit-action-tag ${actionClass}">${escapeHtml(l.action_type)}</span></td>
                    <td><code>${escapeHtml(l.target_entity)} #${escapeHtml(l.target_id || '')}</code></td>
                    <td>${escapeHtml(l.details)}</td>
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
            table.innerHTML = `<tr><td colspan="7">${escapeHtml(data.error || 'Failed to load reservations.')}</td></tr>`;
            return;
        }

        const reservations = data.reservations;
        reservationsCache = reservations || [];

        if (!reservations || reservations.length === 0) {
            table.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 15px;">No active reservations on record.</td></tr>`;
            return;
        }

        table.innerHTML = reservations.map(r => `
            <tr>
                <td><code>${escapeHtml(r.plate_number)}</code></td>
                <td>${escapeHtml(r.email || '—')} ${r.is_vip ? '<span class="badge badge-vip">VIP</span>' : ''}</td>
                <td data-filter="Zone ${escapeHtml(r.zone)}">Zone ${escapeHtml(r.zone)} &bull; Slot ${escapeHtml(r.slot_number)}</td>
                <td data-sort="${escapeHtml(r.start_date || '')}">${escapeHtml(r.start_date || 'N/A')}</td>
                <td data-sort="${escapeHtml(r.end_date || '')}">${escapeHtml(r.end_date || 'N/A')}</td>
                <td data-sort="${Number(r.fee)}"><strong>${formatPeso(r.fee)}</strong></td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="overrideReservation(${Number(r.reservation_id)})">Cancel booking</button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error('Error loading admin reservations:', err);
        table.innerHTML = `<tr><td colspan="7">Failed to load reservations.</td></tr>`;
    }
}

async function loadAdminRefundQueue() {
    const table = document.getElementById('adminRefundQueue');
    if (!table) return;
    try {
        const response = await apiFetch(`${API_BASE}?action=admin_refund_queue`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Refund queue unavailable');
        refundsCache = data.refunds;
        const escape = value => String(value ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        table.innerHTML = data.refunds.length ? data.refunds.map(r => {
            const statusLabel = titleCaseStatus(r.refund_status);
            const canApprove = r.refund_status === 'STAFF_REVIEW' && r.reservation_status === 'CANCELLED';
            return `<tr>
            <td data-sort="${Number(r.reservation_id)}">#${Number(r.reservation_id)}</td><td><code>${escape(r.plate_number)}</code></td>
            <td data-sort="${Number(r.amount_centavos) / 100}">${formatPeso(Number(r.amount_centavos) / 100)}</td>
            <td data-filter="${escape(statusLabel)}">${escape(statusLabel)}</td><td><code>${escape(r.payment_id)}</code></td>
            <td>${canApprove ? `<button class="btn btn-sm btn-primary" onclick="requestReservationRefund(${Number(r.reservation_id)})">Review refund</button>` : '<small>Handled with the payment provider</small>'}</td>
        </tr>`;
        }).join('') : '<tr><td colspan="6">No refunds awaiting review.</td></tr>';
    } catch (error) { table.innerHTML = '<tr><td colspan="6">Refund queue unavailable.</td></tr>'; }
}

function requestReservationRefund(reservationId) {
    const r = refundsCache.find(item => Number(item.reservation_id) === reservationId);
    if (!r) return;
    const amount = formatPeso(Number(r.amount_centavos) / 100);
    decisionDialog.open({
        title: `Review refund for booking #${reservationId}`,
        subtitle: 'The booking was cancelled after payment.',
        facts: [
            ['Booking', `#${reservationId}`],
            ['Plate', r.plate_number],
            ['Booking status', titleCaseStatus(r.reservation_status)],
            ['Refund status', titleCaseStatus(r.refund_status)],
            ['Amount paid', amount],
            ['Payment reference', r.payment_id]
        ],
        criteria: ['The booking is cancelled and the slot is no longer held.', 'The amount matches what the driver paid.'],
        notice: 'Local demo payments move no real money.',
        actions: [{
            label: `Approve ${amount} refund`,
            tone: 'primary',
            outcome: `Approves a full refund of ${amount} for this booking.`,
            run: async () => {
                const data = await postAdmin('admin_request_refund', { reservation_id: reservationId }, 'Refund status unavailable.');
                loadAdminRefundQueue();
                loadAdminAnalytics();
                return data.message || 'Refund review recorded.';
            }
        }]
    });
}

function overrideReservation(reservationId) {
    const r = reservationsCache.find(item => Number(item.reservation_id) === reservationId);
    if (!r) return;
    decisionDialog.open({
        title: `Cancel booking for ${r.plate_number}`,
        subtitle: 'This is a staff override of a prepaid reservation.',
        facts: [
            ['Plate', r.plate_number],
            ['Account', r.email || '—'],
            ['Space', `Zone ${r.zone}, slot ${r.slot_number}`],
            ['Period', `${r.start_date || 'N/A'} to ${r.end_date || 'N/A'}`],
            ['Prepaid fee', formatPeso(r.fee)]
        ],
        actions: [{
            label: 'Cancel booking',
            tone: 'danger',
            outcome: 'Cancels the reservation, frees the assigned slot, and adds an entry to the audit trail. A paid booking then appears in Reservation Refund Review.',
            run: async () => {
                await postAdmin('override_reservation', { reservation_id: reservationId, status: 'CANCELLED', release_slot: true }, 'Failed to override reservation.');
                loadAdminReservations();
                loadAdminRefundQueue();
                loadAdminStats();
                loadAdminAnalytics();
                loadAdminMap();
                loadAdminAuditLogs();
            }
        }]
    });
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
                    <td><strong>${escapeHtml(a.email)}</strong></td>
                    <td><code>${escapeHtml(a.plate_number || 'No linked vehicle')}</code></td>
                    <td data-filter="${a.plate_number ? (isVip ? 'VIP / PWD priority' : 'Standard') : 'Staff account'}">${a.plate_number ? (isVip ? '<span class="badge badge-vip">VIP / PWD PRIORITY</span>' : '<span class="badge badge-bypassed">STANDARD</span>') : 'Staff account'}</td>
                    <td data-sort="${role}" data-filter="${role}">
                        <select id="accountRole-${Number(a.account_id)}" aria-label="Role for ${escapeHtml(a.email)}">
                            ${['ADMIN', 'DRIVER', 'CASHIER', 'VENDOR'].map(option => `<option value="${option}" ${role === option ? 'selected' : ''} ${option === 'DRIVER' && !a.plate_number ? 'disabled' : ''}>${option}</option>`).join('')}
                        </select>
                        <button class="btn btn-sm btn-outline" onclick="setAccountRole(${a.account_id})">Save Role</button>
                    </td>
                    <td>
                        <button class="btn btn-sm ${isVip ? 'btn-outline' : 'btn-primary'}" onclick="setVip(${a.account_id}, ${isVip ? 0 : 1})" ${a.plate_number ? '' : 'disabled title="Staff account has no vehicle"'}>
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
                    <td data-sort="${escapeHtml(log.timestamp)}" style="white-space: nowrap; font-size: 0.84rem; color: var(--text-secondary);">${formatPhtDateTime(log.timestamp)}</td>
                    <td><code>${log.plate_number}</code></td>
                    <td>${log.zone ? 'Zone ' + log.zone + ' &bull; ' : ''}Slot ${log.slot_number || 'Unassigned'}</td>
                    <td data-sort="98.5"><span style="font-weight: 600; color: var(--primary);">98.5%</span></td>
                    <td data-filter="${log.location_status === 'EXITED' ? 'Exit gate' : 'Entrance gate'}">${locStatus}</td>
                    <td data-filter="${escapeHtml(log.status || 'UNPAID')}"><span class="badge ${badgeClass}">${escapeHtml(log.status || 'UNPAID')}</span></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading ALPR logs:', err);
    }
}

// --- 8. INTERACTIVE SLOT MANAGEMENT & OVERRIDES ---
async function loadSimulationSessions() {
    const body = document.getElementById('simulationSessionsBody');
    if (!body) return;
    try {
        const response = await apiFetch(`${API_BASE}?action=admin_simulation_sessions`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Unable to load simulation sessions.');
        simulationCache = data.sessions;
        body.innerHTML = data.sessions.length ? data.sessions.map(session => `
            <tr>
                <td data-sort="${Number(session.session_id)}">#${Number(session.session_id)}</td>
                <td data-filter="${safeSectionalText(session.scenario_id)}">${safeSectionalText(session.scenario_id)}</td>
                <td><code>${safeSectionalText(session.plate_number)}</code></td>
                <td>${safeSectionalText(session.slot_number)}</td>
                <td data-filter="${safeSectionalText(titleCaseStatus(session.payment_status))}">${safeSectionalText(titleCaseStatus(session.payment_status))}</td>
                <td><button type="button" class="btn btn-sm btn-outline" onclick="recoverSimulationSession(${Number(session.session_id)})">Close session</button></td>
            </tr>`).join('') : '<tr><td colspan="6">No open simulation sessions.</td></tr>';
    } catch (error) {
        body.innerHTML = `<tr><td colspan="6">${safeSectionalText(error.message)}</td></tr>`;
    }
}

function recoverSimulationSession(sessionId) {
    const session = simulationCache.find(item => Number(item.session_id) === sessionId);
    if (!session) return;
    decisionDialog.open({
        title: `Close simulation session #${sessionId}`,
        subtitle: 'Use this only when the simulation ended before its exit feed.',
        facts: [
            ['Session', `#${sessionId}`],
            ['Scenario', session.scenario_id],
            ['Plate', session.plate_number],
            ['Bay', session.slot_number],
            ['Payment', titleCaseStatus(session.payment_status)]
        ],
        criteria: ['Payment or validation is cleared.', 'Any violation fees on the session are cleared.'],
        fields: [{ id: 'reason', type: 'textarea', label: 'Reason for closing', help: 'At least 8 characters. Saved with the recovery record.' }],
        actions: [{
            label: 'Close session',
            tone: 'danger',
            outcome: 'Closes the session and reconciles its slot and zone count. It is recorded as a simulation recovery, not a camera-confirmed departure.',
            run: async values => {
                if (values.reason.length < 8) throw new Error('Enter a reason of at least 8 characters.');
                await postAdmin('admin_close_simulation_session', { session_id: sessionId, reason: values.reason }, 'Unable to close session.');
                await loadSimulationSessions();
                loadAdminMap();
                loadAdminStats();
                loadAdminAuditLogs();
            }
        }]
    });
}

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
        const counts = { occupied: 0, reserved: 0, vacant: 0 };
        zoneSlots.forEach(s => { counts[slotStatus(s).key] += 1; });

        const cards = zoneSlots.map(slot => {
            const status = slotStatus(slot);
            const isOccupied = status.key === 'occupied';
            const plate = slot.plate_number ? normalizeLicensePlateInput(slot.plate_number) : '';
            const plateDisplay = plate
                ? `<code>${safeSectionalText(plate)}</code>${status.key === 'reserved' ? ' <small>reserved for</small>' : ''}`
                : '—';
            const cameraPlate = slot.observed_plate ? normalizeLicensePlateInput(slot.observed_plate) : '';
            let plateCheck = '';
            if (slot.plate_check === 'MATCH') plateCheck = `<small class="plate-check is-match">Camera read matches</small>`;
            else if (slot.plate_check === 'MISMATCH') plateCheck = `<small class="plate-check is-mismatch">Camera read ${safeSectionalText(cameraPlate)} does not match</small>`;
            else if (cameraPlate && !plate) plateCheck = `<small class="plate-check">Camera read ${safeSectionalText(cameraPlate)}</small>`;
            const camera = zone === 'F'
                ? `Zone allocation: ${Number(slot.zone_allocated_count || 0)}`
                : `Camera: ${titleCaseStatus(slot.observed_state || 'NOT_OBSERVED')}${slot.observed_color && slot.observed_color !== 'UNKNOWN' ? ` · ${safeSectionalText(slot.observed_color.toLowerCase())}` : ''}`;
            const next = isOccupied ? 'vacant' : 'occupied';

            return `
                <div class="admin-slot-card slot-${status.key}">
                    <strong>${slot.slot_number}</strong>
                    <div class="slot-plate">${plateDisplay}</div>
                    ${plateCheck}
                    <p class="status-text slot-${status.key}">
                        <span class="slot-state is-${status.key}"><i aria-hidden="true"></i>${status.label}</span>
                        <small class="slot-detail">${camera}</small>
                    </p>
                    <button type="button" class="btn-action slot-override-button" aria-label="Mark ${slot.slot_number} ${next}" onclick="toggleSlotOverride(${slot.slot_id}, ${isOccupied ? 0 : 1})">
                        Mark ${next}
                    </button>
                </div>
            `;
        }).join('');

        return `
            <div class="zone-section">
                <div class="zone-header">
                    <h3>Zone ${zone}</h3>
                    <span class="zone-badge"><strong>${counts.vacant}</strong> vacant <span aria-hidden="true">·</span> ${counts.reserved} reserved <span aria-hidden="true">·</span> ${counts.occupied} occupied</span>
                </div>
                <div class="admin-grid">
                    ${cards}
                </div>
            </div>
        `;
    }).join('');
}

function toggleSlotOverride(slotId, newOccupiedState) {
    const slot = adminSlotsCache.find(item => Number(item.slot_id) === slotId);
    if (!slot) return;
    const stateName = newOccupiedState ? 'occupied' : 'vacant';
    const status = slotStatus(slot);
    const facts = [
        ['Space', `${slot.slot_number} (Zone ${slot.zone})`],
        ['Current status', status.label],
        [status.key === 'reserved' ? 'Reserved for' : 'Plate on record', slot.plate_number ? normalizeLicensePlateInput(slot.plate_number) : 'None']
    ];
    let notice = status.key === 'reserved' && !newOccupiedState
        ? 'This bay has an active reservation. It stays reserved until the booking is cancelled or expires.'
        : '';
    if (slot.zone === 'F') {
        facts.push(['Zone allocation', Number(slot.zone_allocated_count || 0)]);
    } else {
        const observed = slot.observed_state || 'NOT_OBSERVED';
        facts.push(['Camera state', observed]);
        if (slot.observed_color && slot.observed_color !== 'UNKNOWN') facts.push(['Vehicle colour', slot.observed_color.toLowerCase()]);
        if (slot.observed_plate) {
            const check = slot.plate_check === 'MATCH' ? ' (matches)' : slot.plate_check === 'MISMATCH' ? ' (does not match plate on record)' : '';
            facts.push(['Plate read by camera', normalizeLicensePlateInput(slot.observed_plate) + check]);
        }
        if (observed === 'NOT_OBSERVED' && !notice) notice = 'The camera has no reading for this space, so this change rests on your own check of the bay.';
    }
    decisionDialog.open({
        title: `Mark ${slot.slot_number} as ${stateName}`,
        subtitle: 'Manual override of the recorded space status.',
        facts,
        notice,
        criteria: ['Check the camera feed or the bay itself first.', 'The recorded status should not match what you see.'],
        actions: [{
            label: `Mark as ${stateName}`,
            tone: newOccupiedState ? 'danger' : 'success',
            outcome: `Sets ${slot.slot_number} to ${stateName} and adds an entry to the audit trail.`,
            run: async () => {
                await postAdmin('toggle_slot', { slot_id: slotId, is_occupied: newOccupiedState }, 'Failed to update slot.');
                loadAdminStats();
                loadAdminAnalytics();
                loadAdminMap();
                loadAdminAuditLogs();
            }
        }]
    });
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
    tableTools.setup('sectionalAlertsTable', { noun: 'alerts', search: 'Search alerts by scenario, region or finding', filters: [{ col: 0, label: 'Status' }, { col: 2, label: 'Finding' }], noSort: [4, 5] });
    tableTools.setup('adminViolationsTable', { noun: 'cases', search: 'Search by case, plate or infraction', filters: [{ col: 5, label: 'Status' }, { col: 3, label: 'Infraction' }], numeric: [4], noSort: [7] });
    tableTools.setup('alprLogsTable', { noun: 'detections', search: 'Search by plate, slot or status', filters: [{ col: 5, label: 'Session status' }, { col: 4, label: 'Gate' }], numeric: [3] });
    tableTools.setup('simulationSessionsBody', { noun: 'sessions', search: 'Search by session, scenario or plate', filters: [{ col: 4, label: 'Payment' }, { col: 1, label: 'Scenario' }], noSort: [5] });
    tableTools.setup('adminReservationsTable', { noun: 'reservations', search: 'Search by plate, account or zone', filters: [{ col: 2, label: 'Zone' }], numeric: [5], noSort: [6] });
    tableTools.setup('adminRefundQueue', { noun: 'refunds', search: 'Search by booking, plate or payment reference', filters: [{ col: 3, label: 'Refund status' }], numeric: [2], noSort: [5] });
    tableTools.setup('adminAccountsTable', { noun: 'accounts', search: false, filters: [{ col: 3, label: 'Role' }, { col: 2, label: 'Privilege' }], noSort: [4] });
    tableTools.setup('adminAuditLogsTable', { noun: 'entries', search: 'Search by operator, action or details', filters: [{ col: 2, label: 'Action' }, { col: 1, label: 'Operator' }] });
    loadAdminStats();
    loadAdminAnalytics();
    loadAdminViolations();
    loadSectionalAlerts();
    loadAlprLogs();
    loadAdminMap();
    loadSimulationSessions();
    loadAdminReservations();
    loadAdminRefundQueue();
    loadAdminAccounts();
    loadAdminAuditLogs();

    // Refresh payment and violation status when Cashier settles a citation in another tab.
    setInterval(() => {
        loadAdminStats();
        loadAlprLogs();
        loadAdminViolations();
        loadSectionalAlerts();
    }, 30000);
});