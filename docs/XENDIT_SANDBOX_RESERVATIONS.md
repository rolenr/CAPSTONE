# Xendit sandbox reservations: setup and expected results

The reservation app now creates **Xendit Payment Sessions in Test Mode**. Testing uses virtual funds and does not incur payment charges. Live payment processing has transaction fees and is **not enabled by this code**: it rejects a key unless it starts with `xnd_development_`. See [Xendit's account guide](https://docs.xendit.co/docs/create-account), [API setup](https://docs.xendit.co/apidocs), and [current Philippine pricing](https://www.xendit.co/en-ph/pricing/). The local booking amount is stored in centavos; the Xendit API receives Philippine pesos (a ₱60 booking is sent as `60`).

A new booking holds one slot for **10 minutes** as `PENDING_PAYMENT`. The Xendit session is set to expire about one minute later to allow for API creation delay; the worker cancels it at the local deadline before releasing the slot. A completed payment is verified by retrieving both the Payment Session and Payment through Xendit's API. Only a successful, matching payment captured before the deadline activates the reservation and issues a QR pass. The return page alone never confirms payment.

## 1. Create a Xendit Test Mode account

1. Open [Xendit's sign-up page](https://dashboard.xendit.co/register/1) and create an account with your email and password. Verify your email and answer the initial business-profile questions shown on the dashboard. If you already have an account, sign in instead.
2. Switch the dashboard to **Test Mode**. Xendit says test mode is available after sign-up and uses virtual funds; business verification is needed for **live** payments, not this test.

**Expected result:** The dashboard indicates **Test Mode**. You can explore test transactions. No real card, bank, or e-wallet money is moved by a test payment. If the dashboard asks for additional steps before exposing an API feature, follow its on-screen account instructions.

## 2. Create a development secret API key and find the callback token

In **Test Mode**, open **Settings → Developers → API Keys** (some dashboard layouts say **Settings → API Keys**). Give the key a name such as `ParkingLtd`. The permission choices are **radio buttons per feature row**: each row can have **one level** (`None`, `Read`, or `Write`), and you can configure multiple rows. Set **Money-in products** (Credit card, Direct Debit, E-wallets, Retail Outlets, PayLater, Invoices) to **Write**. This is the permission used by the reservation checkout and refund requests; `Write` includes the access needed to retrieve their records. Leave **Money-out products** and **xenPlatform** rows at **None** because this app does not make disbursements or manage subaccounts. If the dialog shows a separate **Payment Sessions** or **Refunds** row, set that row to **Write** too. Then select **Generate key** and copy the entire development secret key securely; it should begin `xnd_development_`. Do not use a public key or a `xnd_production_` live key. Xendit explains [secret keys and permissions here](https://docs.xendit.co/docs/api-keys).

Open **Settings → Webhooks** in Test Mode and copy the **webhook verification/callback token**. You will configure the callback URL after starting an HTTPS tunnel. Xendit sends the token in its `x-callback-token` header; it is not an HMAC signature and it is not the API key. The server checks that token and independently retrieves the session and payment from Xendit before issuing a pass. See [Xendit's webhook guidance](https://docs.xendit.co/docs/handling-webhooks).

**Expected result:** You have two separate private values: a development secret API key and a webhook callback token. Keep them out of Git, screenshots, and frontend code. A newly created Xendit account may start without a secret key; generate one explicitly.

## 3. Check the local project

Open **Terminal A**:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
php -v
php -m | grep -Ei '^(curl|pdo_sqlite|sqlite3)$'
command -v ngrok
php tests/test_xendit_reservation_payments.php
```

**Expected output:** A PHP version, separate `curl`, `pdo_sqlite`, and `sqlite3` lines, an ngrok path such as `/opt/homebrew/bin/ngrok`, and finally `Xendit reservation payment tests passed`. The two `Xendit hold 1 remains held` lines are expected safety-test logs. If the ngrok path is blank, install it in step 4. If a PHP module is absent, install or enable that module before continuing.

## 4. Install ngrok, start PHP, then obtain a public HTTPS URL

Create a free [ngrok account](https://dashboard.ngrok.com/signup), verify your email, then open [Your Authtoken](https://dashboard.ngrok.com/get-started/your-authtoken) while signed in. The token is a private value shown in your own dashboard; this guide cannot supply it. On macOS, install the agent:

```sh
brew install ngrok
ngrok version
```

**Expected output:** `ngrok version ...`. If Homebrew is unavailable, use [ngrok's official macOS download](https://ngrok.com/download/mac-os). The [free plan](https://ngrok.com/docs/pricing-limits) includes one assigned HTTPS development domain.

On the **Your Authtoken** page, copy the dashboard's complete `ngrok config add-authtoken ...` command and run it in your terminal. **Do not type** `YOUR_ACTUAL_NGROK_AUTHTOKEN` or another placeholder. If you already saved a placeholder, running the dashboard's command again replaces it.

**Expected output:** `Authtoken saved to configuration file: .../ngrok.yml`. This message only confirms the value was saved; starting ngrok below confirms whether it is valid. Never put the authtoken in this repository, chat, or a screenshot.

In **Terminal A**, from the repository root:

```sh
php -S 127.0.0.1:8080 -t .
```

**Expected output:** `PHP ... Development Server (http://127.0.0.1:8080) started`. Leave it running.

In **Terminal B**:

```sh
ngrok http 8080
```

**Expected output:** An ngrok status screen showing `Session Status online` and a line such as `Forwarding https://your-assigned-name.ngrok-free.app -> http://localhost:8080`. Copy **your actual HTTPS URL**, including `https://` and without a path. Call that value your **ngrok origin** in the remaining steps. Keep this terminal running. If you see `ERR_NGROK_105`, your saved authtoken is invalid; rerun the exact command from your [ngrok dashboard](https://dashboard.ngrok.com/get-started/your-authtoken). [ngrok Agent CLI quickstart](https://ngrok.com/docs/gateway/endpoints/agent-cli-quickstart).

Open **your ngrok origin** plus `/frontend/login.html` in a browser. For example, if ngrok displays `https://parking-demo.ngrok-free.app`, open `https://parking-demo.ngrok-free.app/frontend/login.html`. **Expected result:** the Parking Ltd. login page loads. Use this same origin for login and reservation, so the browser session survives the return from Xendit. ngrok's free plan may show a browser interstitial on first visit; continue to the app before testing login.

## 5. Start PHP with your Xendit settings

Stop Terminal A's initial PHP server with `Ctrl+C`. In that same terminal, export the two values from your **Xendit Test Mode** dashboard and the exact ngrok origin from Terminal B. Replace every value below before running it:

```sh
export XENDIT_SECRET_KEY='YOUR_FULL_XENDIT_DEVELOPMENT_SECRET_KEY'
export XENDIT_CALLBACK_TOKEN='YOUR_XENDIT_WEBHOOK_CALLBACK_TOKEN'
export APP_BASE_URL='https://your-assigned-name.ngrok-free.app'
php -S 127.0.0.1:8080 -t .
```

**Expected output:** `PHP ... Development Server (http://127.0.0.1:8080) started`. The secret key must actually start `xnd_development_`; the callback token is a **different** value from Xendit Settings → Webhooks. `APP_BASE_URL` is only the origin: no `/frontend`, no webhook path, and no trailing slash. Shell exports affect PHP only when PHP is started **after** them. Leave both PHP and ngrok running.

## 6. Register and test the Xendit Payment Session webhook

In Xendit's **Test Mode** dashboard, open **Settings → Webhooks** and set **Payment Session – Completed** to:

```text
https://your-assigned-name.ngrok-free.app/backend/api/xendit_webhook.php
```

Replace the example hostname with the **exact ngrok hostname from Terminal B**. Optionally point **Payment Session – Expired** to the same URL. The endpoint acknowledges that event; the expiry worker still checks Xendit before freeing a slot. Select **Test and save**. Do not configure an Invoice/Payment Link legacy webhook for this Payment Sessions integration. [Xendit's webhook checklist](https://docs.xendit.co/docs/payments-integration-setup) lists the Payment Session topics.

**Expected result:** The Test Mode webhook settings show the exact HTTPS ngrok URL under **Payment Session – Completed**. The dashboard's sample test may use a fabricated session ID; the endpoint acknowledges a definitive "remote session not found" response without creating a booking or pass. The definitive payment test is the real sandbox reservation in step 8. The handler accepts either `data.id` or `data.payment_session_id` and then retrieves and verifies the real remote session before issuing any pass.

If Xendit says the URL "may contain private or local IPs," verify that ngrok is online and that **your exact ngrok hostname** opens `/frontend/login.html` from a different network or device. Test the endpoint as shown below. Do not enter `127.0.0.1`, `localhost`, or an IP address in Xendit. Xendit documents ngrok as a testing option in its [webhook API reference](https://developers.xendit.co/api-reference/?javascript=).

Open **Terminal C** and check public reachability (replace the hostname with your actual ngrok hostname):

```sh
export APP_BASE_URL='https://your-assigned-name.ngrok-free.app'
curl -sS -o /dev/null -w 'reservation page: HTTP %{http_code}\n' "$APP_BASE_URL/frontend/reserve.html"
curl -sS -o /dev/null -w 'webhook GET: HTTP %{http_code}\n' "$APP_BASE_URL/backend/api/xendit_webhook.php"
curl -sS -o /dev/null -w 'unsigned webhook POST: HTTP %{http_code}\n' -X POST "$APP_BASE_URL/backend/api/xendit_webhook.php"
```

**Expected output:** `reservation page: HTTP 200`, `webhook GET: HTTP 405`, and `unsigned webhook POST: HTTP 401`. The 401 is correct: an unauthenticated request must never issue a pass. If `curl` gets an ngrok interstitial instead of your PHP response, inspect ngrok's request log and try the endpoint from a browser; Xendit must be able to POST to PHP without an interactive challenge.

## 7. Run the hold-expiry worker

In **Terminal D**, export the same development key again; terminals do not share exports:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
export XENDIT_SECRET_KEY='xnd_development_PASTE_YOUR_FULL_TEST_KEY_HERE'
while true; do php backend/api/expire_reservation_holds.php; sleep 60; done
```

**Expected output:** Usually nothing until a hold passes its deadline. For an abandoned test checkout, expect `Expired hold #N` once Xendit confirms cancellation or expiry. If a payment is processing or Xendit cannot be reached, the worker logs that the hold remains held; it must **not** release the slot. Stop the loop with `Ctrl+C`. For unattended operation, schedule this script once a minute and give the job its own securely supplied `XENDIT_SECRET_KEY`.

**If the page still says "The hold deadline has passed":** In Terminal D, verify that the real development key is exported in **that terminal** (do not print the key), then run one pass manually:

```sh
test -n "$XENDIT_SECRET_KEY" && echo 'Xendit key is set' || echo 'Xendit key is missing'
php backend/api/expire_reservation_holds.php
```

**Expected for a safely ended checkout:** `Expired hold #N`. Refresh the reservation page; it should return to the booking form, where you can create a new checkout. If the worker prints `Xendit hold N remains held: ...`, note the exact error and inspect the session in Xendit Test Mode. An `EXPIRY_RETRY` state means the worker ran but **could not verify that Xendit canceled or expired the checkout**. Do not edit the database to free that slot. After fixing the key, connection, or Xendit processing state, rerun the worker. The local expiry time alone must not release an unresolved payment.

## 8. Make a test reservation

1. Sign in at the **HTTPS tunnel hostname**, preferably as a driver with a registered plate. The login page's demo driver works only if it still exists in your database. A new driver can register at `/frontend/register.html`.
2. Open `/frontend/reserve.html` on the same hostname. Choose an available Zone A–D and a valid one- to three-day stay. Zone E requires VIP access. A one-day stay is **₱60.00**; two days are **₱220.00**; three days are **₱280.00**.
3. Select **Hold Slot & Continue to Sandbox Checkout**. If using the administrator login, leave **Explicit admin payment exemption** unchecked to test Xendit.

**Expected before payment:** A hosted URL on `checkout-staging.xendit.co` or `dev.xen.to` opens. The local booking is `PENDING_PAYMENT`; there is a 10-minute deadline, a way back to checkout, and **no QR pass**. Xendit receives the amount in pesos (`60` for one day) while the local payment record stores `6000` centavos.

For the first successful demo, use a **Philippines-specific test card and 3DS scenario confirmed by Xendit Support for your account**. Xendit's [general test-card list](https://docs.xendit.co/docs/cards-simulate-card-scenarios) includes challenge cards such as `4000000000002503` (Visa) and `5200000000002151` (Mastercard), but Xendit says the working numbers vary by country and connection. Use a future expiry and test CVV; complete the simulated 3DS challenge/OTP and choose **AUTHENTICATED** if that option appears. The **Simulate scenario** control beside Card Details may also offer a successful test flow; follow its on-screen authentication steps. Do not assume a generic frictionless card will satisfy a Philippine account's 3DS requirement. Never enter a real card for this Test Mode demo.

**If every test card declines:** Do **not** try a real card. Confirm the checkout hostname begins `checkout-staging.xendit.co` (Test Mode), use the checkout's **Simulate scenario** control if it offers success, and inspect the failed card transaction in Xendit's **Test Mode → Transactions** for its detailed failure code. Xendit notes that some test payment flows are restricted by partners, region, or account setup; if the detailed reason does not identify a fix, contact Xendit Customer Success with the test payment/session ID and failure code, **never** your secret key or card details. See [Xendit's pre-integration checklist](https://docs.xendit.co/docs/payments-integration-setup). Real card payments require a verified account and Live Mode, which this application intentionally rejects.

If the transaction detail says **`AUTHENTICATION_FAILED`**, the card attempt failed during 3-D Secure authentication; it does not establish that the reservation code or webhook failed. For a challenge test card, complete the simulator/OTP step and select **AUTHENTICATED** if Xendit's simulator presents that choice. If a frictionless test card also gives this code, record the Test Mode payment request ID, partner, and any available 3DS status/reason from the transaction detail and ask Xendit support which cards work for this Philippine account. [Xendit's authentication details](https://docs.xendit.co/docs/cards-card-declines-and-error-codes) explain that several distinct 3DS failures map to this single code. An expired local hold needs a **new** reservation and checkout after the expiry worker has safely released it.

Suggested Xendit Support request: "Our Philippine **Test Mode** account uses the **Payment Sessions hosted checkout** (`PAYMENT_LINK`, `CARDS`, PHP). Test attempts fail with `AUTHENTICATION_FAILED`, including a challenge attempt. Please confirm whether Cards and the 3DS test flow are enabled for this account/connection, provide a PH-compatible successful test-card/OTP scenario, and inspect payment request ID `<ID>` and any 3DS status/reason." Attach only a masked test-card number and the request ID; never send your API key, full card number, CVV, or OTP.

**Expected failed-card result:** Xendit displays a decline, the reservation remains `PENDING_PAYMENT`, and no pass is issued. You can retry on the same hosted checkout **before its deadline**. After the deadline, let the expiry worker check and release the hold, then create a new reservation. The Xendit dashboard's card transaction details may show a more specific decline reason than the generic checkout modal; [Xendit's decline guide](https://docs.xendit.co/docs/cards-card-declines-and-error-codes) explains where to find it.

**Expected after payment:** Xendit returns to the reservation page. If the redirect arrives before the callback, the page may briefly show **Awaiting payment**; click **Refresh payment status**. A verified, timely Xendit payment changes the booking to `ACTIVE` / `PAID`, shows **ACTIVE RESERVATION PASS**, and issues a QR code. Closing the browser before returning does not prevent the callback from confirming a valid payment.

## 9. Verify local records and reconcile

In Terminal C, at the repository root:

```sh
sqlite3 -header -column backend/database/parking.db "SELECT r.reservation_id,p.provider,r.status AS booking,r.payment_state,p.status AS payment,p.amount_centavos,p.refund_status FROM reservations r LEFT JOIN reservation_payments p ON p.reservation_id=r.reservation_id ORDER BY r.reservation_id DESC LIMIT 5;"
```

**Expected paid row (ID varies):** `XENDIT | ACTIVE | PAID | PAID | 6000` for a one-day reservation. Before payment, expect `XENDIT | PENDING_PAYMENT | PENDING | PENDING | 6000`. Existing PayMongo rows remain labeled `PAYMONGO`; they are not converted into paid Xendit bookings.

Export the development key in **Terminal C** and reconcile:

```sh
export XENDIT_SECRET_KEY='xnd_development_PASTE_YOUR_FULL_TEST_KEY_HERE'
php backend/api/reconcile_xendit_reservation_payments.php
```

**Expected clean final line:** `Checked N Xendit sandbox checkouts; 0 issue(s).` `N` depends on your database. Reconciliation retrieves completed sessions and payment objects, repairs missed callbacks, and checks pending refunds. Any `UNKNOWN`, `OVERDUE`, `MISMATCH`, or `ERROR` line needs investigation in the Xendit Test Mode dashboard.

## 10. Failure and refund checks

| Scenario | Expected result |
| --- | --- |
| Browser closed before payment | Hold remains pending until deadline; worker cancels/observes session end before slot release. No QR pass. |
| Failed test-card attempt | No pass; retry the same hosted session while valid. |
| Forged or missing `x-callback-token` | Webhook responds HTTP 401; no booking change. |
| Duplicate completed callback | One paid record, one pass, one payment audit. |
| Payment captured before deadline but callback delayed | Pass can still be issued after server verification of the original capture time. |
| Payment captured after deadline | `EXPIRED` / `LATE_PAID`, no pass; full refund requested. Refund failure is flagged `STAFF_ATTENTION`. |
| Payment is processing at deadline | Slot stays held until Xendit confirms completed, canceled, or expired. |
| Administrator payment exemption | `ACTIVE` / `ADMIN_EXEMPT` and audited; no Xendit checkout. |

For a staff-reviewed refund, cancel an `ACTIVE` paid booking in the admin page, open **Reservation Refund Review**, and choose **Approve refund**. **Expected result:** the booking loses access, refund status changes from `STAFF_REVIEW` to `REQUESTED` or `SUCCEEDED`, and reconciliation later confirms the final Xendit status. `REQUESTED` does not mean settled. If the API call fails or its outcome is uncertain, the record becomes `STAFF_ATTENTION`; inspect Xendit before trying again.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `ERR_NGROK_105` | The saved ngrok authtoken is invalid, often because a placeholder was copied. Sign in to the ngrok dashboard, copy its actual authtoken command, run it again, then start `ngrok http 8080`. |
| Xendit rejects the webhook URL | Confirm ngrok shows `online`, use its exact HTTPS hostname and `/backend/api/xendit_webhook.php` path, and run the three reachability checks in step 6. Check the ngrok request log for Xendit's attempt. |
| `Sandbox checkout is not configured` | Export a full `xnd_development_` key, the Xendit callback token, and HTTPS ngrok `APP_BASE_URL` in Terminal A, then restart PHP. |
| Xendit returns `INVALID_API_KEY` or a permission error | Use a Test Mode **secret** key with Money-In write access. A production key is rejected by this app. |
| `INVALID_EXPIRY_DATE` | The Xendit API must see a future expiry at least 10 minutes ahead; check machine time and retry. |
| Checkout fails to open | Check the PHP error log and whether the response URL is `checkout-staging.xendit.co` or `dev.xen.to`. |
| Test card says `Your card was declined` | On a fresh, unexpired Test Mode checkout, use a PH-compatible challenge card confirmed for your account and complete the simulated 3DS authentication. Inspect the failure reason and any 3DS status/reason in Xendit's Test Mode transaction details. |
| Callback returns 401 | Recopy the Test Mode webhook callback token, export it in Terminal A, and restart PHP. |
| Callback returns 500 | Inspect Terminal A's `Xendit webhook:` error; compare remote session/payment IDs and amounts. |
| Paid in Xendit, no pass | Check callback delivery, then run the Xendit reconciliation command. Do not manually mark the booking paid. |
| Returned browser is logged out | Open the app through the tunnel hostname from the beginning, not local HTTP. |
| ngrok restarted | Confirm its Forwarding URL. If the hostname changed, update `APP_BASE_URL` and the Xendit webhook URL, restart PHP, and sign in through that hostname. |
| `UNKNOWN` legacy PayMongo hold | One pre-existing `CREATING` PayMongo hold was present during migration. It is intentionally left for staff reconciliation; do not assume Xendit can cancel it. |

The old [PayMongo guide](PAYMONGO_SANDBOX_RESERVATIONS.md) is retained for historical setup only. The current reservation UI and new bookings use **Xendit Test Mode**.
