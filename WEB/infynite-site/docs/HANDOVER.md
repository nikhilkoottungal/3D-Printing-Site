# Infynite Solutions portal – Handover for AI agents

Version of this document: 2026-10-21. Source brief: `Infynite_Solutions_AI_Agent_Implementation_Brief.docx` (v1.1, "3D Printing Business Platform").

## 1. What this is

A custom, lightweight PHP portal for **infynitesolutions.in**, a 3D-printing business with four internal staff:

1. **Public website** (home, services, materials, gallery, contact, privacy, terms).
2. **Customer portal** (register, verify email, log in, upload STL/3MF, place an order / request a quote, approve a quote, track the order, export/delete data).
3. **Staff workspace** (`/staff`): orders, tasks, knowledge base & procedures, inventory, team & roles.
4. Optional **Dolibarr ERP** integration over REST (customers + proposals). Dolibarr is not required to run the current portal; its Hostinger test install was not completed.

Everything is plain PHP + MySQL/MariaDB. No framework, no Composer, no Node, no background workers.

### Status snapshot (2026-10-21, end of day 1)
| Area | State |
|---|---|
| Portal (public site, customers, orders, direct orders, tasks, KB, inventory, team, backups page) | Built; **62/62** end-to-end checks pass (`php tools/smoke_test.php`, portable, builds its own DB) on Linux PHP 8.3 + MariaDB |
| Backup/restore tooling | Built; **22/22** checks pass (`php tools/test_roundtrip.php`); live disaster restore verified, incl. Dolibarr |
| Dolibarr | Optional; not required for the current portal. Hostinger test install was not completed; owner removed the empty `/erp` directory before installation. |
| Windows | Guide written; PHP 8.5.11 + MariaDB 13.0.2 installed and tests verified locally (see `SCRATCHPAD.md`) |
| Hostinger | Temporary test deployment live at `https://pink-viper-143770.hostingersite.com/`; clean public root and private app/storage above it. Not production; see “Hostinger test deployment” below. |
| rclone transfer (host to PC to Google Drive) | **Decided, not built** (owner: use rclone; wait for Phase 0) |
| Not tested | mobile layout on a real phone, real e-mail delivery |
| Not built | MFA for staff, unit tests beyond the two scripts, supplier POs, Drive adapter, accessibility audit |
Demo data lives only in the developer's local databases. **Do not ship it.** A fresh install gets starter content from `app/lib/seed.php`.

### Document index (read in this order)
1. `AGENTS.md` (rules for agents). 2. This file. 3. `docs/PHASE0_HOSTING_CHECKLIST.md` (**next milestone**). 4. `docs/WINDOWS_SETUP.md` (local environment mirroring the host). 5. `docs/BACKUP_RESTORE_PLAN.md` (design/decisions) and `docs/BACKUP_OPERATIONS.md` (tools how-to).

### Immediate next actions (priority order)
1. Keep the temporary Hostinger deployment test-only. Rotate the DB password and setup token because their values appeared in the file manager's accessibility output; verify app login/DB connectivity and setup endpoint closure after rotation. Do not paste secrets into chat or docs.
2. Complete Hostinger checks: PHP version/extensions, upload limits with real STL/3MF files, outgoing mail, HTTPS/cookie behavior, and cron/SSH/SFTP/database limits. Record measured results in `PHASE0_HOSTING_CHECKLIST.md`.
3. Confirm recoverable backups of the prior root and rollback/staging copies before deleting anything. Only after checks and explicit approval, connect the real domain and progress to production.
4. Generate backup keys (`tools/keygen.php`), schedule backups, build the **rclone** transfer (host to owner PC via SFTP/FTPS, PC to Google Drive 10 GB cap) and dead-man alert; perform an off-site restore drill before launch.
5. Remaining MVP gaps: storage cleanup of old files, MFA, supplier purchasing/PO workflow (add Dolibarr only if needed), professional privacy/terms review, final test report approved by the owner (brief section 8).

### Hostinger test deployment (2026-10-21)

