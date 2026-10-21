# Infynite portal change log and current handover

Updated: 2026-10-22

## Scope and status

The portal has been installed at the temporary Hostinger URL `https://pink-viper-143770.hostingersite.com/` for testing. This is not the production domain and does not constitute launch approval. This document records the work performed during local Windows setup/testing and the Hostinger test deployment, plus the current live check.

## Changes made

### Local Windows setup and verification

- Installed PHP 8.5.11 and MariaDB 13.0.2 on the Windows test machine; configured a development database and imported `sql/schema.sql`.
- Ran PHP syntax checks; the end-to-end smoke suite completed with 62 passed and 0 failed.
- Ran the backup/restore roundtrip suite: 21 checks passed, 0 failed; the read-only destination outage scenario was skipped because the Windows/root test context could not provide a read-only folder.
- Fixed `tools/smoke_test.php` so the built-in PHP child server inherits relevant CLI extensions (including PDO MySQL and mbstring). This fixed the earlier child-server test failures.

### Application routing and hosting compatibility

- Added base-path handling in `app/controllers/site.php`, so requests mounted below the domain root (for example, a staging subfolder) are normalized before route matching.
- Updated `app/lib/core.php` redirects to retain the configured subfolder mount path.
- Updated `public/index.php` to resolve the private app either beside the document root or one directory above it, use the matching private storage path, and rewrite root-relative HTML links/assets/forms when mounted in a subfolder.
- These are routing/bootstrap compatibility changes; no visual CSS redesign was part of this deployment work.

### Hostinger test deployment and rollback preservation

- Reorganized the account's public root so `public_html/` contains only `assets/`, `.htaccess`, and `index.php`.
- Put `app/` and `storage/` above `public_html/`; the front controller loads the app from there. This keeps configuration, uploads, and logs outside the served directory.
- Copied the staging app/config to this layout and changed the configured base URL to the temporary domain root. No credential values are recorded here.
- Moved the previous `infynite-test/` staging copy above the document root for rollback. Downloaded the old root `index.html` and retained it above the web root as `index.html.hostinger-backup-20261021`. The old CSS and deployment package/archive were also moved out of `public_html/`.
- Kept the existing test database, DB account, Owner account, password hashes, and business/test data. No schema migration, account deletion, or password reset was performed.

### Dolibarr decision and cancelled Hostinger test install (2026-10-22)

- Confirmed Dolibarr is optional and not required for the current portal's customer/order/staff workflow or basic inventory. Consider it later only for identified needs such as supplier purchasing, accounting/invoicing, or warehouse management.
- The owner removed the empty `public_html/erp/` directory before the Dolibarr archive was installed. No Dolibarr application files or application tables were added to Hostinger. The isolated Dolibarr database was created separately but left unused; the existing portal database/users/data were not changed by this attempt.
- The portal integration is limited to server-side customer/proposal sync and does not make all Dolibarr ERP screens/features part of the portal. Define ownership of shared data before any future integration.
- Hosting note for this deployment: PHP websites and scheduled PHP cron jobs are supported. The portal is request-based and does not need a continuously running PHP daemon/background worker; scheduled jobs must stay within the plan's resource and cron-job limits.
- Sodium extension status: currently missing from the Hostinger PHP setup (per the hosting check). The Infynite encrypted backup/restore toolkit depends on Sodium; Dolibarr core does not. Enable and verify Sodium before relying on encrypted backups.

## Current verification

- Live homepage at the temporary URL renders with the Infynite 3D-printing content and navigation.
- Live authenticated `/staff/tasks` page renders, with staff navigation, task filters, and existing task records. The account is recognized as Owner. No live edits were made during this check.
- Earlier browser verification also covered `/services` and `/staff`.
- These are focused functional checks only, not a complete production-readiness test.

## Security and remaining gates

- The private database password and setup token were exposed in Hostinger File Manager accessibility output during config editing. Rotate both through the hosting panel/config, then verify database connectivity and that setup is no longer available. Do not paste the values into chat, email, or project notes.
- Preserve rollback copies until a separate recoverable backup has been confirmed.
- Still to verify before production: real-domain routing and HTTPS/cookies; Hostinger PHP version/extensions and configured upload limits; real STL/3MF upload/download; outgoing email delivery; scheduled and off-site encrypted backups plus a restore drill; mobile/security/privacy review; final owner approval.
- Dolibarr is not part of this deployment and has not been installed on Hostinger.

## Repository and commit note

The Dolibarr/Hostinger notes in this update and the companion changes in `README.md`, `SCRATCHPAD.md`, and `docs/HANDOVER.md` are documentation-only. They were committed on `first-live-test` as `db72ecc`, `dcfa274`, and `73f60ab`. No application code, UI, portal database, or portal user data was changed for this decision. Unrelated credentials, deployment archives, logs, and generated test artifacts remain outside those commits.
