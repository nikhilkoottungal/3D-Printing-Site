# Infynite Internal Server: System Write-up

Version 2026-10-22. This document describes what was built, why, how it works, how to run it, and what is still open. For day-to-day steps see `FIRST_ADMIN_BACKUP_RESTORE_GUIDE.md`; for the page-by-page feature map see `FEATURES.md`; for an AI agent continuing the work see `AI_AGENT_GUIDE.md`.

## 1. Purpose and decisions

Infynite Solutions runs a 3D printing business with a small staff. The system manages orders, production, stock, purchasing, billing, tasks and knowledge in one place.

| Date | Decision | Consequence |
|---|---|---|
| 2026-10-21 | Start as a public site plus customer portal and a Dolibarr ERP on Hostinger shared hosting | Prototype, 62 tests |
| 2026-10-22 | Do not host live; run on an office machine, **office LAN only** | No public exposure; local backups and systemd services |
| 2026-10-22 | **Staff-only**: no public site, no customer self-service (it "can be misused") | That code was removed. Customers are records entered by staff and can never log in |
| 2026-10-22 | **Dolibarr not required**: the portal and its database handle everything | Billing, purchasing, stock, GST, expenses and reports were built into the portal; Dolibarr database dropped and code archived |
| 2026-10-22 | Business settings (GST, numbering, bank, mail, pricing) editable in an Owner-only page behind a **personal admin key** | Admin settings page |
| 2026-10-22 | Safety rules for admins: no admin can remove another alone; key changes are announced; two-factor login | Mutual-agreement removal, per-admin keys, TOTP |

## 2. Architecture

**Stack:** plain PHP 8.3, MariaDB 10.11, no framework, no Composer, no Node. The web server is PHP's built-in server run as a systemd **user** service on `0.0.0.0:8080`. MariaDB listens on 127.0.0.1 only.

**Request flow:** `public/index.php` (front controller) loads `app/bootstrap.php` (config, libraries, controllers, security headers, session, migrations) and calls `dispatch()` in `app/controllers/site.php`, which applies the sign-in rules, matches the route table and calls a controller function. Views are plain PHP templates in `app/views`.

```
public/            the only web-visible folder: index.php, assets/ (css, js, vendored three.js)
app/bootstrap.php  startup
app/config.php     SECRET local settings (database login, setup token, backup keys). Never copied anywhere.
app/lib/           core helpers, auth, settings, mail+SMTP, PDF writer, QR code, TOTP, offsite (rclone), migrations ...
app/controllers/   one file per area (orders, invoices, purchasing, billing, production, workspace, team, admin ...)
app/views/         templates (staff/ holds the pages)
sql/schema.sql     all tables (CREATE IF NOT EXISTS); upgrades run automatically (app/lib/migrate.php)
storage/           private: uploads (encrypted ID copies, model files), logs, backups, secret.key
tools/             backup, restore, offsite, daily job, smoke tests, key tools
deploy/            systemd units and scripts (server.sh, install-lan.sh)
docs/              documentation (archive/ holds superseded Hostinger-era files)
```

**Data model (35 tables, all prefixed `is_`):** users and sessions (`is_users`, `is_login_attempts`); orders (`is_requests`, `is_request_files`, `is_request_history`, `is_order_qc`, `is_order_issues`); production (`is_printers`, `is_print_jobs`); inventory (`is_inventory_items`, `is_stock_moves`); purchasing (`is_suppliers`, `is_purchase_orders`, `is_po_lines`, `is_supplier_bills`, `is_bill_payments`, `is_expenses`); billing (`is_invoices`, `is_invoice_lines`, `is_invoice_payments`, `is_credit_notes`); tasks and knowledge (`is_tasks`, `is_task_comments`, `is_recurring_tasks`, `is_notes`, `is_attachments`, `is_kb_articles`, `is_kb_versions`); people (`is_staff_profiles`, `is_staff_docs`); admin (`is_settings`, `is_notify_rules`, `is_admin_requests`, `is_admin_approvals`, `is_audit_log`).

**Schema upgrades:** `migrate()` runs once per `SCHEMA_VERSION` (currently 12): it creates missing tables, adds missing columns, and backfills. The live database and a fresh install were compared column by column and match.

## 3. Roles and permissions

| Role | Can do |
|---|---|
| Owner (admin) | Everything, including team, admin settings (with admin key), integrations, P&L, exports |
| Sales | Orders, quotes, customers, invoices, credit notes, GST and reports |
| Production | Orders, production statuses, print jobs and printers, quality checks, stock use |
| Purchasing | Inventory, suppliers, purchase orders, bills, expenses |

All staff can read and edit procedures, see tasks, use notes, the calendar and search (results limited by role). Permissions are enforced on the server for every route; links are only hidden for convenience.

## 4. What the system does (summary)