- URL: `https://pink-viper-143770.hostingersite.com/` (temporary domain; test only).
- `public_html/` contains only `assets/`, `.htaccess`, and `index.php`. Private `app/` and `storage/` are above the document root; the front controller points to the private app.
- Previous `infynite-test/` staging copy and old root `index.html` backup were moved above the web root for rollback. The old HTML was downloaded first. Old CSS and package/archive were also moved out of public_html.
- Staging config was copied into the private app and only its `base_url` was changed. Existing database (`u151540875_infynite_stage`), DB account, setup token, Owner user/password hash, and data were preserved; no migration or credential reset was done. Never document secret values.
- Browser observations: homepage, `/services`, and logged-in `/staff` work; existing Owner records are present (current tab was `/staff/team`). This is not full production validation.
- Security: rotate DB password and setup token; both secret values were exposed in file-manager accessibility output during config editing. Then verify connectivity and that setup is closed. Do not repeat the values.
- Still unverified: real domain, PHP extensions/limits, real upload, mail, HTTPS/cookies, backup automation/off-site recovery, complete mobile/security review, and launch approval.

### Dolibarr decision (2026-10-22)

- Dolibarr is **not necessary for the current portal to function**. The portal already covers customer accounts, quote/order workflow, staff tasks and procedures, team roles, and basic inventory.
- Dolibarr would be a separate optional system for fuller ERP workflows such as supplier purchasing, accounting/invoicing, or warehouse management. The existing portal integration is limited to server-side customer/proposal sync; installation alone would not make every ERP function appear in the portal UI.
- Owner removed the newly created, empty Hostinger `public_html/erp/` directory before installation. The Dolibarr archive was not installed and the isolated Dolibarr database was not initialized with application tables. The existing portal app/database and its users/data were not modified as part of this decision.
- If ERP requirements emerge later, define the source of truth per entity before connecting the systems to avoid duplicate or conflicting customer, order, and stock records.

## 2. Hard constraints from the brief (do not violate)
- Use the existing Hostinger **Single** plan: no VPS, Docker, root, persistent Node/Python, or extra paid services; do not upgrade the plan without owner approval.
- PHP 8.x, HTML/CSS/JS, MySQL/MariaDB. Avoid heavy dependencies. Dolibarr is optional, not a runtime prerequisite.
- If Dolibarr is adopted later, explicitly define which system owns each entity. Any portal integration must use **server-side REST only**; never write to Dolibarr tables directly. Until that decision, the portal's own database remains authoritative for the workflows implemented in the portal.
- Current Hostinger shared-plan constraint: do not rely on persistent background workers/daemons. The application uses ordinary PHP requests; scheduled tasks, if needed, must use cron within the plan's limits.
- Model files and documents must live **outside the public document root**; if the host cannot do that, stop and propose a secure alternative before accepting uploads.
- Security: HTTPS, secure cookies, server-side authorization on every record/file, CSRF, prepared SQL, output encoding, rate-limited login/reset, `password_hash`, validated uploads, no secrets in repo/logs.
- Never modify production DB/files without explicit approval and a verified backup. Never ask for passwords in chat.
- Out of scope for MVP: automatic slicing/pricing, payments, printer integration.
- Daily encrypted off-site backups and tested restore are required before launch. Owner must approve the final test report before public launch.

### Deliberate deviations / decisions the owner should confirm
| Decision | Why | Where |
|---|---|---|
| Dolibarr is optional and not needed for the current Hostinger test portal | Existing portal provides the current customer/order/staff workflows and basic inventory; no ERP workflow has been selected as a blocker. Add Dolibarr only for specific fuller ERP requirements. | 1, Hostinger test deployment |
| Staff **tasks, knowledge base/procedures, inventory and team roles live in this portal**, not in Dolibarr's modules | Dolibarr has no procedure/wiki feature; the owner wanted tasks with comments, procedures and role assignment. The brief assigns "projects/tasks" to Dolibarr. | Section 6 |
| Inventory is in the portal (simple items + movement log) | Owner asked for it. Brief puts stock in Dolibarr. Supplier purchase orders are still expected in Dolibarr. | 6.5 |
| Staff UI is the portal, not a separate Dolibarr "restricted path" | Simpler for the four users; Dolibarr stays for ERP. | – |
| Word "Order" is used everywhere in the UI (DB/URLs still say `requests`) | Owner request. | 9 |

