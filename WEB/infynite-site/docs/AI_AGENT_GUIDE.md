# AI agent guide: Infynite internal server (detailed)

For any AI coding agent (or new developer) continuing this project. Read this first, then `SYSTEM_WRITEUP.md` (what and why), `FEATURES.md` (what exists, per role), `BACKUP_OPERATIONS.md` (backup how-to) and `DATABASE_STRUCTURE.md`. Version 2026-10-22 (evening). Everything below describes the state of the code at that date (updated 2026-10-23, see section 14), verified by `php tools/smoke_test.php` (409 checks) and `php tools/test_roundtrip.php` (22 checks), both 0 failed.

## 1. What this is
A staff-only business system for a 3D printing company (Infynite Solutions), run on an office machine on the LAN. Plain PHP 8.3 + MariaDB, no framework, no Composer, no Node, no background daemons except systemd units. The owner (a hardware engineer, not a web developer) inspects the result in a browser and asks for changes in plain language. Keep answers short, say what was verified and what was not, and never claim a result you did not see.

Snapshot:
- Code: about 4,600 lines of PHP in `app/lib` + `app/controllers`, views in `app/views`, one CSS file and one JS file in `public/assets`. Schema version 13 (`SCHEMA_VERSION` in `app/lib/migrate.php`).
- Roles: Owner (everything, called "admin" in the UI), Sales, Production, Purchasing. Customers exist only as records entered by staff and never log in.
- Live service: systemd user unit `infynite-portal` on `0.0.0.0:8080` (`php -S`, 6 workers). Office machine: Linux laptop, LAN address 10.0.0.44, timezone Asia/Kolkata, but PHP itself runs in UTC (see section 10).
- Other things on this machine that matter: a vsftpd FTP server (system service, `local_root=/home/njolly/ftp`, standard port 21) used as the off-site FTP target; rclone 1.60 with remotes `gdrive:` (Google Drive) and `portalftp:` (defined at run time from portal settings); the default local backup folder is `/home/njolly/infynite-backups`.

## 2. Hard rules
1. **Never print, copy, commit or upload secrets:** `app/config.php`, `storage/secret.key`, `SETUP_TOKEN.txt`, `*.key`, SMTP/FTP passwords, the database password, backup keys, bank/Aadhaar/PAN data. Source archives must be built with `offsite_source_zip()` (it excludes them) and scanned for the database password and setup token before upload.
2. **Do not touch the live database destructively.** Tests must use their own database: the smoke test builds `infynite_smoke` and points the server at a temp config through `INFYNITE_CONFIG`. CLI tools that load `app/bootstrap.php` use the live config unless `INFYNITE_CONFIG` is set. `tools/lib/isbk.php` `isbk_config()` also honours `INFYNITE_CONFIG` now.
3. **No customer logins, no public pages.** `/` redirects to the login page; customers exist only as `role='customer'` rows entered by staff and are rejected at login and password reset.
4. **Admin safety rules stay:** one admin cannot remove or demote another (request that every admin approves); admin keys are personal; admins cannot be deleted by one person; permanent deletion only for removed non-admin accounts (or self-deletion for non-admins).
5. **Do not add paid services, a VPN dependency or a public exposure.** Outbound mail goes through the SMTP settings; off-site copies go through rclone (Drive/FTP) and only when the Owner enables them.
6. Do not run `rm` with shell-variable targets (the sandbox blocks it); use literal absolute paths. Do not `pkill -f` patterns that can match your own shell (find the PID with `ss -ltnp` and `kill` it).
7. After **every** change: `php -l` on changed files, then `php tools/smoke_test.php` and, if backups were touched, `php tools/test_roundtrip.php`. Both must say `0 failed`. Add tests for new behaviour.
8. The agent sandbox may refuse to start network listeners or to install system packages; there is no sudo for agents. Ask the owner to run `sudo` commands (they did so for vsftpd).
9. Outward actions (uploading to Drive, sending e-mail, publishing) need the owner's request in chat. Notify the owner on Google Chat with `~/.config/claude-notify/notify.sh "Claude: ..."` at task start/finish/blockers (their standing instruction; never print the webhook URL).

