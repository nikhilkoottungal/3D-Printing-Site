# Infynite site scratchpad

Updated: 2026-10-21

## Hostinger test deployment (2026-10-21)

- Test URL: `https://pink-viper-143770.hostingersite.com/` (temporary domain, not production).
- The public `public_html/` root now contains only `assets/`, `.htaccess`, and `index.php`. Private `app/` and `storage/` are above `public_html/`; the root front controller resolves the app outside the document root.
- Prior `infynite-test/` staging copy was moved above `public_html/` for rollback. The former root `index.html` was downloaded and retained above the web root as `index.html.hostinger-backup-20261021`; old CSS and deployment archive/package were moved out of the public root too.
- Staging config was copied into the private app and its `base_url` changed to the temporary root URL. Existing database, DB account, setup token, Owner account, password hashes, and data were retained; no migrations or password/account changes were made. Never put secret values in notes.
- Browser verified homepage, `/services`, and authenticated `/staff` with existing records; current browser tab was `/staff/team`. This is a functional smoke observation, not a production signoff.
- Security follow-up: rotate the DB password and setup token because the Hostinger file manager's accessibility output exposed both while config was being edited. Verify DB connectivity and setup endpoint closure afterward.
- Remaining before production: real-domain routing/HTTPS; Hostinger PHP version/extensions and limits; real STL/3MF upload; outgoing mail; scheduled/off-site backups and restore drill; mobile/security/privacy review; explicit launch approval. Keep rollback copies until a separate backup is confirmed.

## What this is

Infynite Solutions is a plain PHP 8 web portal backed by MySQL/MariaDB. It has no framework, Composer package, npm build, or background process requirement. `public/` is the only web root; `app/`, `sql/`, `storage/`, `tools/`, and `docs/` must remain outside it.

The repository also contains a separate bundled Dolibarr 23.0.4 copy under `WEB/dolibarr-local`; Dolibarr is an optional ERP integration and should be run with its own web root and documents directory. `WEB/infynite-design` is a static design preview.

## Main features

- Public pages: home, services, materials, gallery, contact, privacy, terms.
- Customer registration, email verification/reset, login, dashboard, model upload, quote requests, approvals/cancellation, order tracking, export/deletion requests.
- Staff workspace: orders, inventory, backups, team/roles, tasks, knowledge/procedure articles, history/restore, and customer requests.
- Optional server-side Dolibarr REST sync for customers/proposals.

## Local test contract

- PHP 8.1+ (the writeup recommends PHP 8.3) with curl, fileinfo, GD, intl, mbstring, mysqli, openssl, PDO MySQL, sodium, zip, and zlib.
- MariaDB/MySQL.
- `app/config.php` copied from `app/config.example.php`; this is secret and must not be committed or copied into documentation.
- Local mail should use `mail.log_only = true`; links are written to `storage/logs/mail.log`.
- Automated checks: `php tools/smoke_test.php` (62 end-to-end checks) and `php tools/test_roundtrip.php` (22 backup/restore checks). They create throw-away databases and the smoke test uses port 8099.
- Quick local server: `run-dev.bat` on `http://localhost:8080`. Host-like setup uses Apache virtual hosts with `portal.localhost` and optionally `erp.localhost`.

## Safety and operating rules

- Never run destructive production DB commands or touch production without explicit approval and a backup.
- Do not start rclone, Drive upload, or deployment before the Phase 0 hosting report is GO.
- Keep private storage above the web root. Do not commit `app/config.php`, keys, API keys, uploads, or backups.
- After PHP changes: run syntax checks, smoke test, and backup roundtrip test when backup code is involved.

## Current local findings

- Git is installed.
- `php` and `mysql` are not currently available on PATH.
- Chocolatey is present, but its PHP installation attempt failed because the shell was not elevated and the `vcredist140` dependency could not install. A retry from an Administrator PowerShell, or a user-local PHP/MariaDB installation, is required before tests can run.

## Verified Windows result

- PHP 8.5.11 and MariaDB 13.0.2 were installed locally.
- The local MariaDB root password was reset, a development database/user was created, and `sql/schema.sql` was imported.
- PHP syntax checks passed with zero errors.
- Smoke test passed 62/62 checks.
- Backup/restore test passed 21 executed checks with 0 failures; one read-only-folder outage check is skipped on Windows.
- The smoke-test child server originally did not inherit CLI extension flags, causing missing `pdo_mysql` and `mbstring` errors. `tools/smoke_test.php` now forwards loaded extensions to the child server.
- The persistent local site is served at `http://127.0.0.1:8080` from the Administrator PowerShell. Create the first Owner at `/setup`; no default admin credentials are seeded.

## Relevant documents read

- `README.md`
- `AGENTS.md`
- `docs/WINDOWS_SETUP.md`
- `docs/HANDOVER.md`
- `docs/PHASE0_HOSTING_CHECKLIST.md`
- `docs/BACKUP_OPERATIONS.md`
- `docs/BACKUP_RESTORE_PLAN.md`