## 3. Repository layout
```
infynite-site/
  AGENTS.md                  short rules for agents (points here)
  README.md                  quick start
  run-dev.bat / run-dev.sh   start the PHP dev server on :8080 (Windows / Linux)
  docs/                      HANDOVER.md (this), PHASE0_HOSTING_CHECKLIST.md, WINDOWS_SETUP.md, BACKUP_RESTORE_PLAN.md, BACKUP_OPERATIONS.md
  tools/                     keygen.php, backup.php, pull.php, restore.php (backup toolkit, lib/); smoke_test.php, test_roundtrip.php (tests);
                             phase0_probe.php (hosting probe); dolibarr_configure.php (enable modules + API key)
  sql/schema.sql             all tables (prefix is_), plus a commented ALTER for older DBs
  public/                    ONLY web-visible folder (document root)
    index.php                front controller (+ dev-server static file pass-through)
    .htaccess                Apache/LiteSpeed: HTTPS redirect + front controller + deny sensitive extensions
    web.config               IIS equivalent of the front controller rule
    assets/style.css, app.js CSS (light+dark, mobile first), tiny JS (CSP forbids inline script)
  app/                       NOT web-visible (has its own deny .htaccess)
    bootstrap.php            loads config, libs, controllers; error log; HTTPS redirect; security headers; session
    config.example.php       copy to app/config.php (secret, never commit)
    lib/                     core.php, auth.php, markdown.php, mail.php, upload.php, dolibarr.php, seed.php
    controllers/             site.php (route table + dispatcher), auth, customer, staff, requests, tasks, kb, team, inventory
    views/                   layout_site/app/staff.php, public pages, customer/, staff/
  storage/                   uploads/ (private model files), logs/ (php-error.log, mail.log in dev) – must be writable, must NOT be in the web root
```
Deployment rule: `public/` becomes the document root; `app/`, `sql/`, `storage/` sit above it. If the host forces everything inside `public_html`, change the `require` path in `public/index.php` and keep `app`/`storage` outside the served folder.

## 4. Request lifecycle and conventions
1. `public/index.php` → `app/bootstrap.php` (config, libs, controllers, headers, session) → `dispatch()`.
2. `dispatch()` (in `controllers/site.php`) matches `routes()` – a list of `[METHOD, regex, handler]`. Regex captures are passed as string args. **Every POST goes through `csrf_check()` centrally.** Order matters: `/staff/requests/new` etc. are listed before the `(\d+)` routes.
3. Handlers are plain functions; views are included by `render($view, $vars, $layout)`.
4. **Gotchas**
   - `render()` parameters are named `$__view/$__vars/$__layout` on purpose; `extract(..., EXTR_SKIP)` would otherwise let a view variable called `$view` be silently ignored (this was a real bug).
   - CSP is `default-src 'self'` with inline **styles** allowed but **no inline scripts**. Use `data-confirm`, `data-list`, `data-autosubmit` hooks handled by `public/assets/app.js`.
   - Output must always go through `e()`. `flashes.php` escapes error strings; pass plain text, not HTML.
   - All SQL uses prepared statements via `q()/one()/all()/insert()`. Dynamic WHERE/ORDER fragments are built only from constants.
   - Tables are prefixed `is_` so they can share a database with Dolibarr (`llx_`).
   - Dates are server-local (`now()`); MySQL `CURDATE()` is used for overdue checks.
   - `FIELD()` in ORDER BY is MySQL/MariaDB specific.
5. Sessions: cookie `isid`, HttpOnly, SameSite=Lax, Secure when HTTPS (also honours `X-Forwarded-Proto`). Session ID regenerated on login. CSRF token regenerated on login.

## 5. Data model (sql/schema.sql)
| Table | Purpose |
|---|---|
| `is_users` | Customers and staff. `role`: customer, owner, sales, production, purchasing. `token_hash/kind/expires` hold one verify/reset token (SHA-256 of the emailed token). `dolibarr_id` maps to a Dolibarr third party. |
| `is_login_attempts` | Rate limiting for login, register, reset, resend, setup, order submission. |
| `is_requests` | **Orders.** `ref` (IS-0001), `status`, `quote_amount/note`, `source` (`portal`|`direct`), `created_by`, `dolibarr_proposal_id`, `dolibarr_order_id`. |
| `is_request_files` | Uploaded models: random 40-hex `stored_name` on disk, original name, sha256, size. |
| `is_request_history` | Status history with notes and actor. |
| `is_tasks`, `is_task_comments` | Tasks (todo/in_progress/blocked/review/done; low..urgent; assignee, due date, `phase` group, optional `request_id`, `kb_id`); comments table also stores auto "event" rows (activity log). |
| `is_kb_articles`, `is_kb_versions` | Procedures/knowledge with full version history. |
| `is_inventory_items`, `is_stock_moves` | Stock items and an append-only movement log (`delta`, `qty_after`, reason, optional order link). |
| `is_audit_log` | Security-relevant actions (login, status change, file download, stock move, team changes…). |
Existing databases created before "direct orders" need: `ALTER TABLE is_requests ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'portal', ADD COLUMN created_by INT UNSIGNED NULL;`

