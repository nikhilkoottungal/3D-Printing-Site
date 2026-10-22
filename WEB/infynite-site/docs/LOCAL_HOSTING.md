# Local (office LAN) hosting - decision of 2026-10-22
The Hostinger plan is dropped. The system runs on an office machine and is reachable **only on the office LAN**. It is a staff-only internal system: no public site, no customer area.

## What changed
- **Staff-only by design (decision 2026-10-22):** there is no public website and no customer self-service portal; that code has been removed. Customers exist only as records entered by staff and can never log in (login and password reset ignore them). `/` redirects to the login page; the only routes are login, password reset, first-time setup, file download for staff, and `/staff/...`. Quotes are agreed with customers by phone or e-mail and recorded by staff; the portal sends no customer e-mail.
- Subfolder/base-path support stays (harmless at a root install).
- `deploy/install-lan.sh` installs `deploy/infynite-portal.service` as a **user** systemd service on `0.0.0.0:8080` (no root needed). `base_url` is the machine's LAN address (currently `http://10.0.0.44:8080`; give the machine a fixed IP or DHCP reservation, and update `base_url` if it changes).
- MariaDB listens on 127.0.0.1 only. Dolibarr (test instance) stays on localhost:8081, admin from this machine only.

## Still to do (needs the owner / admin rights)
1. `sudo loginctl enable-linger $USER` so the service starts at boot without a login.
2. Firewall: allow 8080 from the office subnet only (`sudo ufw allow from 10.0.0.0/24 to any port 8080`, then enable ufw).
3. Backups (`deploy/infynite-backup.*`): both owners run `tools/keygen.php`, put the PUBLIC keys and a destination folder (external drive/NAS) in `app/config.php` `backup`, then `systemctl --user enable --now infynite-backup.timer`. Do a restore drill. No external drive is attached now, so the timer is NOT enabled.
4. Plain HTTP on a LAN means passwords cross the network unencrypted. Before relying on it, put nginx/Caddy with a local TLS certificate in front (needs sudo), then set `force_https`.
5. Dolibarr as production ERP: install the official release, configure company/GST/numbering, dedicated API user; replace the test credentials in `dolibarr-local/`.
6. `php -S` is fine for ~4 users but single-purpose; move to nginx + PHP-FPM when sudo is available.
7. Rotate the dev credentials/API keys; create the real Owner at `/setup` on a fresh DB (current DB holds demo data).

## Admin settings page (added 2026-10-22)
`/staff/admin` (Owner only, sidebar "Admin settings") holds the business configuration: company name/address/contact, GSTIN, PAN, default GST rate, state, invoice prefix/next number/payment terms, bank details, invoice footer. Values live in table `is_settings` (created automatically; also in `sql/schema.sql`); read them in code with `setting('key', default)`.
It is protected by a second secret, the **admin key**: first visit asks the owner to create it (12+ characters, stored as a hash only); afterwards it must be entered to unlock, the unlock expires after 15 minutes idle, wrong attempts are rate-limited (5 per 15 min) and audited. Lost key: `php tools/reset_admin_key.php` on the server, then set a new one. To add a setting, add a line to `SETTING_GROUPS` in `app/lib/settings.php`.
Dolibarr is no longer part of the plan; `dolibarr` in `app/config.php` is blank (integration disabled).

## Everything built after this note is described in `docs/FEATURES.md`.

## Modules added 2026-10-22 (all in the portal, no Dolibarr)
- **Invoices** (`/staff/invoices`; Owner, Sales): numbered from admin settings, CGST+SGST or IGST, printable, payments, void.
- **Customers** (`/staff/customers`; view: all staff, edit: Owner/Sales): contact, address, GSTIN, state, order and invoice history; feeds invoice prefill.
- **Suppliers & purchase orders** (`/staff/suppliers`, `/staff/purchase-orders`; Owner, Purchasing): PO numbers `PO-0001...` (prefix/next in admin settings); "Mark received" adds stock to linked inventory items, updates unit cost and writes a stock move.
- **Reports** (`/staff/reports`; Owner, Sales): invoiced/collected/unpaid, GST split, by month, top customers, ageing, orders by status, stock value, open POs; CSV of invoices for the accountant.
- Existing databases are upgraded automatically on first request (`app/lib/migrate.php`, version in `is_settings.schema_v`; bump `SCHEMA_VERSION` when changing `sql/schema.sql`).
- Tests: `php tools/smoke_test.php` (101 checks) and `php tools/test_roundtrip.php` (22).
