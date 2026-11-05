# Frontend setup

The Parking Ltd. frontend is in `frontend/`. It uses plain HTML, CSS, and JavaScript and calls the PHP API in `backend/api/endpoints.php`. Run both through the same local PHP server so sign-in cookies and API requests work together. No Node.js, npm install, or frontend build command is required.

## 1. Check the prerequisites

Open Terminal and change to the repository root:

```sh
cd /Users/ridzrgt/Documents/GitHub/CAPSTONE/CAPSTONE
php -v
php -m | grep -Ei '^(PDO|pdo_sqlite|sqlite3)$'
ls backend/database/parking.db
```

You need PHP, its `pdo_sqlite` extension, the existing `parking.db` file, and a browser. The PHP server reads and updates this database during normal use. If `php` is missing, install PHP on your computer. If `pdo_sqlite` is absent from the module list, enable or install the SQLite extension for that PHP installation.

## 2. Start the web app

From the repository root, run:

```sh
php -S 127.0.0.1:8080 backend/router.php
```

Leave this terminal open. The server handles the pages, images, JavaScript, and PHP API. To include the project's **local simulated reservation checkout**, start it with this command instead:

```sh
LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php
```

The local checkout simulates payment; it does not charge money. Use only one of the two server commands at a time. The environment variable must be present when the PHP server starts. If the server is already running without it, stop the server with `Ctrl+C` and start it again with the second command. `backend/router.php` checks account roles before serving protected HTML pages.

To install the demo driver, cashier, and Joey's tenant vendor accounts on an existing database, run once from the repository root:

```sh
php backend/database/seed_demo_accounts.php
```

The command preserves existing accounts and can be run again. It creates a demo driver with plate `DVR1001` if that account is missing and gives staff accounts no vehicle plate. It refuses to overwrite an existing account with different credentials.

## 3. Open and use the frontend

Visit **http://127.0.0.1:8080/**. The root page forwards you to `frontend/login.html`. You can also open **http://127.0.0.1:8080/frontend/login.html** directly.

Sign in with an account in your local database. The development accounts listed in the repository [README](../README.md) are available when using the supplied development database. You can also register a driver account from the login page.

The login page has **Demo Quick Fill** buttons for administrator, driver, cashier, and Joey's tenant vendor. The staff demo credentials are `cashier@parking.test` / `cashier123` and `vendor@joeys.test` / `vendor123`. These are demonstration accounts with visible credentials; do not use them for a deployed system.

Pages are limited by account role:

| Role | Main page | Example features |
| --- | --- | --- |
| Driver | `/frontend/dashboard.html` | Live slot map, reservations, wallet, violations |
| Administrator | `/frontend/admin.html` | Operations dashboard, accounts, slots, reservations, audit records |
| Cashier | `/frontend/cashier.html` | Session lookup and parking payment |
| Vendor | `/frontend/vendor.html` | Vehicle lookup and vendor validation |

In the cashier page, look up an active plate to see parking charges and each unpaid violation linked to that vehicle, including citations from earlier sessions. The amount due includes all those fines. Cash received must cover the full bill; after payment, the citations are marked paid. The exit camera and QR fallback will not close the session while unpaid violation fees remain. A plate such as `AAA3333` shows fees only when violations have actually been recorded for that vehicle in the database.

The browser and API must use the same host and port. Keep using `127.0.0.1:8080` throughout one session; switching between `localhost` and `127.0.0.1` can give you a different browser cookie context.

## 4. Check that it works

1. Open the login page and sign in.
2. Check that the page for your role loads and its data appears.
3. Open the browser developer tools if something fails. On the **Network** tab, check requests to `backend/api/endpoints.php`; on the **Console** tab, check JavaScript errors.
4. For the reservation demo, start the server with `LOCAL_PAYMENTS_ENABLED=1`, sign in as a driver with a registered vehicle, and follow [Local Simulated Payments](LOCAL_SIMULATED_PAYMENTS.md).

The live map and dashboard read the PHP/SQLite application data. The recorded-video scenarios can change that database. `computer_vision/live_vigi.py` sends confirmed exit detections to the PHP API; an eligible exit closes the session and releases its slot, so the cashier exit log and availability update. Entrance detections are still printed only. Follow [Scenario 5 and Live VIGI Camera Setup](SCENARIO5_AND_LIVE_VIGI_SETUP.md) to create the camera token and run both processes.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Browser cannot connect | Keep the `php -S` terminal running. If port 8080 is already occupied, stop the other process or choose another port and use that port in the browser. |
| PHP code appears as text, or API calls fail from a preview server | Open the site through the PHP server URL above, not a `file://` URL or an HTML-only live preview. |
| `Database connection failed` | Check that `backend/database/parking.db` exists and `pdo_sqlite` appears in `php -m`. Start the server from the repository root. |
| Sign-in redirects back to login or API returns 401 | Use the same host and port for all pages, then sign in again. Sessions expire after inactivity. |
| A page says access is denied | Sign in with an account that has that page's role. |
| Reservation checkout says it is disabled | Restart PHP with `LOCAL_PAYMENTS_ENABLED=1 php -S 127.0.0.1:8080 backend/router.php`. |
| CSS or images are missing | Open `/frontend/login.html` through the project-root server; check that the browser URL does not point to a different directory or port. |

Stop the server with `Ctrl+C` in its terminal when finished.
