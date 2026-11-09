# Local simulated reservation payments

This guide runs the Parking Ltd. reservation checkout entirely on your computer. The page resembles a hosted payment checkout, but the card and wallet are illustrations and the confirmation is simulated by the local PHP server. **No payment provider is contacted, no real card or wallet is accepted, no SMS/OTP is sent, and no money moves.** The feature is intended for project demonstrations and flow testing.

The server still enforces the reservation rules: it calculates the tariff, holds a slot for 10 minutes, keeps the booking pending until the success scenario is confirmed, and creates the QR pass only after that confirmation. Demo payments are excluded from revenue reports.

## 1. Start the local app with the simulator enabled

Open Terminal in the repository root:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
export LOCAL_PAYMENTS_ENABLED=1
php -S 127.0.0.1:8080 backend/router.php
```

**Expected output:**

```text
PHP 8.x Development Server (http://127.0.0.1:8080) started
```

The `export` and PHP server must run in the same terminal. This switch only enables the local simulator; it is not a gateway key. No external payment account, API key, webhook, or payment dashboard is needed.

Open [http://127.0.0.1:8080/frontend/login.html](http://127.0.0.1:8080/frontend/login.html). Sign in with a driver account that has a registered vehicle. The credentials in the repository's Quickstart section can be used on the supplied development database.

**Expected result:** Parking Ltd. opens with the signed-in driver account. The reservation page displays **“Demo payments only · No real money is charged.”**

## 2. Create a booking and open checkout

Go to **Reserve Slot**, choose a zone with an available slot and valid dates, then press **Hold Slot & Continue to Demo Checkout**. The form uses the vehicle linked to the signed-in driver. Pick another test driver or vehicle if this vehicle already has an active booking or payment hold; an existing paid booking is not silently replaced.

**Expected result:** Checkout opens at a local URL similar to:

```text
http://127.0.0.1:8080/frontend/demo-checkout.html?session=local_...
```

The page shows the assigned zone and slot, vehicle plate, dates, booking reference, server-calculated total, and a 10-minute countdown. The card and wallet options come prefilled with clearly labeled demo details. They do not collect or transmit any card, security code, phone number, or wallet credentials.

## 3. Approve a simulated payment

Leave **Successful payment** selected under **Demo scenario**, then press **Continue to verification**. Enter the displayed simulation code:

```text
123456
```

Press **Confirm demo payment**.

**Expected result:** The checkout shows **Payment complete** and states that no money was charged. Return to the reservation page; the booking is now `ACTIVE` and `PAID`, and its QR pass is available. The demo pass can be used to demonstrate the app's paid-pass and gate-validation flow. It does not represent a real payment or a real parking entitlement.

## 4. Try decline, cancellation, and expiry

For a decline, start another booking, open **Demo scenario**, choose **Card / wallet declined**, continue to verification, and enter `123456`.

**Expected result:** Checkout says **Payment declined**. No pass is issued. The booking remains pending and keeps its slot during the rest of the 10-minute hold. The driver can try the success scenario before the deadline.

For cancellation, press **Cancel checkout** before confirming payment.

**Expected result:** Checkout says **Checkout cancelled**, issues no pass, and releases the unpaid booking's slot.

For expiry, leave checkout pending until the countdown reaches zero. You can also close the page and revisit the reservation after the deadline; expiry is processed on the next reservation/checkout request. To run the background expiry worker manually from another terminal:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
php backend/api/expire_reservation_holds.php
```

**Expected result:** Expired local holds become `EXPIRED`, have no QR token, and release their slots. Historical non-local holds are not changed by the local expiry worker.

If the reservation page shows an old non-local provider hold, it is not a local checkout. It remains unresolved for staff review; no local demo pass is issued for it.

## 5. Review the records and demo refunds

Payment records identify the provider as `LOCAL`; amounts are stored in centavos (for example, ₱60.00 is `6000`). A successful record has status `PAID`, a generated simulation payment ID, a payment timestamp, and an audit event. Declined attempts do not set `PAID` or create a pass. The simulator stores no card details. The dashboard's collected-revenue figures omit `LOCAL` records.

If an administrator cancels a demo-paid reservation, it enters the staff refund review queue. Approving that review marks a **simulated** refund complete and records an audit entry; no funds are returned because none were charged.

## 6. Run the local checks

From the repository root:

```sh
php -v
php -m | grep -Ei '^(pdo_sqlite|sqlite3)$'
php tests/test_local_reservation_payments.php
```

**Expected output:** PHP and SQLite module versions, followed by:

```text
Local simulated payment tests passed
```

The test uses an in-memory database and fake payment outcomes. It does not create a booking in your normal `parking.db`.

## Important limits

- Keep the simulator enabled only while you want local demo checkouts. Stop the PHP server with `Ctrl+C`; in a later shell, do not export `LOCAL_PAYMENTS_ENABLED=1` unless you want it enabled again.
- Use `http://127.0.0.1:8080` for the full local flow. Do not expose this simulation URL through ngrok or use it to accept actual payments.
- Historical provider records remain in the existing database for audit. The local simulator does not cancel or mark those bookings paid.
- The simulator tests the product flow and server-side reservation state. It does not verify a bank, acquiring connection, real OTP, card authentication, or payment-provider reconciliation.
