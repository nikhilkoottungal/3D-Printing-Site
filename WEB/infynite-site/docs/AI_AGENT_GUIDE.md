# AI agent guide: Infynite internal server

For any AI coding agent (or new developer) continuing this project. Read this first, then `SYSTEM_WRITEUP.md` (what and why) and `FEATURES.md` (where things are). Version 2026-10-22.

## 1. What this is
A staff-only business system for a 3D printing company, run on an office machine on the LAN. Plain PHP 8.3 + MariaDB, no framework, no Composer, no Node, no background daemons. The owner (a hardware engineer, not a web developer) inspects the result in a browser and asks for changes in plain language. Keep answers short, say what was verified and what was not, and never claim a result you did not see.

## 2. Hard rules
1. **Never print, copy, commit or upload secrets:** `app/config.php`, `storage/secret.key`, `SETUP_TOKEN.txt`, `*.key`, SMTP/FTP passwords, the database password, backup keys, bank/Aadhaar/PAN data. Source archives must be built with `offsite_source_zip()` (it excludes them) and scanned for the database password and setup token before upload.
2. **Do not touch the live database destructively.** Tests must use their own database: the smoke test builds `infynite_smoke` and points the server at a temp config through `INFYNITE_CONFIG`. CLI tools that load `app/bootstrap.php` use the live config unless `INFYNITE_CONFIG` is set.
3. **No customer logins, no public pages.** `/` redirects to the login page; customers exist only as `role='customer'` rows entered by staff and are rejected at login and password reset.
4. **Admin safety rules stay:** one admin cannot remove or demote another (request that every admin approves); admin keys are personal; admins cannot be deleted by one person; permanent deletion only for removed non-admin accounts (or self-deletion for non-admins).
5. **Do not add paid services, a VPN dependency or a public exposure.** Outbound mail goes through the SMTP settings; off-site copies go through rclone (Drive/FTP) and only when the Owner enables them.
6. Do not run `rm` with shell-variable targets (the sandbox blocks it); use literal absolute paths. Do not `pkill -f` patterns that can match your own shell.
7. After **every** change: `php -l` on changed files, then `php tools/smoke_test.php` and, if backups were touched, `php tools/test_roundtrip.php`. Both must say `0 failed`. Add tests for new behaviour (see section 6).

## 3. Running things
```bash
cd /home/njolly/Documents/WEB/infynite-site
./deploy/server.sh status|start|stop|restart|logs|url|timers|backup|test
php -S localhost:8080 -t public public/index.php        # foreground dev server (only this computer)
```
The live service is a systemd **user** unit (`infynite-portal`) on `0.0.0.0:8080` (`deploy/infynite-portal.service`); PHP reloads files per request, so code changes are live without a restart. No sudo is available to agents on this machine. Browser testing: start a throwaway instance on another port with a temporary database and config (see how the smoke test does it), never use real credentials.

## 4. Code map and conventions
- **Routes:** `routes()` in `app/controllers/site.php`: `['GET'|'POST', 'regex', 'function_name']`. `dispatch()` applies the sign-in rules (reload sign-out, idle timeout), CSRF (`csrf_check()` on every POST), then calls the function.
- **Controllers** are plain functions grouped by area in `app/controllers/*.php` (list them in `app/bootstrap.php`). Start every handler with `require_staff('permission')`; permissions come from `ROLE_PERMS` in `app/lib/core.php` (`team.manage` = Owner only via `'*'`).
- **Views:** `render('staff/x', [...], 'staff')`; always escape with `e()`. The CSP forbids inline scripts: behaviour goes in `public/assets/app.js` via data attributes. After editing CSS/JS the layout adds a version query string, so browsers pick it up.
- **Database:** helpers `q()`, `one()`, `all()`, `insert()`; always prepared statements. New table or column: add to `sql/schema.sql` **and** bump `SCHEMA_VERSION` in `app/lib/migrate.php` (add an `ALTER` for existing databases in the column list there). Verify live vs fresh with an information_schema comparison.
- **Settings:** `SETTING_GROUPS` in `app/lib/settings.php` defines Admin settings fields (types: text, textarea, select, int, money, percent, host, port, email, prefix, gstin, pan, secret). Read with `setting('key', default)`. Secrets are encrypted via `secret_encrypt()`.
- **Mail and notifications:** `send_mail()` (SMTP or log-only), `notify($event, ...)` for rule-based alerts (`NOTIFY_EVENTS` in `app/lib/notify.php`), `notify_admins()` for security notices.
- **Documents:** `Pdf` class in `app/lib/pdf.php` (A4, Helvetica, `Rs.` for the rupee sign); `Qr::svg()` for QR codes; `doc_head()`/`party()` in `production.php` for letterheads.
- **Numbering:** `next_number('key_next', 'prefix', userId)` inside a transaction for invoices, POs, credit notes, pro-formas.
- **Sensitive personal data:** Aadhaar/PAN/bank account via `hr_profile()`/`hr_save()` in `profile.php`; ID copies encrypted on disk; Aadhaar masked everywhere.
- **Sign-in rules:** `app/lib/session_rules.php` (pure) and `dispatch()`; a browser sends `Cache-Control: max-age=0` on a reload **and** on the page after a form submission, so reload detection ignores the 5 seconds after any POST.
- **Subfolder mounting:** `base_path()` plus an output-buffer rewrite of root-relative links in `public/index.php`; keep links root-relative (`/staff/...`).