## 6. Features

### 6.1 Public site
Static views in `app/views/`. Privacy and Terms are **drafts** that need review by a qualified Indian legal/tax professional. Gallery and contact details are placeholders.

### 6.2 Authentication & accounts (`controllers/auth.php`, `lib/auth.php`)
- Register (password ≥ 10 chars, terms checkbox, no account-existence leak), email verification (48 h), login (rate-limited: 6 failures per email / 20 per IP per 15 min; constant-time dummy hash for unknown users), logout (POST), forgot/reset (2 h tokens, rate-limited), resend verification.
- `/setup` (one-time): creates the Owner and seeds starter content. Needs `setup_token` from `config.php` (placeholder value is rejected; rate-limited). Disappears (404) once any staff account exists.
- Customer self-service: profile, password change, data export (JSON), account-deletion request (creates a task for the owner).

### 6.3 Orders (customer side)
- Customer uploads 1–10 STL/3MF files, chooses material/colour/quantity/notes (`/requests/new`). Order starts as `requested`.
- Customer sees only their own orders (404 for any other ID). Can approve a quote (stale-price protection: posts the quote amount seen), or cancel while `requested/under_review/quoted`.
- Status flow (constants `STATUSES`, `STATUS_FLOW` in `lib/core.php`): Requested → Under review → Quoted → Approved → Scheduled → Printing → Quality check → Ready/Dispatched → Completed; plus Cancelled / Failed. History is stored with notes.

