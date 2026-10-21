# Phase 0 – Hostinger Single compatibility checklist

Purpose (brief §1, §6, "First task for the AI agent"): record **evidence** of what the real Hostinger Single account allows **before** any production install. Nothing here may be assumed; every cell below is to be filled from the real account.
Rules: do not upgrade the plan or buy anything without owner approval; do not share passwords, API keys or the webhook URL in chat/screenshots; do not deploy publicly until the go/no-go at the end is "GO".
Owner = person with hPanel access. Agent = the AI agent that will read the report and decide the next steps.

Menu names in hPanel change over time; the "Where" column is a hint, not a promise.

## 0. How to run it (about 45 minutes)
1. Log in to hPanel (owner). Work through section A by hand (copy values into the report, §F).
2. Run the **probe script** on the real account (section B). It reports PHP facts, folder permissions, outbound network and DB facts without printing secrets.
3. Test cron + e-mail + upload (section C, D).
4. Fill the report (§F), then give the **report text** and screenshots (secrets blurred) to the agent.

## A. hPanel facts to read (owner)
| # | Item | Where (hint) | Record | Why it matters / pass criteria |
|---|---|---|---|---|
| A1 | Plan name, renewal date | Billing / Hosting overview | | Brief says "Single"; confirm nothing else is attached |
| A2 | **Disk quota** shown for the account (e.g. 10 GB) and current usage | Hosting overview / Resource usage | | Plan around it; also see A3 |
| A3 | Does the quota **include** databases, e-mail mailboxes, backups, temp files? | Plan details / support chat (ask in writing and keep the answer) | | Decides how much room uploads really have (brief §5) |
| A4 | **Inodes / file-count limit** | Resource usage / plan details | | 250 models + Dolibarr + documents must fit |
| A5 | **Number of MySQL/MariaDB databases** allowed, DB size limit | Databases > Management | | ≥2 → separate portal and Dolibarr DBs; 1 → single DB (tables are already prefixed `is_` / `llx_`) |
| A6 | DB engine/version (MariaDB or MySQL + version), remote access allowed? | Databases, phpMyAdmin footer | | Needs InnoDB, utf8mb4; remote access NOT required |
| A7 | **PHP versions** selectable; current; can it be set **per domain/folder**? | Advanced > PHP Configuration | | Need 8.1+ (8.2/8.3 preferred), version Dolibarr 23/24 supports |
| A8 | PHP options page: values of `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, `max_input_vars`, `max_file_uploads` and which are editable | Advanced > PHP Configuration | | Target ≥ 32M / 40M / 256M / 120 s. Do not promise uploads above what you can set (brief §5) |
| A9 | PHP extensions list (tick/untick) | PHP Configuration > Extensions | | Required: pdo_mysql, mysqli, mbstring, zip, curl, gd, intl, xml, openssl, sodium, zlib, json, ctype (see probe) |
| A10 | **Cron Jobs** available? minimum interval? can it run `php` CLI? | Advanced > Cron Jobs | | Needed for nightly backup (can be a PC-side trigger if not) |
| A11 | **SSH** access? **SFTP/FTPS**? (host, port, create a **separate limited FTP/SSH account** for backups) | Advanced > SSH Access / Files > FTP Accounts | | Decides how the PC pulls backups with rclone (brief: rclone chosen) |
| A12 | **Document root** layout: path of `public_html`, can a folder **above it** be used (e.g. `/home/uXXXX/domains/<domain>/`)? Subdomains allowed and their roots? | File Manager / Websites | | Private uploads must live ABOVE the public folder (brief §2). Subdomain for Dolibarr (`erp.`) preferred |
| A13 | SSL: certificate active? **Force HTTPS** switch? HTTP/2? | Security > SSL | | Required before launch (brief §4) |
| A14 | **Backups offered by the host** (frequency, retention, how to restore/download) | Files/Backups | | Convenience only, never the sole backup |
| A15 | **Resource limits** (CPU %, RAM, processes, entry processes) as documented/visible | Resource usage / plan page | | Do not assume 1 core/1 GB; read them |
| A16 | E-mail: can create a mailbox for `no-reply@<domain>`? SMTP host/port/ encryption for sending; SPF/DKIM/DMARC status | Emails / Domains > DNS | | Verification and reset links must arrive in inboxes, not spam |
| A17 | DNS: where is DNS managed? ability to add records (A, CNAME, TXT) | Domains > DNS Zone | | Subdomains + mail records |
| A18 | WAF / bot protection / "LiteSpeed cache" settings that could break POST forms or uploads | Security / Performance | | Note anything that blocks `multipart/form-data` or large POSTs |
| A19 | Is `.htaccess` honoured (LiteSpeed/Apache)? | Test in §C or probe output | | The portal's front controller needs rewrite rules |

## B. Probe script (owner + agent)
File: `tools/phase0_probe.php` (no secrets printed; read its header comment).
1. **Web mode** (reflects the PHP the site will use): copy to `public_html/phase0-probe.php`, edit `$PROBE_TOKEN` to a 20+ character random string, open `https://<domain>/phase0-probe.php?token=<token>`, save the full text output, then **delete the file from the server**.
2. **Cron/CLI mode** (reflects what cron/SSH use): create a cron job every minute for ~5 minutes: `php /home/<user>/path/phase0_probe.php --cron-log=/home/<user>/probe-cron.log` (place the script **outside** public_html). Afterwards read `probe-cron.log`: it shows cron works, the real minimum interval and the PHP version cron uses. Remove the cron job.
3. **DB test** (optional, CLI via SSH if available): `PROBE_DB_HOST=... PROBE_DB_NAME=... PROBE_DB_USER=... PROBE_DB_PASS=... php phase0_probe.php` (set the variables only for that command).
4. **Mail test**: `php phase0_probe.php --mail-to=<your address>`; check inbox **and spam**.
What the probe decides automatically: PHP ≥ 8.1, required extensions, whether files can be created above the document root, upload limits warning, outbound HTTPS to Google/GitHub, `exec` availability, disk numbers, DB version/limits.