## 5. Pitfalls already hit (do not repeat)
- PHP arrow functions capture by value: `fn($i) => ($i['x'] = ...)` does not change the caller's array (bank matching bug). Use loops.
- PHP return types cannot be `[bool, ?string]`; use `array`.
- In the smoke test, `is_file()` results are cached: call `clearstatcache()` before asserting a file was deleted by the server.
- A smoke test that dies leaves its SMTP sink on port 2527 and breaks the next run (mail assertions fail): kill the stale python process first (`ss -ltnp | grep 2527`).
- The test HTTP client sends nested arrays with `http_build_query` only when no file is attached.
- `settings_all()` is cached per request; after `setting_set()` it refreshes itself, but long-running CLI loops should call `settings_all(true)`.
- Admin settings form: posting a subset of fields blanks the others. Tests use a helper that re-posts all current values.
- Daily/CLI tools must not define `APP_ROOT` themselves (bootstrap does).
- `mysqldump`/MariaDB credentials come from `app/config.php`; never echo them. Config printing once leaked an old API key to a terminal: use `sed` filters or read only the keys you need.
- QR codes: custom encoder (byte mode, level M, versions 1 to 10); keep payloads under about 210 bytes (the 2FA link is shortened for this).

## 6. How to add a feature (checklist)
1. Decide permission and role; add route(s) in `site.php`, controller function, view.
2. Schema: `sql/schema.sql` + `SCHEMA_VERSION` + migrate step; run the smoke test (it builds from schema) and compare live vs fresh.
3. Add checks to `tools/smoke_test.php` in the right section (blocks are inside one `try`; the sink process is closed at the end of the phase blocks). Test the happy path, validation errors, role refusal, anonymous redirect (302) and any file/e-mail/PDF output (the e-mail sink records attachments).
4. Update `docs/FEATURES.md`; for operations changes update the guide and this file.
5. Lint, run both suites, check the live service still answers (`curl -I http://<ip>:8080/login`).

## 7. Current state
Everything in `FEATURES.md` is built and tested (smoke 331, roundtrip 22). Live database schema version 12. Services and timers are installed (portal, backup 22:30, off-site 22:45, daily 08:00). Open items (need the Owner or an administrator): start at boot (`sudo loginctl enable-linger`), firewall for the office subnet, HTTPS, Drive/FTP connection in Admin settings, second off-machine backup copy, restore drill with real data. Not built: stock count sheet, task time tracking, audit log screen, per-user permissions beyond four roles, bulk imports, WhatsApp/SMS, e-invoicing/e-way bill.

## 8. Where decisions are recorded
`SYSTEM_WRITEUP.md` section 1 (decision table), `ROADMAP.md` (the Owner's own ticks and comments on the feature checklist), `LOCAL_HOSTING.md` (hosting decision), `archive/` (superseded Hostinger/Dolibarr/Windows material, kept for history only).
