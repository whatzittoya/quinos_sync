# Quinos Local Sync (Windows)

Copy this folder to the Windows machine. PHP with PDO MySQL and MySQL/MariaDB must be installed. Include `vendor/` and `public/build/` when copying to avoid requiring Composer or Node on that machine; otherwise `start.cmd` installs/builds them with Composer and Node available.

Do **not** copy `.env`, `bootstrap/cache/config.php`, or `storage` runtime secrets/logs from another machine. Each machine needs its own application key and configuration.

Double-click `start.cmd`, then open http://127.0.0.1:8010. No login is required.

The first-run page asks for:
- MySQL database and password (defaults: host `127.0.0.1`, port `3306`, username `root`; expandable connection fields are editable).
- Hosted URL (default: `https://quinos.qaccounting.id`).
- API token created in the hosted **Runner tokens** menu.

Click **Finish setup**. The app connects to MySQL, creates the database only if missing and permitted, runs pending migrations without dropping existing data, and encrypts the API token locally. The dashboard opens automatically. A failed setup can be retried.

On subsequent starts, `start.cmd` opens the dashboard directly. Keep the command window open while using the local UI. Scheduled syncs run independently using `php artisan local:sync --scheduled`.

The sidebar has three pages:
- **Dashboard**: one card per client with Purchase and Sales days behind, last sync time, and master data last sync.
- **Sync**: calendar per client (pick the client in the dropdown). Pick days and sync them; the result is uploaded to the hosted app.
- **Connection**: the hosted URL and API token, with a test button.
- **Scheduler**: the daily Windows tasks, which clients they automate (checkboxes), and a filterable run log (client, date, status, scheduler or manual).

## XAMPP

Copy the folder into `xampp/htdocs/local_sync`. Start Apache and MySQL in XAMPP, then open:

```text
http://localhost/local_sync/
```

The included root `index.php` and `.htaccess` let Apache serve the app when the project folder itself is the web directory. `start.cmd` copies `public/build` into the root `build` folder for this layout. Make sure Apache's `mod_rewrite` is enabled and `AllowOverride All` is allowed for `htdocs`.

For XAMPP, use `xampp-start.cmd` after starting Apache and MySQL in the XAMPP Control Panel. It opens `http://localhost/local_sync/`; it does not start Laravel's built-in server.

Scheduling is separate and only runs for the clients checked under **Scheduler**: the **Master data** task runs `local:sync --scheduled --master-only`, while the **Reports** task runs `local:sync --scheduled --reports-only`. Configure their times independently from the dashboard.