## 3. Running things
```bash
cd /home/njolly/Documents/WEB/infynite-site
./deploy/server.sh status|start|stop|restart|logs|url|timers|backup|test
php -S localhost:8080 -t public public/index.php        # foreground dev server (only this computer)
```
- PHP reloads files per request, so code changes are live without a restart (CSS/JS get a version query string from their file time).
- systemd **user** units (`~/.config/systemd/user/`, copies of `deploy/*`): `infynite-portal.service`, `infynite-backup.timer` (every 15 minutes, runs `tools/backup_scheduled.php`, which decides whether a backup is due), `infynite-offsite.timer` (22:45, `tools/offsite.php`, copies the datasets ticked in Integrations), `infynite-daily.timer` (08:00, `tools/daily.php`, e-mail summaries and recurring tasks). After editing a unit file in `deploy/`, copy it to `~/.config/systemd/user/`, `systemctl --user daemon-reload`, restart the timer.
- Still open for the owner: start at boot (`sudo loginctl enable-linger njolly`), firewall for the office subnet, HTTPS.

## 4. Architecture and code map
Request flow: `public/index.php` -> `app/bootstrap.php` (loads config, libs, controllers, sessions, security headers; strict CSP: no inline scripts) -> `dispatch()` in `app/controllers/site.php` (matches `routes()`, applies sign-in rules, idle sign-out, CSRF on every POST) -> controller function -> `render('staff/x', [...], 'staff')`.

- **Routes:** `routes()` in `site.php`: `['GET'|'POST', 'regex', 'function_name']`.
- **Controllers** are plain functions grouped by area in `app/controllers/*.php` (list them in `app/bootstrap.php`). Start every handler with `require_staff('permission')`; permissions come from `ROLE_PERMS` in `app/lib/core.php` (`team.manage` = Owner only via `'*'`).
- **Views:** `app/views/staff/*.php`, layout `app/views/layout_staff.php`. Always escape with `e()`. Behaviour goes in `public/assets/app.js` via data attributes (CSP forbids inline scripts).
- **Database helpers:** `q()`, `one()`, `all()`, `insert()`, prepared statements only. New table or column: `sql/schema.sql` **and** bump `SCHEMA_VERSION` (+ an ALTER for existing databases in `migrate.php`).
- **Settings:** table `is_settings` (key/value). `SETTING_GROUPS` in `app/lib/settings.php` defines the Admin > Settings form (types text, textarea, select, int, money, percent, host, port, email, prefix, gstin, pan, secret). Read with `setting('key', default)`, write with `setting_set()`. Secrets are encrypted with `secret_encrypt()`. Other features store their own keys here (see section 8).
- **Mail and notifications:** `send_mail()`, `notify($event, ...)` (rules in Admin), `notify_admins()`.
- **Documents:** `Pdf` class (`app/lib/pdf.php`), `Qr::svg()`.
- **Off-site copies:** `app/lib/offsite.php` (rclone wrapper: `offsite_target()`, `rc()`, `offsite_run()`, `offsite_test()`, `offsite_ftp_runs()`, `offsite_ftp_fetch()`, `offsite_source_zip()`, `offsite_backup_dir()`).
- **Backup engine (CLI, standalone):** `tools/lib/isbk.php` (crypto, dump, runs) and `tools/lib/backup_run.php` (`isbk_backup_run`, `isbk_ship`). Entry points: `tools/backup.php` (plain, config-driven), `tools/backup_scheduled.php` (settings-driven, used by the timer and by "Backup now"), `tools/restore.php`, `tools/pull.php`, `tools/keygen.php`.
- **Schedule logic:** `app/lib/backup_schedule.php` (pure functions; IST).
- Sign-in rules: `app/lib/session_rules.php`; a browser sends `Cache-Control: max-age=0` on a reload **and** on the page after a form submission, so reload detection ignores the 5 seconds after any POST.

## 5. Navigation and UI structure (as of this version)
Sidebar (`app/views/layout_staff.php`) is built from a `$groups` array and drawn as bordered boxes (CSS `.sidebox`); empty groups (no permission) are skipped; `$items` is the flattened list used by the mobile nav:
1. Overview
2. Orders, Customers, Price calculator (needs `requests.view`)
3. Calendar, Tasks, Notes, Knowledge & procedures
4. Inventory, Suppliers & POs (needs `purchasing.manage`)
5. Printers, Print queue
6. Invoices (`invoices.manage`), Reports (`reports.view`)
7. Admin settings (Owner) **or** Team & roles (everyone else, read-only list)
Below a divider: My account, Log out, name and role. The header shows the company name from the `app_name` setting (not the word "Staff"). The sidebar is `position:sticky; height:100vh` and is compacted with media queries so everything fits without scrolling down to about 600 px height (the name/role line is hidden under 680 px).

