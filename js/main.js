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

// ---------------- AUTH ----------------
if (authForm) {

    authForm.addEventListener('submit', async (e) => {

        e.preventDefault();

        const email = document.getElementById('email').value;
        const plate = document.getElementById('licensePlate').value;

        currentUser.licensePlate = plate.toUpperCase();
        currentUser.isVIP = false;

        authSection.classList.add('hidden');
        dashboardSection.classList.remove('hidden');

        fetchLiveMap();

    });

}

// ---------------- MAP ----------------
async function fetchLiveMap() {

    if (!liveMap) return;

    liveMap.innerHTML = `
        <div class="zone available">Zone A</div>
        <div class="zone available">Zone B</div>
        <div class="zone available">Zone C</div>
    `;

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

        alert("Reservation Submitted");

    });

}

// ---------------- VIOLATIONS ----------------
if (violationForm) {

    violationForm.addEventListener("submit", async function (e) {

        e.preventDefault();

        const plate = document.getElementById("searchPlate").value;

        try {

            const response = await fetch(
                `api/endpoints.php?action=violations&plate=${encodeURIComponent(plate)}`
            );

            const data = await response.json();

            if (data.length === 0) {

                violationResults.innerHTML =
                    "<p>No violations found.</p>";

                return;

            }

            let html = "";

            data.forEach(v => {

                html += `
                    <div class="card">
                        <h3>${v.violation_type}</h3>

                        <p><strong>Penalty:</strong> ₱${v.penalty_amount}</p>

                        <p><strong>Status:</strong> ${v.status}</p>

                        <p><strong>Issued:</strong> ${v.issued_at}</p>
                    </div>
                `;

            });

            violationResults.innerHTML = html;

        }
        catch (err) {

            console.error(err);

            violationResults.innerHTML =
                "<p>Failed to load violations.</p>";

        }

    });

}