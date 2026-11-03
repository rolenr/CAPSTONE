# PayMongo sandbox reservations: complete local setup and demo

> **Historical guide:** New reservations now use Xendit Test Mode because PayMongo sign-up was unavailable. Follow [Xendit sandbox reservations](XENDIT_SANDBOX_RESERVATIONS.md) for the current configuration. This PayMongo guide remains for understanding older records only.

This guide starts with a new PayMongo account and ends with a paid test reservation, a signed webhook, and a visible QR pass. It is written for the macOS checkout at `/Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE`. Commands run in **zsh** from that repository. Terminal output, IDs, dates, and tunnel hostnames below are **examples**; compare their shape and status, not their exact values.

The application accepts **PayMongo test secret keys only** (`sk_test_...`) and creates **card-only** hosted checkouts. PayMongo's card test mode does not move real money. Do not enter a real card. A booking stays `PENDING_PAYMENT` and holds a slot for 10 minutes; only a valid `checkout_session.payment.paid` webhook makes it `ACTIVE`, records `PAID`, and issues the QR pass. The browser's return URL cannot confirm it. The 10-minute checkout hold is separate from the parking dates.

## 1. Create or access a PayMongo account

1. Open [PayMongo sign-up](https://dashboard.paymongo.com/signup). If you already have an account, sign in at [the dashboard](https://dashboard.paymongo.com/) and continue to step 2.
2. Enter an email address, a strong password, accept the required terms, and submit. Enter the **six-digit email OTP** that PayMongo sends. Check spam or request another OTP if it does not arrive.
3. Follow any identity-verification prompts in your dashboard. PayMongo's documented flow may request a liveness camera check, a government ID, and matching personal details. Submit those **only to PayMongo's own site**. Business verification is a separate step for broader/live capabilities; this guide does not require live keys or a live payment method. PayMongo's account pages differ on precisely when test keys appear, so follow the prompts shown on your account.

**Expected result:** You can sign in to the PayMongo dashboard and switch to **Test Mode**. Under **Developers / Developer Tools → API Keys**, a test secret key beginning `sk_test_` is available. If keys are not yet shown, complete the account/identity prompts in the dashboard; do not substitute a live key.

## 2. Get the test API key

In the PayMongo dashboard, turn on **Test Mode**, open **Developers / Developer Tools → API Keys**, reveal or copy the **test secret key**. The `pk_test_...` public key is not used by this server-created checkout integration. Save the secret in a password manager or paste it directly into your local terminal later. Do not put it in Git, frontend JavaScript, a screenshot, or this document.

**Expected result:** The copied key starts with `sk_test_`. A key beginning `sk_live_` belongs to live mode and is deliberately rejected by this application. The webhook signing secret obtained in step 5 is **different** from this API key.

## 3. Check this Mac and the repository

Open **Terminal A**:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
pwd
php -v
php -m | rg -i '^(curl|pdo_sqlite|sqlite3)$'
command -v cloudflared
command -v sqlite3
```

**Expected output (version and paths vary):**

```text
/Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
PHP 8.5.7 (cli) ...
curl
pdo_sqlite
sqlite3
/opt/homebrew/bin/cloudflared
/opt/local/bin/sqlite3
```

If `rg` is unavailable, run `php -m` and look for those three modules. This checkout needs PHP with cURL and SQLite. `cloudflared` is already installed on the project Mac; on another Mac, follow [Cloudflare's installation instructions](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/) if `command -v cloudflared` finds nothing. The `(.venv-cv)` Python prompt does not configure PHP and is harmless here.

Run the local payment tests before configuring PayMongo:

```sh
php tests/test_reservation_payments.php
```

**Expected final line:** `Reservation payment tests passed`. The test also prints a simulated `Hold 1 remains held: payment processing`; that line is expected, not a failure. If the test fails, fix the local application before testing an external checkout.

## 4. Start the local PHP server and create an HTTPS tunnel

In **Terminal A**, still at the repository root, start PHP:

```sh
php -S 127.0.0.1:8080 -t .
```

**Expected output (leave it running):**

```text
[Tue Nov 03 ... 2026] PHP 8.5.7 Development Server (http://127.0.0.1:8080) started
```

In **Terminal B**, start a [Cloudflare Quick Tunnel](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/):

```sh
cloudflared tunnel --url http://127.0.0.1:8080
```

**Expected output:** `cloudflared` prints a temporary public URL such as `https://violet-river-example.trycloudflare.com`. Copy **your actual** `https://...trycloudflare.com` URL; the example hostname will not work. Keep Terminal B running. A Quick Tunnel does not require a Cloudflare account, but anyone with its URL can reach the local app while it runs. Use it for this demo only.

The three addresses have different purposes:

| Value | Example | Use |
| --- | --- | --- |
| Local PHP listener | `http://127.0.0.1:8080` | Tunnel target; not the PayMongo webhook URL |
| Tunnel origin / `APP_BASE_URL` | `https://violet-river-example.trycloudflare.com` | Checkout return origin; no `/frontend` suffix |
| Webhook URL | `https://violet-river-example.trycloudflare.com/backend/api/paymongo_webhook.php` | PayMongo's POST destination |

**Expected check:** The tunnel URL plus `/frontend/login.html` opens the application's sign-in page. If Cloudflare returns a gateway error, check that Terminal A is still running on port 8080. Do **not** add Cloudflare email authentication to this demo tunnel: PayMongo's webhook cannot complete an email challenge.

## 5. Create the signed webhook in PayMongo Test Mode

In the PayMongo dashboard, make sure the mode toggle says **Test Mode**. Open **Developers / Developer Tools → Webhooks → Add endpoint** (some dashboard versions label this **Create Webhook**). Enter your **actual** tunnel URL followed by `/backend/api/paymongo_webhook.php`, subscribe to **`checkout_session.payment.paid`**, then save/create the endpoint. Copy the endpoint's **webhook signing secret** displayed by PayMongo. The endpoint must be public HTTPS with a hostname; PayMongo does not accept an IP-address webhook URL.

**Expected result:** The webhook appears in the Test Mode webhook list with the exact tunnel URL and `checkout_session.payment.paid` event. Its signing secret is available to copy. Keep it private. This secret is used to verify the raw webhook body and is not your `sk_test_` API key.

The dashboard's **Send Test Event** feature is optional. Its default sample IDs do not refer to one of this application's real checkout sessions, so it is **not** a substitute for the card checkout in step 9. This application may reject or fail to match that sample even when delivery/signature work. The real sandbox checkout is the end-to-end test.

## 6. Export the three settings and restart PHP

Stop **Terminal A's** initial PHP server with `Ctrl+C`. In **that same terminal**, replace all three example values below with the full values from your own PayMongo dashboard and tunnel. Do not type the literal `...` or example hostname:

```sh
export PAYMONGO_SECRET_KEY='sk_test_PASTE_YOUR_FULL_TEST_SECRET_HERE'
export PAYMONGO_WEBHOOK_SECRET='PASTE_YOUR_FULL_TEST_WEBHOOK_SECRET_HERE'
export APP_BASE_URL='https://PASTE-YOUR-ACTUAL-HOST.trycloudflare.com'
```

The webhook signing secret may have a provider-specific prefix; copy its **entire** value. These exports apply only to this terminal and processes launched from it. They do not retroactively update a PHP server that was already running.

Check configuration **without printing secrets**:

```sh
php -r '$key=getenv("PAYMONGO_SECRET_KEY") ?: ""; $hook=getenv("PAYMONGO_WEBHOOK_SECRET") ?: ""; $url=getenv("APP_BASE_URL") ?: ""; echo "test API key: ".(str_starts_with($key,"sk_test_") && !str_contains($key,"PASTE") && !str_contains($key,"...") ? "set" : "MISSING/placeholder").PHP_EOL; echo "webhook secret: ".($hook !== "" && !str_contains($hook,"PASTE") && !str_contains($hook,"...") ? "set" : "MISSING/placeholder").PHP_EOL; echo "base URL: ".(filter_var($url,FILTER_VALIDATE_URL) && !str_contains($url,"PASTE") ? "valid" : "MISSING/placeholder").PHP_EOL;'
```

**Expected output:**

```text
test API key: set
webhook secret: set
base URL: valid
```

This check catches the guide's placeholders and missing variables; it does **not** authenticate against PayMongo. The actual checkout in step 9 proves that the API key works.

Restart the server in **Terminal A**:

```sh
php -S 127.0.0.1:8080 -t .
```

**Expected output:** `PHP ... Development Server (http://127.0.0.1:8080) started`. Leave both PHP and the tunnel running. If you must change an exported value later, stop/restart PHP again.

## 7. Prove the public URL reaches this server

Open **Terminal C**, set only the non-secret tunnel origin for these checks, and replace the hostname:

```sh
export APP_BASE_URL='https://PASTE-YOUR-ACTUAL-HOST.trycloudflare.com'
curl -sS -o /dev/null -w 'reservation page: HTTP %{http_code}\n' "$APP_BASE_URL/frontend/reserve.html"
curl -sS -o /dev/null -w 'webhook GET: HTTP %{http_code}\n' "$APP_BASE_URL/backend/api/paymongo_webhook.php"
curl -sS -o /dev/null -w 'unsigned webhook POST: HTTP %{http_code}\n' -X POST "$APP_BASE_URL/backend/api/paymongo_webhook.php"
```

**Expected output:**

```text
reservation page: HTTP 200
webhook GET: HTTP 405
unsigned webhook POST: HTTP 401
```

`405` means the webhook correctly requires POST. `401` means an unsigned POST was rejected; a genuine PayMongo request has a `Paymongo-Signature` header and must pass raw-body signature verification. A `404`, `502`, or Cloudflare error points to a wrong hostname, stopped process, or wrong path. Do not disable signature verification to make a test pass.

## 8. Run the 10-minute hold-expiry worker

The PayMongo checkout session does **not** expire automatically when the local 10-minute clock ends. The worker must first ask PayMongo to expire the session, then release the slot. If PayMongo says a payment is processing or the request fails, the slot remains held for reconciliation.

For a local demo, open **Terminal D** at the repository root. Export the **same full test API key** in this terminal (shell variables from Terminal A are not shared), then run the worker once per minute:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
export PAYMONGO_SECRET_KEY='sk_test_PASTE_YOUR_FULL_TEST_SECRET_HERE'
while true; do php backend/api/expire_reservation_holds.php; sleep 60; done
```

**Expected output:** Nothing while no holds are due. After an abandoned checkout exceeds 10 minutes and PayMongo confirms expiry, a line such as `Expired hold #42` appears. If payment is still processing, the worker may log that the hold remains held; do not manually free that slot. Stop the loop with `Ctrl+C` after the demo.

For an unattended installation, schedule `php backend/api/expire_reservation_holds.php` once a minute with `cron` or a service manager. Use absolute paths and supply the same test key in that job's environment; cron will not inherit Terminal A's exports. Keep keys out of committed files and avoid putting them directly in a shared crontab. A manually launched loop is simpler and observable for this sandbox demo.

## 9. Make a successful sandbox reservation

1. Open **`https://YOUR-ACTUAL-HOST.trycloudflare.com/frontend/login.html` in your browser**, replacing the hostname with your tunnel's real value. Browsers do not expand the shell variable `$APP_BASE_URL`. Sign in as a **driver**; register one at `/frontend/register.html` if needed. A driver account needs a valid linked plate (three letters followed by three or four digits). The login page also has demo quick-fill buttons if those demo accounts still exist in your database.
2. Open `/frontend/reserve.html` on that **same tunnel origin**. Using `http://127.0.0.1:8080` for the app while `APP_BASE_URL` points to the tunnel can make the returned browser session look logged out because the hosts differ.
3. Choose an available zone **A–D**, a start date no earlier than today in Manila, and an end date within three inclusive days. Zone E requires VIP status. For a first test, use a one-day booking: the displayed fee should be **₱60.00**. Two inclusive days are **₱220.00**; three are **₱280.00**. The server recalculates this amount in centavos.
4. Select **Hold Slot & Continue to Sandbox Checkout**. An admin can also test online payment, but must leave **Explicit admin payment exemption** **unchecked**. Checking it creates an audited booking without checkout and does not test PayMongo.

**Expected result before payment:** The browser redirects to `https://checkout.paymongo.com/...`. If you return to the reservation page or close/reopen the tab, it shows **Awaiting payment**, the assigned zone/slot, a Manila-time hold deadline about 10 minutes ahead, and **Return to sandbox checkout**. There is **no QR pass** yet. A representative API response at this stage is:

```json
{
  "success": true,
  "status": "PENDING_PAYMENT",
  "payment_state": "PENDING",
  "fee": 60,
  "hold_deadline": "2026-11-03 07:20:00",
  "checkout_url": "https://checkout.paymongo.com/EXAMPLE",
  "qr_token": null
}
```

The JSON deadline is UTC; the page formats it in Philippine time. The real response also contains dynamic reservation, zone, slot, and date fields.

5. On PayMongo's hosted checkout, use the official **successful Visa test card** `4343 4343 4343 4345`, any **future** expiry, and any three-digit CVC. Use a test name/email if the form asks. Complete the checkout.

**Expected result after payment:** PayMongo displays a successful test payment and returns you to the reservation page. The page may briefly say **Awaiting payment** if the redirect beats the webhook. Click **Refresh payment status** after a few seconds. It should then show **ACTIVE RESERVATION PASS**, **CONFIRMED**, the assigned slot, and a QR code. The PayMongo Test Mode dashboard should show the sandbox payment. The server's webhook endpoint returns HTTP 200 with `{"received":true}` for a valid, matched event. The reservation becomes `ACTIVE` / `PAID`; the payment record becomes `PAID`. Refreshing the browser alone never creates a paid pass.

## 10. Verify the database and reconcile with PayMongo

In **Terminal C**, from the repository root, inspect recent records without exposing keys:

```sh
sqlite3 -header -column backend/database/parking.db "SELECT r.reservation_id, r.status AS booking, r.payment_state, p.status AS payment, p.amount_centavos, p.refund_status FROM reservations r LEFT JOIN reservation_payments p ON p.reservation_id=r.reservation_id ORDER BY r.reservation_id DESC LIMIT 5;"
```

**Expected relevant row after the ₱60.00 test payment (IDs vary):**

```text
reservation_id  booking  payment_state  payment  amount_centavos  refund_status
--------------  -------  -------------  -------  ---------------  -------------
42              ACTIVE   PAID           PAID     6000
```

For a pending checkout, expect `PENDING_PAYMENT`, `PENDING`, `PENDING`, `6000`, and no pass. `6000` means 6,000 centavos = ₱60.00. Other pre-existing rows may also appear.

In **Terminal C**, export the full `sk_test_` key there as well, then run reconciliation:

```sh
export PAYMONGO_SECRET_KEY='sk_test_PASTE_YOUR_FULL_TEST_SECRET_HERE'
php backend/api/reconcile_reservation_payments.php
```

**Expected clean output:**

```text
Checked 1 sandbox checkouts; 0 issue(s).
```

The count includes **all** local payment records, so it may be 0 or greater than 1. A mismatch or API error is printed with a reservation ID and the command exits nonzero. Compare that ID and payment/refund in the PayMongo **Test Mode** dashboard before changing a local record. Reconciliation can also advance a requested refund to `SUCCEEDED` or `STAFF_ATTENTION` when PayMongo reports its outcome.

## 11. Check failure and recovery paths

| Test | Action | Expected output or UI |
| --- | --- | --- |
| Declined card | Start a **new** hold and use PayMongo test card `4111 1111 1111 1111` | Checkout reports a generic decline; reservation remains `PENDING_PAYMENT`, no QR pass. Retry the same checkout while the hold is valid. |
| Abandoned browser | Start a hold, close checkout, wait past the deadline while Terminal D runs | The worker eventually prints `Expired hold #N` if PayMongo expiry succeeds; booking becomes `EXPIRED`, no pass, slot becomes available. |
| Return before webhook | Finish checkout, immediately inspect reservation page | It may temporarily show **Awaiting payment**. **Refresh payment status** after delivery; pass appears only after the signed webhook. |
| Forged request | Run the unsigned POST in step 7 | HTTP `401`; no booking/payment change. |
| Duplicate event | PayMongo retries a valid paid event | Still one paid booking and one QR pass; event deduplication prevents a second activation. |
| Existing booking for plate | Try a second booking for the same linked vehicle | Error such as **You already have a booking or payment hold.** No replacement of a paid booking. |
| Payment at deadline | Payment is still processing as worker checks expiry | Slot remains held until PayMongo's outcome is known. No premature pass. |
| Late success | PayMongo confirms after the hold deadline | Booking stays `EXPIRED` / `LATE_PAID`, no pass; a full refund is requested. Failed/uncertain refund needs staff attention. |
| Admin exemption | Sign in as admin and deliberately check the exemption box | `ACTIVE` / `ADMIN_EXEMPT`, pass issued, audit entry; no PayMongo checkout or collected-payment record. |

The local test suite exercises these state rules, signature rejection, duplicate delivery, concurrent slot holds, and gate rejection of unpaid passes. It does not replace the real sandbox checkout/webhook test above.

For a **staff-reviewed cancellation refund**, sign in as an administrator, cancel an `ACTIVE` paid reservation in the admin page, then open **Reservation Refund Review** and select **Approve refund**. **Expected result:** access is revoked, the queue initially shows `STAFF_REVIEW`, then the refund moves to `REQUESTED` or `SUCCEEDED` if PayMongo accepts it. `REQUESTED` is not proof of settlement. Run reconciliation and inspect PayMongo's Test Mode refund record. If it shows `STAFF_ATTENTION`, investigate in PayMongo before retrying; do not create duplicate refunds blindly.

## 12. Troubleshooting by observed output

| What you see | Likely cause | Check or fix |
| --- | --- | --- |
| `Sandbox checkout is not configured. Contact staff.` | PHP process lacks a full `sk_test_` key or valid `APP_BASE_URL` | Repeat step 6 **in Terminal A** and restart PHP. Literal placeholders do not count. |
| `Checkout could not be created. Please try again.` | PayMongo rejected the request, credentials/account unavailable, or a network error | Read Terminal A's `Checkout creation failed` line. Check Test Mode, key, account access, and network. An uncertain remote outcome may leave a hold for staff review. |
| PayMongo dashboard has no webhook deliveries | Wrong Test/Live mode, wrong event, or wrong endpoint URL | Recheck the mode, subscription, and exact tunnel hostname/path in step 5. |
| Webhook delivery returns `401` | Wrong webhook signing secret, missing signature, or stale/altered request | Export the endpoint's **test** webhook secret in Terminal A and restart PHP. Do not use the API key here. |
| Webhook delivery returns `500` | Signature passed but event was malformed, unmatched, or local processing failed | Read Terminal A's `PayMongo webhook: ...` error. Compare session ID, payment amount, and local record. A generic dashboard sample event may not match any local checkout. |
| Checkout says paid but page still says **Awaiting payment** | Webhook has not completed, cannot reach the tunnel, or has a processing error | Click **Refresh payment status**, inspect delivery attempts in PayMongo, then check Terminal A and reconciliation. Do not issue a pass manually. |
| Page returns logged out after checkout | App opened on local HTTP but return URL uses HTTPS tunnel origin | Sign in and reserve through the **same tunnel origin** from the start. |
| Tunnel URL changed | Quick Tunnel restarted and generated a new hostname | Update `APP_BASE_URL`, PayMongo Test Mode webhook URL, and browser URL; restart PHP. Old checkout return URLs still point to the old hostname. |
| `PHP Error: Function curl_close() is deprecated ...` | Older application code running under PHP 8.5 | Use the updated `backend/api/reservation_payments.php`, stop/restart PHP, and rerun the test. |
| Worker shows no output | No hold is due, or worker is running in a different environment | This is normal before a deadline. Check its `PAYMONGO_SECRET_KEY` and the recent database rows. |
| `MISMATCH` or `ERROR` from reconciliation | Local and PayMongo states differ, or PayMongo API is unreachable | Use the reservation ID to compare PayMongo Test Mode checkout/payment/refund records. Resolve before a live rollout. |

## After the demo

Stop Terminal D (`Ctrl+C`), Terminal B (`Ctrl+C`), and Terminal A (`Ctrl+C`). Cloudflare Quick Tunnel URLs are temporary. If you start another demo, repeat the tunnel, webhook URL, `APP_BASE_URL`, and PHP startup steps with the new hostname. Keep the API and webhook secrets private; rotate either in PayMongo if it was exposed. This application is intentionally sandbox-only. Do not use live keys without a separate production/security review.

Official references: [PayMongo account creation](https://docs.paymongo.com/docs/get-started-create-your-account), [test mode and cards](https://docs.paymongo.com/docs/payment-acceptance-testing), [create a webhook](https://docs.paymongo.com/docs/creating-a-webhook-endpoint), [webhook signing](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [hosted checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start), [checkout session expiry](https://docs.paymongo.com/docs/payment-channels-key-concepts), [refund API](https://docs.paymongo.com/docs/payment-acceptance-refunds), and [Cloudflare Quick Tunnels](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).
