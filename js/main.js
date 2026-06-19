// --- DOM Elements ---
const authForm = document.getElementById('authForm');
const authSection = document.getElementById('authSection');
const dashboardSection = document.getElementById('dashboardSection');
const liveMap = document.getElementById('liveMap');
const reservationForm = document.getElementById('reservationForm');
const durationInput = document.getElementById('duration');
const feeDisplay = document.getElementById('feeDisplay');
const qrWalletSection = document.getElementById('qrWalletSection');
const qrCodeImage = document.getElementById('qrCodeImage');
const violationForm = document.getElementById('violationForm');
const violationResults = document.getElementById('violationResults');

// --- Global State ---
let currentUser = {
    isVIP: false,
    licensePlate: ''
};

// --- Authentication ---
authForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('email').value;
    const plate = document.getElementById('licensePlate').value;

    try {
        // Placeholder API Call: POST /api/auth.php
        // const response = await fetch('/api/auth.php', { method: 'POST', body: JSON.stringify({email, plate}) });
        
        // Simulating successful login
        currentUser.licensePlate = plate.toUpperCase();
        currentUser.isVIP = false; // Toggle this to test Zone A restriction
        
        authSection.classList.add('hidden');
        dashboardSection.classList.remove('hidden');
        fetchLiveMap();
    } catch (error) {
        alert('Authentication failed.');
    }
});

// --- Live Parking Map ---
async function fetchLiveMap() {
    try {
        // Placeholder API Call: GET /api/map.php
        // const response = await fetch('/api/map.php');
        // const data = await response.json();
        
        // Simulated Data (True Availability calculated via YOLOv8 - Active Reservations)
        const mapData = [
            { zone: 'A', available: 5, total: 10 },
            { zone: 'B', available: 0, total: 20 },
            { zone: 'C', available: 12, total: 20 },
            { zone: 'D', available: 15, total: 20 },
            { zone: 'E', available: 8, total: 15 }
        ];

        liveMap.innerHTML = '';
        mapData.forEach(area => {
            const isFull = area.available === 0;
            const div = document.createElement('div');
            div.className = `zone ${isFull ? 'full' : 'available'}`;
            div.innerHTML = `Zone ${area.zone}<br>${area.available}/${area.total} Slots`;
            liveMap.appendChild(div);
        });
    } catch (error) {
        console.error("Failed to load map data", error);
    }
}

// --- Dynamic Billing Display ---
durationInput.addEventListener('input', (e) => {
    let days = parseInt(e.target.value);
    if(days > 3) days = 3;
    if(days < 1) days = 1;
    e.target.value = days;
    feeDisplay.textContent = (days * 60).toFixed(2);
});

// --- Slot Reservation System ---
reservationForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const zone = document.getElementById('zoneSelect').value;
    const days = parseInt(durationInput.value);

    // Business Rule: Restrict Zone A to PWD/VIP
    if (zone === 'A' && !currentUser.isVIP) {
        alert('Access Denied: Zone A is strictly reserved for PWD and VIP accounts.');
        return;
    }

    try {
        // Placeholder API Call: POST /api/reserve.php
        // Payload: { plate: currentUser.licensePlate, zone, days }
        
        // Simulate successful payment and QR generation
        alert(`Payment of ₱${(days * 60).toFixed(2)} processed successfully!`);
        
        // Render dummy QR token
        qrCodeImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=RESERVE_${currentUser.licensePlate}_${zone}`;
        qrWalletSection.style.display = 'block';
        fetchLiveMap(); // Refresh map capacity
    } catch (error) {
        alert('Reservation failed. Please try again.');
    }
});

// --- Billing & Violations Tracker ---
violationForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const searchPlate = document.getElementById('searchPlate').value.toUpperCase();

    try {
        // Placeholder API Call: GET /api/violations.php?plate=...
        
        // Simulating backend response
        if (searchPlate === currentUser.licensePlate) {
            violationResults.innerHTML = `
                <div style="background: #fed7d7; padding: 1rem; border-radius: 4px; color: #e53e3e;">
                    <strong>Violation Alert:</strong> Unauthorized Spatial Allocation detected in Zone C.
                    <br><strong>Fee:</strong> ₱100.00 Pending.
                </div>`;
        } else {
            violationResults.innerHTML = `<p style="color: var(--success)">No outstanding bills or violations found for ${searchPlate}.</p>`;
        }
    } catch (error) {
        console.error("Failed to fetch violations", error);
    }
});
