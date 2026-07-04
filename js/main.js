// --- DOM Elements ---
const authForm = document.getElementById('authForm');
const registerForm = document.getElementById('registerForm'); // Added for registration
const liveMap = document.getElementById('liveMap');
const reservationForm = document.getElementById('reservationForm');
const durationInput = document.getElementById('duration');
const feeDisplay = document.getElementById('feeDisplay');
const qrWalletSection = document.getElementById('qrWalletSection');
const qrCodeImage = document.getElementById('qrCodeImage');
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
        
        let mapHTML = '';
        data.forEach(zone => {
            const isFull = zone.true_available <= 0;
            const statusClass = isFull ? 'full' : 'available';
            mapHTML += `
                <div class="zone ${statusClass}">
                    <h3>Zone ${zone.zone_id}</h3>
                    <p>${zone.true_available} / ${zone.max_capacity} Slots</p>
                </div>
            `;
        });
        liveMap.innerHTML = mapHTML;
    } catch (error) {
        liveMap.innerHTML = '<p>Error loading live map data.</p>';
    }
}

// Auto-load map if we are on the dashboard
if (liveMap) {
    fetchLiveMap();
}

// ---------------- BILLING ----------------
if (durationInput && feeDisplay) {
    durationInput.addEventListener('input', (e) => {
        let days = parseInt(e.target.value);
        if (days > 3) days = 3;
        if (days < 1) days = 1;
        e.target.value = days;
        feeDisplay.textContent = (days * 60).toFixed(2);
    });
}

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

        const zone = document.getElementById('zoneSelect').value;
        const days = document.getElementById('duration').value;

        try {
            const response = await fetch('api/endpoints.php?action=reserve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    plate: currentUser.licensePlate, 
                    zone: zone, 
                    days: days 
                })
            });
            const data = await response.json();

            if (data.success) {
                alert(`Payment of ₱${data.fee.toFixed(2)} processed successfully!`);
                // Render the digital QR wallet
                if (qrCodeImage && qrWalletSection) {
                    qrCodeImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${data.qr_token}`;
                    qrWalletSection.style.display = 'block';
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