See `FEATURES.md` for pages and roles. In short:
- **Orders:** staff enter every order; status flow with history; price calculator; quotation, delivery challan and job label PDFs (QR code); STL 3D preview; quality checklist; rework and returns; delivery details.
- **Production:** printers, print queue with 14-day calendar, job costing, automatic filament deduction.
- **Stock and purchasing:** stock moves, reorder levels and suggestions, purchase orders (PDF, e-mail, receiving adds stock), supplier bills with part payments, expenses.
- **Billing:** invoices with CGST+SGST/IGST, part payments, pro-forma, credit notes, PDF and e-mail, reminders, GST summary, profit and loss, bank statement matching, reports and CSV exports.
- **Work:** tasks (checklists, comments, attachments, recurring), notes and checklists, calendar, knowledge base with PDF export, global search, overview dashboard.
- **People:** personal and employment details (encrypted Aadhaar, PAN, bank numbers; ID copies), profile pictures, freeze, remove, permanent deletion rules.
- **Admin:** company, GST, numbering, bank, pricing, e-mail (SMTP with test), notification rules, integrations (Google Drive via rclone, FTP/SFTP), security settings.

## 5. Security measures

- **Network:** LAN only; MariaDB on localhost; no secrets in the web folder.
- **Sign-in:** password hashing, login and reset rate limits, CSRF tokens on every form, secure cookies, a strict Content-Security-Policy (no inline scripts), optional TOTP two-factor with recovery codes, sign-out on page reload (setting) and idle sign-out (currently 1 minute, with a keep-alive while someone works), frozen accounts blocked at once.
- **Admin protection:** personal admin keys (hashed, rate-limited, 15-minute unlock); changes announced to other admins by e-mail; removing an admin needs every admin's agreement.
- **Data protection:** SMTP/FTP passwords, 2FA seeds, Aadhaar, PAN, bank account numbers and ID copies are encrypted at rest with a key in `storage/secret.key` (included in backups). Aadhaar is always shown masked. Owner views of personal data are audit-logged.
- **Uploads:** content checked (not just extension), random stored names, served only to signed-in staff.
- **Audit log:** sign-ins, settings changes, exports, deletions and sensitive views.
- **Backups:** encrypted with public keys; the private key stays off the server.

## 6. Operations

**Start, stop, check** (the portal is a systemd user service):

```bash
cd /home/njolly/Documents/WEB/infynite-site
./deploy/server.sh status     # is it running, and the address
./deploy/server.sh start      # or stop / restart
./deploy/server.sh logs       # service log and PHP errors
./deploy/server.sh timers     # backup, off-site and daily jobs
```
Address: `http://<server LAN IP>:8080` (currently 10.0.0.44). Code changes take effect on the next page load; a restart is only needed after changing the service file. First install: `./deploy/install-lan.sh`. Start at boot without a login needs `sudo loginctl enable-linger <user>` once.

**Scheduled jobs (systemd user timers):** nightly encrypted backup 22:30 (`tools/backup.php`), off-site copies 22:45 (`tools/offsite.php`), daily summaries and recurring tasks 08:00 (`tools/daily.php`).

**Backup and restore:** see section 3 and 4 of the guide. Backups go to `/home/njolly/infynite-backups` (encrypted). Restore with `tools/restore.php` (safe drill into a scratch database first; live restore saves a snapshot and shows the maintenance page). The private key file and its passphrase must be stored off the server.

**Maintenance page:** the file `storage/maintenance.flag` makes every request show "Back soon".

**Tests:** `php tools/smoke_test.php` (331 end-to-end checks, builds its own database and server) and `php tools/test_roundtrip.php` (22 backup/restore checks). Both must report 0 failed before any change is considered done.

## 7. Verification status (2026-10-22)

- Smoke suite 331/331, backup roundtrip 22/22; live schema identical to a fresh install.
- Checked by eye in a browser: login and reload behaviour, scroll position, sticky menu, STL preview, QR code (decoded with a scanner library), My account and Team pages.
- Real e-mail sent through Gmail SMTP (test succeeded).
- Not verified: phone layout of the sticky top menu, real phone scan of the two-factor QR, FTP/Drive copies against real servers (tested against a local rclone remote), restore drill with real business data.

## 8. Open items

| Item | Needs |
|---|---|
| Start at boot (`enable-linger`), firewall for the office subnet, HTTPS (nginx/Caddy), fixed LAN IP | Admin (sudo) |
| Connect Google Drive / FTP in Admin settings > Integrations | Owner |
| A second copy of the backup folder off the machine | Owner |
| Restore drill with real data | Owner |
| Not built: stock count sheet, task time tracking, audit log screen, per-user permissions beyond four roles, bulk imports, WhatsApp/SMS, e-invoicing and e-way bills | Decide if wanted |

## 9. Build history

- **Phase 0 (day 1):** portal prototype, backup toolkit, Windows guide, Hostinger plan (archived).
- **Local hosting:** staff-only mode, systemd service, customer portal removed, Dolibarr dropped.
- **A foundation:** SMTP settings, notification rules, per-admin keys, mutual admin removal, My account, two-factor login (QR).
- **B backups and storage:** PDF writer, Integrations page (Drive, FTP), knowledge base PDFs.
- **C orders and production:** calculator, documents, QR labels, quality checks, printers, queue, costing, search, STL preview.
- **D purchasing:** PO PDF and e-mail, reorder suggestions, supplier bills.
- **E billing:** part payments, credit notes, pro-forma, GST summary, expenses, P&L, bank matching, reminders.
- **F workspace:** recurring tasks, notes, attachments, calendar, dashboard, exports.
- **People and safety:** personal details with encrypted ID data, profile pictures, freeze/remove/delete, reload and idle sign-out, sticky menu, scroll memory.