## C. Hands-on tests (owner)
| # | Test | How | Pass criteria |
|---|---|---|---|
| C1 | `.htaccess` rewrite works | Upload `public/` of the portal to a **test subfolder/subdomain**, open `/services` (no `index.php`) | Page loads (not 404) |
| C2 | Folder above web root is usable | Probe §Filesystem | "can create files in parent of document root: yes" |
| C3 | **Real upload limit** | After portal install on staging: upload a 5 MB, 20 MB and 25 MB valid STL | All accepted; if not, record the exact error |
| C4 | 3MF handling | Upload the 3MF sample (zip) | Accepted; a crafted bad zip rejected |
| C5 | Cron fires | Probe cron log (B2) | New line each interval |
| C6 | Mail deliverability | Register a test customer with a Gmail/Outlook address | Verification mail in **inbox** (not spam) within 2 minutes |
| C7 | Outbound to Google | Probe network section | `www.googleapis.com` HTTP 200/404 (reachable) – only matters if host→Drive upload is ever wanted |
| C8 | Restore from host backup | Try hPanel's restore/download of a backup to see what it contains | Know how to get a copy; note retention |
| C9 | Load sanity | Open 10 pages quickly / run `php tools/smoke_test.php`-like manual clicks | No 5xx, no "resource limit" pages |

## D. Quotas and sizing worksheet
| Item | Value | Notes |
|---|---|---|
| Account quota | | from A2/A3 |
| Dolibarr install (code) | ~150–300 MB | measure after install |
| Dolibarr documents (grows) | | |
| Databases (portal + Dolibarr) | | measure in hPanel |
| Planned model library | 250 × 20 MB ≈ 5 GB | brief §5 |
| Customer upload headroom | quota − all above − 20 % safety | set `upload.quota_bytes` accordingly (default 500 MB per customer is a **proposal**; consider 100–200 MB) |
| Backups kept on host | ≤ 2 small runs (tens of MB) | uploads are NOT archived on the host |
Alert thresholds (brief §5): 70 %, 80 %, 90 % of quota (shown on the portal's Backups page when `backup.disk_quota_bytes` is set to the real quota).

## E. Decision table (agent applies after the report)
| Finding | Consequence / branch |
|---|---|
| PHP < 8.1 selectable | **NO-GO**: Dolibarr 23/24 and the portal need 8.1+. Ask owner (plan limits) |
| Cannot write above the public folder | **STOP uploads** (brief §2). Propose: password-protected folder with `.htaccess` deny + random names (weaker), or move uploads off-host; needs owner approval |
| Only 1 database | Use one DB for both apps (prefixes already separate them) – restore/backups work (the tool dumps a shared DB once) |
| No cron | Backup trigger from the owner PC via an authenticated HTTPS endpoint (to be built) or an external cron service (approval needed) |
| No SSH/SFTP/FTPS | HTTPS download endpoint for backups (to be built); manual hPanel download as last resort |
| `exec` disabled | No issue (tools are pure PHP); rclone only runs on the PC |
| Upload limits fixed below 25 MB | Lower `upload.max_bytes` to the real limit; tell customers; plan an upgrade proposal (no VPS dependency in MVP) |
| Quota includes backups/DB/mail | Reduce customer quota; keep zero archives on host (already default) |
| Missing PHP ext (gd/intl/sodium/zip/mysqli) | Enable in hPanel; if impossible → NO-GO for that component (Dolibarr needs gd/intl/mysqli; backups need sodium; uploads need zip) |
| No outbound HTTPS | Fine for MVP (nothing needs it). Host→Drive push impossible; PC-pull+rclone plan unaffected |
| Mail goes to spam | Fix SPF/DKIM/DMARC; use the mailbox SMTP (code uses PHP `mail()`; SMTP support would be a small addition) |

## F. Report template (owner fills, gives to the agent)
```
PHASE 0 REPORT   date: ____   by: ____
Plan: ____  Quota: ____ (includes DB/mail/backups? ____)  Inodes: ____
Databases allowed: ____  Engine/version: ____
PHP selectable: ____  chosen: ____  per-folder? ____
PHP limits (upload/post/memory/exec/input_vars): ____ / ____ / ____ / ____ / ____  editable? ____
Extensions missing: ____
Cron: yes/no, min interval ____, CLI PHP version ____
SSH: yes/no  SFTP/FTPS: yes/no  separate limited account possible: yes/no
Folder above public_html writable: yes/no   Subdomains: yes/no
SSL + force HTTPS: ____
Host backups: ____ (frequency/retention/restore)
Mail: mailbox created yes/no; test mail inbox/spam; SPF/DKIM/DMARC ____
Outbound HTTPS from PHP: ____
Probe output (web): attached     Probe output (cron): attached
Upload tests 5/20/25 MB: ____
GO / NO-GO recommendation + open issues: ____
```
Attach only non-secret screenshots (blur usernames/hosts if you prefer; never passwords, tokens or the Google Chat webhook).

## G. After Phase 0
- If **GO**: agent updates `docs/HANDOVER.md` (section "Hosting facts"), sets real limits in `app/config.example.php` comments, then proceeds to **Phase 1** (repo/staging/rollback), **Phase 2** (Dolibarr install on a subdomain), then the backup schedule + rclone transfer (`BACKUP_RESTORE_PLAN.md`).
- If **NO-GO**: agent writes a short upgrade/alternative proposal for the owner (no purchases without approval).