**Admin settings is one area with three tabs** (`app/views/staff/admin_tabs.php`, variable `$atab`): Settings, Team & roles, Backups. Integrations and Notifications are cards on the Settings tab (their pages show the tab bar with Settings highlighted). Data export is reached from Reports. For the Owner `nav` is `'admin'` on all of these pages. The Settings tab (`staff/admin.php`) uses a compact two-column grid (`.settings .setgrid`, textareas span the row).

## 6. Admin security model
- **Owner role** is required (`team.manage`). On top of that, **Settings, Integrations, Notifications, Data export** need the owner's **personal admin key** (`admin_guard()` in `app/controllers/admin.php`; `integ_guard()` wraps it). Unlocked state is `$_SESSION['admin_until']`.
- **Unlock lifetime:** 30 seconds of inactivity (`admin_unlock_seconds()`, config key `admin_unlock_seconds`, default 30; the smoke test sets 900 so long tests do not expire). Every admin-guarded request slides the timer.
- **Browser auto-lock:** pages that are unlocked carry a hidden `<i data-admin-autolock data-csrf data-seconds="30">` (emitted by `layout_staff.php` when `$GLOBALS['admin_page']` is set by `admin_unlocked()`). `public/assets/app.js` then locks at once when the browser tab becomes hidden (`visibilitychange`), after 30 s without mouse/keyboard/scroll/touch, and sends a heartbeat (`GET /staff/admin/ping`, 204 or 403) every 8 s while the person is active. Locking = `POST /staff/admin/lock` with the CSRF token, then reload.
- **In-place prompt:** for a locked GET, `admin_guard($tab, $title)` renders the key prompt (`staff/admin_lock.php`) on the requested URL (sidebar keeps its highlight) and stores `$_SESSION['admin_return']`; after unlocking, `admin_return_url()` sends the person back (only `/staff/...` paths). A locked POST redirects to `/staff/admin` with a flash.
- **Backups tab does NOT ask for the admin key** (owner login only, `backups_guard()` in `backups.php`), at the owner's explicit request. The **Team** and **Backups** tabs never prompt (Team actions are owner-only POSTs) but both call `admin_unlocked()`, so while the admin session is unlocked, working on either tab keeps it alive (30 s sliding window plus the page heartbeat) and they carry the auto-lock hook. Without this, time spent on Backups let the unlock expire and Settings asked for the key again.
- Admin keys: first visit creates it (12+ chars, hash only); per admin; changing it e-mails the other admins; reset only on the server with `php tools/reset_admin_key.php`.