### 6.4 Orders (staff side) – `controllers/requests.php`
- List with status tabs; detail page with files, history, quote form (sends email, only allowed while requested/under_review/quoted), status change (permission per status via `STATUS_PERM`), per-order tasks, "Sync to Dolibarr" (only if configured).
- **New direct order** (`/staff/requests/new`, permission `requests.create`): for phone/email/walk-in orders. Choose an existing customer or enter a new one (no login; placeholder `walkin-…@walkin.invalid` email if none; a real email lets them claim the account via Forgot password). Optional files, agreed price, starting status (restricted by the staff member's permissions; statuses from Quoted need a price), optional production task for an assignee. No email is sent. Order is tagged `source='direct'`.

### 6.5 Inventory – `controllers/inventory.php`
Items: name, SKU, category (filament/consumable/spare/other), material, colour, unit, qty, reorder level, location, supplier (text), unit cost, notes, archive. Movements: purchase (+), use (−), waste (−), return (+), stock-take adjust (sets counted total). Done in a transaction with `SELECT … FOR UPDATE`; stock can never go negative. Items at/below reorder level show a "Reorder" badge and a card on the staff overview. Optional order reference on a movement. Seed adds five filament spools at quantity 0.

### 6.6 Tasks – `controllers/tasks.php`
Fields: title, Markdown description (checklists), status, priority, assignee (staff only), due date, group/phase, linked order and procedure. List and board views, filters (assignee/status/group). Comments plus an automatic activity log (status/priority/assignee/due/title/description changes). Email notice to a newly assigned person and on comments. Edit rights: assignee, creator or owner; everyone on staff can comment.

### 6.7 Knowledge base & procedures – `controllers/kb.php`
Articles of type procedure/knowledge, category, optional review-by date (flagged when overdue), search, Markdown subset (`lib/markdown.php`: `#` headings, `**bold**`, lists, `- [ ]` checklists, `>` quotes, `[text](https://…)`; everything escaped first, only http/https links). Every save creates a version; history page can view and restore. "Create task from procedure" copies the body into a new task. Delete is owner-only. Slug `new` is reserved.

### 6.8 Team & roles – `controllers/team.php`
Owner adds staff (generates a one-time password-setup link, emailed and shown once), changes role, deactivates (open tasks are unassigned). An owner cannot demote/deactivate themselves.

### 6.9 Permission matrix (`ROLE_PERMS` in `lib/core.php`)
| Perm | Owner | Sales | Production | Purchasing |
|---|---|---|---|---|
| `requests.view` | ✔ | ✔ | ✔ | ✔ |
| `requests.quote` (quote, early statuses, Dolibarr sync) | ✔ | ✔ | – | – |
| `requests.production` (scheduled…completed/failed) | ✔ | – | ✔ | – |
| `requests.create` (direct order) | ✔ | ✔ | ✔ | – |
| `stock.use` (use/waste/return) | ✔ | – | ✔ | ✔ |
| `stock.manage` (items, purchase, adjust) | ✔ | – | – | ✔ |
| `team.manage` (also KB delete) | ✔ (via `*`) | – | – | – |
All staff: read/edit KB, view all tasks, comment, create tasks. Role names/split are a proposal; the brief only says "four role-based users".

### 6.10 Dolibarr (`lib/dolibarr.php`)
`dol_sync_request()` creates the Dolibarr third party (once, stored in `is_users.dolibarr_id`) and a draft proposal with one line (idempotent via `dolibarr_proposal_id`). Config: `dolibarr.url/api_key/ui_url/vat_rate`. **Untested against a real Dolibarr**: verify endpoint payloads, `tva_tx`, and ref numbering for the installed version. Not implemented: sales order creation, invoices, purchase orders, stock sync, pulling statuses back.

## 7. Security measures implemented
- HTTPS redirect (config `force_https` + `.htaccess`), HSTS, `X-Content-Type-Options`, `X-Frame-Options: DENY`, CSP, `Referrer-Policy`.
- CSRF on all POSTs (empty/missing token rejected – a bug that accepted an empty token for sessionless visitors was found and fixed).
- Password hashing (`password_hash`, rehash on login), min length 10, rate limits, generic messages.
- Authorization on every order/file/task route; customers get 404 for others' IDs; files are streamed through `/files/{id}` after an ownership/permission check, `Content-Disposition: attachment`, `no-store`, never in the web root.
- Upload validation (`lib/upload.php`): extension allow-list, size cap, quota per customer, STL structure (binary size match or ASCII `solid…facet`), 3MF: `PK` signature, entry count, uncompressed-size limit, path traversal / absolute / backslash / symlink rejection, compression-ratio check, required `[Content_Types].xml` and `3D/3dmodel.model`. Random 40-hex storage names, mode 0640.
- Prepared statements, output escaping, audit log, tokens stored hashed, user-enumeration avoided on register/forgot.
- Secrets: `app/config.php` only (not in repo); `app/` and `storage/` have deny `.htaccess` as a second layer.

## 8. Configuration (`app/config.php`, from `config.example.php`)
`base_url`, `force_https`, `debug`, `setup_token`, `db{host,port,name,user,pass}`, `mail{from,from_name,log_only}`, `storage`, `upload{max_bytes=25MB, max_files=10, quota_bytes=500MB, max_unzipped=200MB, max_zip_entries=2000}`, `dolibarr{…}`. The 25 MB / 500 MB numbers are **proposals**, not confirmed host limits. Also set PHP `upload_max_filesize` and `post_max_size` > 25 MB and `memory_limit` ≥ 128 MB.
Local development: `force_https=false`, `mail.log_only=true` (verification/reset links then appear in `storage/logs/mail.log`).

## 9. Naming note
UI says **Order(s)**. Internals still use "request": table `is_requests`, routes `/requests/…` and `/staff/requests/…`, functions `cust_request_*`, `sreq_*`, `own_request()`, permission strings `requests.*`. The first status is still labelled "Requested" (from the brief). Renaming URLs/tables is optional and should keep redirects.

## 10. Running it

The recommended local setup mirrors the host (Apache + PHP + MariaDB, `public/` as web root, app/storage outside): see `docs/WINDOWS_SETUP.md`; the same layout works on Linux. The quick alternatives below use PHP's built-in server and are fine for development and for the automated tests.

### Linux / macOS
```bash
sudo apt install -y php-cli php-mysql php-mbstring php-zip php-curl php-xml mariadb-server
sudo mysql -e "CREATE DATABASE infynite CHARACTER SET utf8mb4; CREATE USER 'infynite'@'localhost' IDENTIFIED BY '<choose>'; GRANT ALL ON infynite.* TO 'infynite'@'localhost';"
mysql -uinfynite -p infynite < sql/schema.sql
cp app/config.example.php app/config.php      # edit db, force_https=false, mail.log_only=true, setup_token (20+ random chars)
./run-dev.sh                                  # http://localhost:8080  → open /setup once
```

### Windows 10/11
The code uses only forward-slash paths and standard PHP; nothing is Linux-specific. `chmod()` is a harmless no-op on Windows. `mail()` does not work on Windows without an SMTP server, so keep `mail.log_only=true` for local use (or configure `sendmail_path`/SMTP in `php.ini` for real sending).
1. **PHP 8.3 x64 Non-Thread-Safe** zip from https://windows.php.net/download → extract to `C:\php`; add `C:\php` to the PATH; `copy php.ini-development php.ini`; in `php.ini` set `extension_dir = "ext"` and un-comment: `extension=curl`, `extension=fileinfo`, `extension=mbstring`, `extension=openssl`, `extension=pdo_mysql`, `extension=zip`. Optionally raise `upload_max_filesize=32M`, `post_max_size=40M`, `memory_limit=256M`. Verify: `php -v` and `php -m`. If PHP complains about missing DLLs install the **Microsoft Visual C++ Redistributable (x64)**.
2. **Database**: install MariaDB (MSI from mariadb.org) or MySQL 8, or XAMPP. In PowerShell (note: `<` redirection does not exist in PowerShell):
   ```powershell
   mysql -u root -p -e "CREATE DATABASE infynite CHARACTER SET utf8mb4; CREATE USER 'infynite'@'localhost' IDENTIFIED BY '<choose>'; GRANT ALL ON infynite.* TO 'infynite'@'localhost';"
   Get-Content sql\schema.sql -Raw | mysql -u infynite -p infynite
   ```
   (or `mysql -u infynite -p infynite -e "source sql/schema.sql"`).
3. `copy app\config.example.php app\config.php` and edit as for Linux. `storage` uses `APP_ROOT . '/storage'` and works on Windows paths.
4. Run `run-dev.bat` (or `php -S localhost:8080 -t public public\index.php`), open http://localhost:8080/setup.
5. Running behind a real web server on Windows: **Apache (XAMPP)** – DocumentRoot = `...\infynite-site\public`, `AllowOverride All`, `mod_rewrite` on (uses `public/.htaccess`; remove its HTTPS rule for local HTTP). **IIS** – PHP via FastCGI + URL Rewrite module; `public/web.config` is provided. Keep `app\` and `storage\` outside the site root.
6. Windows gotchas: use the `public\index.php` router form of `php -S` (static files are passed through in `index.php`); make sure the account running PHP can write `storage\uploads` and `storage\logs`; antivirus may briefly lock freshly uploaded files; keep line endings LF if editing (CRLF is fine for PHP but breaks nowdoc closers only if trailing spaces are added in `seed.php`).

### Deploying to Hostinger (not yet done)
Do **Phase 0** first (brief §6). Then: create DB, import `sql/schema.sql`, place `public/` as the document root and `app/`, `storage/` above it, create `app/config.php` with real values and `force_https=true`, `mail.log_only=false`, visit `/setup`, then change `setup_token`. Test uploads against the real PHP limits before announcing any size.

## 11. Verification performed so far (Linux, local)
Now committed and portable: `php tools/smoke_test.php` (62 checks) and `php tools/test_roundtrip.php` (22 checks). Areas covered:
- Pages 200; setup creates owner and seeds 10 procedures + 11 tasks (+ inventory seed on new installs); `/setup` then 404.
- Customer register→verify (via mail.log)→login; customer blocked from `/staff`; bad login rejected; POST without/empty CSRF → 419.
- Upload: valid STL + 3MF accepted; fake STL, executable renamed `.stl`, 3MF with `../`, and `.exe` rejected, no order created.
- Isolation: customer B gets 404 on A's order, file and approve; anonymous download redirected.
- Quote → approve → status to completed; tasks (create/comment/activity log/escaping); KB create/edit/version/history/task-from-procedure/`javascript:` link not rendered; team add; customer export.
- Inventory: create, use, purchase, adjust, negative blocked, Production can use but not purchase/create, low-stock filter and overview card.
- Direct orders: walk-in with file + auto task, quoted-without-price rejected, existing customer, Production cannot start as Approved, customer cannot access the form.
- A subagent read all code for syntax/authorization/XSS/SQLi and its findings were fixed. Measured: ~31 MB RSS per PHP process, ~1 ms per page locally.

## 11a. Dolibarr (local test instance, added 2026-10-21)
A local TEST Dolibarr 23.0.4 lives in `../dolibarr-local/` (outside this repo; see its README): DB `infynite_dolibarr`, port 8081, modules API/third parties/proposals/orders/invoices/products/stock/suppliers enabled, API key in `dolibarr-local/API_KEY.txt`. The portal's local `app/config.php` points `dolibarr.url/api_key/ui_url` at it. Verified: "Sync to Dolibarr" creates the third party (`llx_societe`) and a draft proposal with the right line and total (`llx_propal/propaldet`); a repeat sync is refused (idempotent). **Still to do:** real GST rate (`dolibarr.vat_rate` is 0), numbering, company profile, sales-order and invoice creation from the portal, supplier POs, Dolibarr users/permissions, and a hardened production install on Hostinger (official release package; PHP needs mysqli, gd, intl, curl, mbstring, xml, zip).

## 11b. Backup & restore tooling (added later the same day)
Built and locally tested: `tools/keygen.php`, `backup.php`, `pull.php`, `restore.php`, `test_roundtrip.php`, `tools/lib/*`, portal page `/staff/backups` (owner), maintenance mode via `storage/maintenance.flag`, seeded KB procedures "Restore drill (monthly)" and "Restore after data loss or corruption". Read `docs/BACKUP_OPERATIONS.md` (how-to, what is tested, limits) and `docs/BACKUP_RESTORE_PLAN.md` (design, decisions: two owners hold keys, Drive 10 GB + PC 100 GB, RPO 24 h, Dolibarr included). Keys are libsodium envelope encryption; the host holds only public keys.
Dolibarr (DB + documents + conf.php) is included and tested locally (BACKUP_OPERATIONS.md). Not done: host→PC transfer and PC→Google Drive via **rclone** (owner's decision; build later, not before Phase 0), cron on Hostinger (Phase 0).

## 12. Open items / roadmap (suggested order)
1. **Phase 0** hosting check on Hostinger (upload limits, DB count, private folder above web root, cron, mail/SPF/DKIM, backup options, quota includes what).
2. **Storage meter + alerts** at 70/80/90 % and cleanup of files of cancelled/old orders; lower default customer quota to ~200 MB (brief §5).
3. **Backups**: tooling exists (see 11b). Remaining: Drive adapter, host→PC transfer, schedule on Hostinger, add Dolibarr, run the pre-launch restore drill.
4. **Automated tests** (PHPUnit or scripted HTTP) covering the acceptance criteria in brief §8; CI lint (`php -l`).
5. **MFA** for staff (brief asks "if supported").
6. Dolibarr: install, secure, verify API payloads, add sales-order + invoice + purchase-order flow; decide the staff-tasks/inventory question (section 2).
7. Supplier purchase-order list in the portal if Dolibarr POs are not used (receiving should update stock).
8. Email: real SMTP/transactional setup, templates, status-notification preferences; SMS later.
9. Customer-facing: order status e-mails for all steps, invoice download (needs Dolibarr), gallery content, real contact details, reviewed legal pages.
10. Hardening: session invalidation on password change, separate columns for verify vs reset tokens, CSP tightening (move inline styles to CSS), log rotation, pagination for long lists (orders limited to 300, tasks 500, inventory 500).
11. Optional: rename URLs/tables to `orders`; "New enquiry" label for the first status.

## 13. Handy commands
```bash
find app public -name '*.php' -exec php -l {} \;      # lint (Windows PowerShell: Get-ChildItem -Recurse -Filter *.php app,public | % { php -l $_.FullName }) 
php -S localhost:8080 -t public public/index.php       # dev server
tail -f storage/logs/php-error.log storage/logs/mail.log
```