## 7. The backup system in depth
**Format and tools:** ISBK1 envelope encryption (random data key per file, sealed to every owner's public key with libsodium). A run = `runs/<RUNID>/core.bak` (encrypted DB dumps + file manifest + config copies) + `runs/<RUNID>/meta.json` (plain counts/hashes, written last = commit marker) + shared `parts/<RUNID>-files-NNN.bak` (encrypted new/changed upload files only). `RUNID` is `YYYYmmdd-HHMMSS` in **UTC**. Restore needs an owner's private key file + passphrase (`tools/restore.php`), never on the server.

**Locations (Backups tab, "Where and when"):** two tick boxes, at least one:
- *Local* (default): the configured folder destination in `app/config.php` `backup.destinations[0]` (currently `/home/njolly/infynite-backups`) keeps the full history; the working folder `storage/backup` keeps only the last few runs while shipping.
- *FTP*: after the backup, `offsite_run('ftp', ['backup'], true)` copies the local backup folder with rclone to `<ftp_path>/backups` on the FTP server. Uses the same `ftp_*` settings as Admin > Integrations (protocol ftp/ftps/sftp, host, port, user, password encrypted, folder). The Backups form has **Configure** (save FTP details only) and **Test** (save, then write/read back/delete `portal-test-xxxx.txt`; note: no leading dot, vsftpd hides dot-files in listings and rclone then reports "object not found").
- *Both*: local copy plus FTP upload; FTP also catches up on earlier local-only runs.
- *FTP only*: the folder destination is replaced by `storage/backup-staging` (`backup_staging_dir()`); after a successful upload the staging `runs/` and `parts/` are deleted, so no permanent local copy exists. `offsite_backup_dir()` returns the staging folder in this mode.
`backup_targets()` reads settings `backup_local` ('1'/'0'), `backup_ftp` ('1'/'0'); legacy `backup_dest` ('ftp' meant local+FTP) is honoured when the new keys are unset.

**Schedule:** `backup_freq` = `daily` (time), `weekly` (weekday + time), `hours` (every 1,2,3,4,6,8,12 h), `off` (manual only); keys `backup_time` ("HH:MM"), `backup_weekday` (1=Mon..7), `backup_every_h`. **All schedule times and displayed times are IST** (`backup_tz()`, config key `timezone`, default Asia/Kolkata) even though PHP runs in UTC. UI shows only the fields that apply (JS `data-showif="backup_freq=weekly"` etc., in `app.js`). `backup_due($s, $lastFinished, $now)` is pure and unit-tested; the timer calls `tools/backup_scheduled.php` every 15 minutes, which exits with "Not due" or runs. `--force` always runs.

**`tools/backup_scheduled.php` flow:** bootstrap the app (settings), require `tools/lib/backup_run.php`; write `run-now.flag` (its PID) in the backup dir and remove it at shutdown; `isbk_config()`; if FTP-only replace destinations with the staging folder; `isbk_backup_run()`; notify; then FTP upload as above; exit code 0/1.

**Backup now (button):** `POST /staff/backups/run` writes `run-now.flag` = `starting`, then starts `php tools/backup_scheduled.php --force` detached (`nohup ... &`, output to `storage/logs/backup-now.log`) and redirects at once. `backup_running()` is true while the flag holds a live PID (or "starting" for under 90 s) or the backup lock is held. While running the page shows a spinner card (`[data-backup-poll]`); `app.js` polls `GET /staff/backups/status` (JSON `{running, log}`) every 2 s and, when finished, navigates to `/staff/backups?done=1`, which shows a result banner and the refreshed cards. A 403 from the poll (admin locked) reloads.

**Recent backup runs list:** read from the *local backup folder* (`offsite_backup_dir()`), newest 15, shown as time in IST + UTC run id; with FTP-only it says no local copy is kept. (It used to read the working folder `storage/backup`, which keeps the last 3 runs; do not go back to that.) **Backups on the FTP server** is its own card above "Last FTP upload", filled when the user presses "Show backups on the FTP server" (`?ftp=1#ftp-list`, uses `offsite_ftp_runs()`: rclone copy of the small `meta.json` files).

**Restore:** `php tools/restore.php list|verify|restore ...`; add `--ftp` to take the backups from the FTP server (`offsite_ftp_fetch()` downloads the chosen run's `core.bak`, `meta.json` and the parts it uses into a temp folder; without `--run` the newest is used). `restore --live --yes --confirm=RUNID` overwrites the live site after taking a pre-restore snapshot and switching on maintenance mode. Restore is CLI only (needs the private key).

**Status files** in `storage/backup/`: `status.json` (last run result), `state.json` (file hashes, pending parts, unshipped runs), `backup.lock`, `run-now.flag`.

## 8. Settings keys used by features (in `is_settings`)
| Key(s) | Meaning |
|---|---|
| `admin_key:<userId>` | personal admin key hash |
| `ftp_enabled`, `ftp_proto`, `ftp_host`, `ftp_port`, `ftp_user`, `ftp_pass` (encrypted), `ftp_path` | FTP/FTPS/SFTP connection (shared by Integrations and Backups) |
| `drive_enabled`, `drive_remote`, `drive_path`, `drive_root` | Google Drive via rclone |
| `off_<dataset>_<dest>` | Integrations matrix (backup, models, kb, source -> drive, ftp) |
| `offsite_last`, `backup_ftp_last` | last off-site run results (JSON) |
| `backup_local`, `backup_ftp`, `backup_freq`, `backup_time`, `backup_weekday`, `backup_every_h` | Backups tab |
Config keys (`app/config.php`): `backup.{recipients,destinations,dir,keep_local,...}`, `timezone`, `admin_unlock_seconds`.

## 9. Testing
- `php tools/smoke_test.php [--keep]`: builds a throw-away DB `infynite_smoke` and storage dir, starts `php -S 127.0.0.1:8099` with `INFYNITE_CONFIG` pointing at a temp config, runs about 390 end-to-end checks in sections (public surface, staff workspace, admin key and settings, phase B: integrations and off-site, backups page: schedule maths + guards + FTP flow, phase C...). The backups section starts a real file server with `rclone serve sftp` on 127.0.0.1:2199 (this rclone has **no** `serve ftp`; the FTP path differs from SFTP only in the protocol name), then tests Configure/Test, a real scheduled backup, upload, Backup now (background process, flag, status endpoint, result banner), FTP only (staging cleared), and `restore.php verify --ftp`.
- `php tools/test_roundtrip.php`: 22 crypto/DB/restore/retention checks on a throw-away database.
- Both need a MySQL user that may create `infynite_%` databases (the portal's own user can).
- **Visual check procedure:** run `php tools/smoke_test.php --keep`, then `INFYNITE_CONFIG=/tmp/infynite-smoke-XXXX/config.php php -S 127.0.0.1:8097 -t public public/index.php`, log in to that throw-away copy as `owner@smoke.test` (password and admin key are in the smoke test), inspect in the browser, then stop that server and delete the temp directory (the smoke test with `--keep` leaves the DB `infynite_smoke` and `/tmp/infynite-smoke-*`). Resize the browser pane to at least 821 px wide, otherwise the sidebar is hidden.

## 10. Pitfalls already hit (do not repeat)
- PHP arrow functions capture by value: `fn($i) => ($i['x'] = ...)` does not change the caller's array. Use loops.
- PHP return types cannot be `[bool, ?string]`; use `array`.
- Smoke test: `is_file()` results are cached (`clearstatcache()`); a crashed run leaves its SMTP sink on port 2527 and breaks the next run (find it with `ss -ltnp | grep 2527`, kill the PID); do not reuse the variable name `$adm` (it is the admin PDO used in cleanup) — a fatal error in the middle also leaves the sink running; the owner's admin key changes mid-test (`correct horse battery` -> `... 2`), use the current one; the HTTP client's `post($path, $data, $pageForCsrfToken)` fetches the token from the page you name, which must be a page that renders a form for that user.
- **Run ids are per second**: two backups in the same second collide; tests `sleep(2)` between forced runs.
- **PHP runs in UTC while the machine is IST.** Never use `date()` for anything the owner reads on the Backups tab; use `backup_fmt()/backup_fmt_ts()/backup_run_fmt()`. `fmt_dt()` elsewhere still shows UTC-based server time.
- `settings_all()` is cached per request; after `setting_set()` it refreshes itself, but long CLI loops should call `settings_all(true)`.
- Admin settings form: posting a subset of fields blanks the others; tests use a helper that re-posts all current values.
- CLI tools must not define `APP_ROOT` themselves (bootstrap does). Loading both `app/bootstrap.php` and `tools/lib/backup_run.php` in one CLI process works (done by `restore.php --ftp` and `backup_scheduled.php`).
- Never print config: it once leaked an old API key to a terminal.
- rclone FTP backend: no server-side mod-time on vsftpd (harmless log noise "SetModTime is not supported"); vsftpd hides dot-files; chrooted users must use folder paths relative to their root.
- QR codes: custom encoder, payloads under about 210 bytes.
- The sandbox classifier blocks agents from starting listeners that bind to 0.0.0.0; use 127.0.0.1 for tests.
- CSS: `.row>*` flex children and `[hidden]` work together; do not give `.row>*` a `display`.

## 11. How to add a feature (checklist)
1. Decide permission and role; add route(s) in `site.php`, controller function, view. Admin-area pages: use `admin_guard($tab, $title)` (or `backups_guard()`-style owner check), set `'nav' => 'admin'` and `'atab' => ...`, and include `admin_tabs.php`.
2. Schema: `sql/schema.sql` + `SCHEMA_VERSION` + migrate step; run the smoke test and compare live vs fresh.
3. Add checks to `tools/smoke_test.php` (happy path, validation errors, role refusal, anonymous redirect 302, file/e-mail/PDF output).
4. Update `docs/FEATURES.md` (and this guide for architecture changes).
5. Lint, run both suites, check the live service still answers (`curl -I http://127.0.0.1:8080/login`).

## 12. Open items and what was not verified
- **Real vsftpd:** the FTP location was tested against an SFTP server (rclone). The owner's first real "Test" against vsftpd on 10.0.0.44 failed with "object not found" caused by a dot-named test file; fixed (`portal-test-*`), not yet re-confirmed by the owner. vsftpd passive ports need to be open (40000-40100) if a firewall is on.
- Drive/FTP copies against the owner's real accounts, restore drill with real business data, second off-machine backup copy, start at boot (`enable-linger`), firewall, HTTPS.
- Phone layout of the sticky top menu; real phone scan of the 2FA QR; browser behaviour of the new auto-lock script was checked by tests of its endpoints and by eye on a throw-away copy, not with an automated browser test.
- Not built: stock count sheet, task time tracking, audit log screen, per-user permissions beyond four roles, bulk imports, WhatsApp/SMS, e-invoicing/e-way bill.

## 13. Change log of the 2026-10-22 evening session
- Backups tab: Local / FTP / both, FTP form with Configure and Test, schedule (daily/weekly/hours/off, IST), Backup now with live loader and auto-refresh, runs list from the real local folder, FTP backups card, restore from FTP (`--ftp`), timers changed to a 15-minute check.
- Admin: one Admin settings area with Settings, Team & roles, Backups tabs; Backups needs no admin key; 30-second / tab-switch auto-lock with heartbeat; in-place key prompt that returns to the page; Data export now behind the key.
- UI: sidebar grouped into boxes in the owner's chosen order and compacted to fit; company name in the header; Admin settings fields compact in two columns.
- Tests grew from 331 to 391 smoke checks; new helper behaviours listed in section 9.

## 14. Follow-up fixes (2026-10-23)
- **Auto-lock must not fire on navigation.** Some browsers fire `visibilitychange` (hidden) while a page is being left (clicking a tab link, submit, reload). `app.js` now sets a short `leaving` flag on link/button click, `submit`, `beforeunload` and `pagehide` and ignores those events; only a real tab switch locks.
- **Page-script requests must not look like reloads.** The sign-out-on-reload rule (`session_rule` = `reload`, `dispatch()` in `site.php`) treats `Cache-Control: no-cache`/`max-age=0` GETs as reloads. `fetch(..., {cache:'no-store'})` sends those headers, so the heartbeat and the backup-progress poll (and `location.reload()` after a lock) were signing people out. Fixes: no `cache:'no-store'` in the script (the endpoints send `Cache-Control: no-store` themselves); `/staff/admin/ping` and `/staff/backups/status` are exempt from the rule; `admin_lock()` and a 403 from the ping set `$_SESSION['reload_ok']`, which lets exactly one following reload through. Tests cover all three.
- **Backups tab keeps the admin unlock alive** (`backups_guard()` calls `admin_unlocked()`), so Settings -> Backups -> Settings does not ask for the key again.
- **My account -> Two-factor login:** every 2FA action now ends on `/staff/me#twofa` and shows its message inside that card (`twofa_done()` in `account.php`, `$_SESSION['twofa_msg']`), so the QR code and any error are visible without scrolling.
- **Layout/scroll audit (56 pages x 8 widths 360-1366 px, plus sidebar height 460-900 px, measured in the browser):** before, 66 page/width combinations scrolled sideways (a wide table or board stretched the grid column). Fixed in `style.css` with `min-width:0` on the page column, grid children and `.row` children, wrapping of long text/code, and a shrinkable key/value list; no page now scrolls sideways at any tested width (the task board, tables and the calendar scroll inside their own box). The sidebar fits without scrolling from 500 px height up. Method: fetch each page, strip scripts, render it in an iframe via `srcdoc` at each width, compare `scrollWidth` with `clientWidth` and list the deepest offending elements.
- One smoke check ("with Local selected nothing is uploaded to FTP") failed once in a `--keep` run and then passed in several runs; it now prints rc/output when it fails. If it recurs, look at run-id collisions (ids are per second) and at the rclone SFTP test server on port 2199.

## 15. Cross-page relations (verified 2026-10-23) and known design choices
Each row says what links to what, where it lives, and whether a smoke test covers it.
| Relation | Where | Rule |
|---|---|---|
| Print job finished -> stock | `job_update()` in `production.php` | Inside one transaction (job row and item row `FOR UPDATE`): `use = actual_grams / grams_per_unit`; stock cannot go below zero (refused, nothing changes); a `use` row is written to `is_stock_moves` with the order id; job gets `material_cost = use x unit_cost` and `machine_cost = hours x calc_machine`; a low-stock alert fires when the new quantity is at or under the reorder level. Finishing twice is blocked (status re-read under lock). |
| Job failed / removed | same | Failed: optional `wasted_grams` (field next to the Failed button, queue and order page). Blank deducts nothing; a number deducts it as a `waste` stock move (note "Failed print job #N (g g wasted)"), sets the job's `material_cost` and counts in the order's cost; refused if stock is too low; a failed or finished job cannot be failed again (status re-read under lock). Only queued jobs can be removed. |
| Order cancelled/failed -> queue | `set_request_status()` in `orders_common.php` | Queued jobs of that order are deleted and the history note says so; jobs already printing stay and are flagged in the note. New jobs cannot be planned on completed/cancelled/failed orders. |
| Order <-> jobs | order page, Print queue | Starting a job moves an approved/scheduled order to Printing. Finishing the last open (queued/printing) job of a Printing order moves it to Quality check automatically (history "All print jobs finished (job #N)", status notification sent); other statuses are never changed automatically. Only failed jobs left: the order stays on Printing. |
| PO received -> stock | `po_update()` in `purchasing.php` | All-or-nothing receipt (no part deliveries); qty added, a `purchase` move logged; the item's `unit_cost` is set to the line price (**last purchase cost, not an average**; a zero price leaves the old cost). PO cannot be received twice or after cancel. |
| Low stock -> reorder -> PO | `reorder_page()` | Items at or under `reorder_level` (active only), items already on an open PO are shown but not pre-ticked; creates a PO with last unit costs. |
| Manual stock moves | `inv_move()` | purchase/return add, use/waste subtract, adjust sets the counted quantity; never below zero; optional order reference links the move to an order. Direct qty edits are not possible: every change has a move row (tested: sum of moves = quantity). |
| Order -> quote -> invoice -> payment | `requests.php`, `invoices.php` | Invoice form is prefilled from the order/customer; invoice stores a snapshot of customer and seller details; part payments, status `paid` when `total - payments - credit notes <= 0`; credit notes only up to the invoice subtotal; voiding a paid invoice is owner-only. Payment on an invoice does not change the order status. |
| GST / profit pages | `billing.php` | Outward tax = non-void tax invoices minus credit notes; ITC from supplier bills and expenses. **Edge cases left as is:** credit notes of an invoice that was voided afterwards still reduce outward tax; payments on a voided invoice still count as cash in; purchases reach profit only when a supplier bill is entered (a PO does not create one). |
| Dashboard and lists | `workspace.php` | KPI tiles link to the same filters the list pages use. |
Delivery: `is_requests.delivery_method` (`courier`, `hand`, `pickup`; `DELIVERY_METHODS` in `production.php`, schema version 13). Courier name and tracking are kept only for `courier`; setting an order to Dispatched appends `[Courier: x (tracking y)]`, `[Hand delivery by our staff]` or `[Customer pickup]` to the history note; the delivery challan shows the method. The statuses themselves are unchanged. Diagrams: `docs/order-status-flow/` (Word + PDF + PNGs).
Not linked by design: invoices and orders (no auto-invoice), print jobs and printer status (a job can be queued on a printer in maintenance), supplier bill and PO.

## 16. Where decisions are recorded
`SYSTEM_WRITEUP.md` section 1 (decision table), `ROADMAP.md` (the Owner's own ticks and comments on the feature checklist), `LOCAL_HOSTING.md` (hosting decision), `BACKUP_OPERATIONS.md` (backup how-to, including the 2026-10-22 additions), `archive/` (superseded Hostinger/Dolibarr/Windows material, kept for history only).
