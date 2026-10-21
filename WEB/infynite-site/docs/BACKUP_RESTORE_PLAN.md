# Backup & Restore Plan – Infynite Solutions platform

Status: **Core tooling BUILT and tested locally (see `BACKUP_OPERATIONS.md`); owner decisions recorded in §10. Google Drive adapter, Hostinger transfer method and Dolibarr inclusion still pending.** Date: 2026-10-21. Fulfils brief §5 ("daily off-site encrypted backups… must not rely solely on the same Hostinger account… test restoration before launch") and §8 ("Backups can be restored").
Items marked **[verify]** depend on Phase 0 findings about the real Hostinger Single account (cron, `exec`, SSH, outbound HTTPS, quotas) and must not be assumed.

## 1. Goals and targets
| Target | Proposal | Meaning |
|---|---|---|
| RPO (max data loss) | **24 h** for everything; optional 6 h DB-only dump later | Worst case we lose the last day of orders/tasks/stock changes. |
| RTO (time to restore) | **≤ 4 h** database + site; uploads may stream back afterwards | Site usable again the same working day. |
| Retention | 14 daily, 8 weekly, 12 monthly (off-site) | Lets us go back before a slow/unnoticed corruption. |
| Proof | Automated verification every night + **full restore drill monthly** | A backup that was never restored is not a backup. |

Owner must confirm RPO/RTO. A tighter RPO costs more (more frequent dumps) but the DB is small (tens of MB), so 6-hourly DB dumps are cheap if wanted.

## 2. What can go wrong (and which backup layer answers it)
| # | Scenario | Answered by |
|---|---|---|
| 1 | Database corrupted / bad migration / table dropped | Nightly DB dump → restore into staging, verify, swap in |
| 2 | Bad deploy / code broken | **Git** (tagged releases) → redeploy previous tag; no data restore needed |
| 3 | Someone deletes one order, task, article or file | Point-in-time dump restored into a *temporary* DB; copy back only the rows/files needed |
| 4 | Model files deleted/overwritten | Off-site uploads copy (immutable random-named files; versioned) |
| 5 | Site hacked / ransomware | Off-site copies the host cannot delete; rebuild clean host, restore **data only**, rotate all secrets |
| 6 | Hosting account suspended/lost | Full rebuild on a new host from off-site archive + git + secrets vault |
| 7 | Operator mistake while restoring | Mandatory **pre-restore snapshot** of the current state, restore into staging first |
| 8 | Backups silently stop or are unreadable | Dead-man alert + nightly verification + monthly drill |

## 3. What gets backed up
| Asset | Where it lives | Method | Notes |
|---|---|---|---|
| Portal database (`is_*`) | MySQL/MariaDB | Logical dump (SQL, gzip) | Orders, users, tasks, KB + versions, inventory, audit log |
| Dolibarr database (`llx_*`) | MySQL/MariaDB | Logical dump | Same DB or second DB; dump both |
| Customer model files | `storage/uploads/` (outside web root) | File copy, **incremental** | Files are immutable and random-named → only new files each run; keep a sha256 manifest |
| Dolibarr documents | Dolibarr `documents/` dir | File copy, incremental | Generated PDFs, attachments |
| Config + secrets | `app/config.php`, Dolibarr `conf.php`, cron entries, `.htaccess` | Included **only inside the encrypted archive** | Never in git, never uploaded unencrypted |
| Code | Git repository | Remote git (private) + tags per release | Not part of the data archive |
| Host settings | PHP version/ini, cron, DNS, mail SPF/DKIM, SSL | Written in `docs/` runbook | Needed to rebuild on a new host |
| Encryption key + credentials | Password manager | Separate custody (see §6) | Without the key, backups are unreadable |

**Consistency rule:** dump the **database first, then copy files**. Files copied after the dump may include a few orphans (harmless); the reverse order could leave database rows pointing at missing files.
**Not backed up:** `storage/logs/` (kept short), temp upload leftovers, caches.

## 4. Architecture – layered (3-2-1 rule)
```
 L1  hPanel/provider backups ........ convenience only, never trusted alone
 L2  On-host nightly archive ......... last 2 kept (fast restore, small: DB + manifest + config)
 L3  Off-site copy #1 (PULL) ......... owner's PC/NAS pulls every night; host cannot delete it
 L4  Off-site copy #2 (PUSH) ......... encrypted upload to cloud storage with a write-only/versioned credential
 L5  Monthly offline/immutable copy .. external disk or cloud bucket with object lock/versioning
```
Why both pull and push: if the host is hacked, an attacker can read the push credential and wipe that copy; a pull-based copy sits on a machine the host cannot reach. Keep at least one of the two that the host **cannot delete**.

### 4.1 Producing the archive on the host (cron, PHP)  **[verify cron, exec, outbound HTTPS, disk quota]**
Shared hosting may block `exec`/`mysqldump`, so the tool is a **pure-PHP CLI script** (`tools/backup.php`, run by cron or manually):
1. Take a lock; check free space (quota alert at 70/80/90 %, brief §5).
2. Dump each DB via PDO to a gzip SQL file (schema + data, row-chunked, `utf8mb4`, foreign key checks off in the dump, a trailing marker line so truncation is detectable).
3. Build a **manifest**: list of all files in `storage/uploads/` (+ Dolibarr documents) with size and sha256; compute the delta vs. the previous manifest (new/removed files).
4. Create the archive: DB dumps + config files + manifest + (delta files, first run = full). Files go in tar/zip chunks of ≤ 500 MB so uploads can resume.
5. **Encrypt with a public key** (libsodium sealed box / `age`-style) – the host holds only the public key; it cannot decrypt old backups, so a server compromise does not expose them. The private key lives off-host (§6).
6. Write a signed `status.json` (time, sizes, sha256 of each chunk, DB row counts, tool version).
7. Prune on-host copies (keep 2); never delete anything off-site from the host.
8. Send a one-line result to Google Chat/email (success or failure).

### 4.2 Getting it off the host
- **Pull (primary):** an owner-side script (Linux cron / Windows Task Scheduler, same PHP codebase) downloads new chunks + `status.json` over SFTP/SSH **[verify SSH/SFTP on Single]** or, if only HTTPS is available, over an authenticated, IP-restricted, single-purpose HTTPS endpoint with a long random token, rate limit and read-only behaviour (serves only finished encrypted chunks). Then verifies sha256 and stores them in dated folders.
- **Push (secondary):** upload the same encrypted chunks to cloud storage the owner already has (e.g. Google Drive via service account, Backblaze B2/S3-compatible). Use a credential that can only add files; enable bucket versioning/object lock where available.
- **Monthly:** copy the month-end set to an offline disk or lock-protected bucket.

### 4.3 Retention (GFS) off-site
14 dailies + 8 weeklies + 12 monthlies of **DB** archives (small). Uploads are stored once (incremental, never overwritten); deletions in the live system are kept in the backup for ≥ 90 days before the backup copy is purged, so an accidental mass-delete can still be undone.

### 4.4 Sizing (10 GB nominal hosting)
Do **not** keep full upload archives on the host. On host: ≤ 2 small DB archives + manifest (tens of MB). Off-site capacity needed ≈ size of `uploads` (≈ 5 GB in the brief's scenario) + DB history (< 1–2 GB/year) → a 20–50 GB destination is ample.

## 5. Restore methods (runbooks)
General rules for **every** restore:
1. Announce, then switch the site to **maintenance mode** (`storage/maintenance.flag` → portal returns 503 page).
2. Take a **pre-restore snapshot** of what exists now (DB dump + file listing) – even if it is corrupted – so the restore itself can be undone.
3. Restore into **staging first** (a separate DB name / folder), run the checks in §5.7, and only then swap into live.
4. Record time, operator, backup ID used and result in the incident note.

### 5.1 Database corrupted or wrongly changed
1. Pick the newest good dump (check `status.json` row counts vs. expectations).
2. Create `infynite_restore` DB; import the dump; run checks (§5.7).
3. Put live in maintenance mode, snapshot live DB, rename live DB → `infynite_old` (or import over after snapshot), promote the restored one (or import into live).
4. Re-apply the few changes made since the dump if needed (audit log + task/stock/status history show what happened; owner decides).
5. Leave maintenance mode; watch `php-error.log`.

### 5.2 Bad deploy / broken code
Redeploy the previous git tag; clear nothing else. No data restore. Always take a DB dump immediately before any migration (the deploy script does this).

### 5.3 Restore a single record or file (accidental delete)
Import the chosen dump into a **temporary** DB, copy only the needed rows (SQL `INSERT … SELECT`), or extract just the needed chunk/file from the uploads archive using the manifest. Do not overwrite live data wholesale.

### 5.4 Uploads lost or corrupted
Use the manifest to list missing/mismatched files (sha256), fetch only those chunks from off-site, decrypt, verify sha256, copy into `storage/uploads/` with mode 0640. Customers can then download again with no DB change.

### 5.5 Full rebuild on a new host (disaster)
1. Provision host/PHP/DB (host notes in `docs/`); deploy the git tag.
2. Recreate `app/config.php` from the encrypted archive copy (rotate DB password and `setup_token`).
3. Restore the DB dump(s), then the uploads/documents; run §5.7 checks.
4. Point DNS / re-issue TLS; verify mail (SPF/DKIM).
5. Rotate every secret that lived on the old host (DB, API keys, Dolibarr key, session secrets) and **force all users to reset passwords** if compromise is possible.

### 5.6 After a compromise
Do **not** restore code from the host or the latest backup blindly. Rebuild code from git, restore **data only**, change all passwords/keys, review the audit log, and restore from a backup dated **before** the first sign of intrusion if data may be tampered with.

### 5.7 Post-restore verification checklist
- [ ] Row counts per table match `status.json` (± documented changes)
- [ ] Owner logs in; a customer logs in; a staff role logs in
- [ ] Open a recent order, download its model file (sha256 equals manifest)
- [ ] Create a test task/comment and a stock movement, then delete/reverse it
- [ ] Customer A still cannot open customer B's order (authorization intact)
- [ ] `php-error.log` clean; cron jobs running; next backup runs successfully

## 6. Keys, secrets and custody

### 6.1 Design (envelope encryption with libsodium – built into PHP 8, no extra install)
- Each backup run creates a **random one-time data key**; the archive is encrypted in chunks with it (XChaCha20-Poly1305 "secretstream", so chunks are authenticated and a truncated or tampered file fails to decrypt).
- The data key is then **wrapped (sealed) with the public key(s)** of the recipients (`crypto_box_seal`). The wrapped keys + key IDs go in the archive header.
- Consequences: the host only ever holds **public** keys, so it can create backups but can never read any; several people can each hold their own private key (a second recipient costs only a few bytes per archive); keys can be rotated without re-encrypting old backups.

### 6.2 Keys
| Key | Who/where | Purpose |
|---|---|---|
| **Backup key #1 (owner)** | Generated on the owner's PC with `tools/keygen.php`; private key stored as a passphrase-protected file (Argon2id) | Decrypt/restore day to day |
| **Backup key #2 (deputy / recovery)** | Generated by a second trusted person on their own device | Recovery if the owner is unavailable or loses key #1 |
| Public keys of #1 and #2 | In `app/config.php` on the host (not secret) | Encrypt every archive to both |
| Passphrases | Password manager (owner) / second person's own manager | Never stored next to the key file |
| Drive/pull/Chat credentials | Host config + password manager | Transport only, they cannot decrypt anything |

### 6.3 Where the private key lives (the "lost key = lost backups" risk)
1. **Password manager** (key file text + passphrase as separate entries).
2. **Sealed paper copy** (key text + QR, passphrase written separately) in a safe or with a trusted person.
3. **Offline USB stick** kept off the PC.
4. The **second recipient** (deputy) holds an independent key – even if all copies of #1 are lost, #2 can decrypt everything.
Never: in git, in the repo, on the host, in Google Drive next to the backups, or in chat.

### 6.4 Lifecycle
- **Generate** once before launch; print/store; **test decrypt** a sample archive immediately.
- **Fingerprint** of each key is shown in the Backups page and in every `status.json`, so a drill can confirm the right key is in use.
- **Rotate** yearly or after any suspected exposure: generate a new key, add its public key to config, keep old private keys forever (needed for old archives; offline). Old archives can optionally be re-wrapped to the new key (only the small header changes).
- **Offboarding:** if the deputy leaves, generate a new key and stop encrypting to the old one; archives already made stay readable by the old key, so also treat any stolen *private* key as exposure of the archives made while it was a recipient.
- **Every drill** (§8) must decrypt with a key taken from its stored location, not from the PC's working copy.
- Secrets that stay on the host (DB password, Dolibarr API key, Drive token) are rotated after any host incident (§5.5/5.6) and are *not* sufficient to read backups.

## 7. Monitoring and alerts
- Backup result message after each run (success/fail, sizes, duration).
- **Dead-man's switch:** the off-site side expects a new `status.json` every day; if none arrives in 26 h, alert the owner.
- Owner-only portal page "Backups": last successful run, age, size, off-site confirmation, quota usage with the 70/80/90 % warnings.
- Verification each night: decrypt the newest chunk header, check sha256, gunzip test, SQL end-marker present, row counts sane (not zero, not a >50 % drop vs yesterday).

## 8. Testing and drills (acceptance criteria)
| When | What | Pass criteria |
|---|---|---|
| Before launch (brief §8) | **Full restore drill** into a clean staging environment from off-site copy only | §5.7 passes; elapsed time recorded and ≤ RTO |
| Monthly | Automated restore of the newest DB archive into a scratch DB + row-count check | Green report in Google Chat |
| Quarterly | Manual single-record and single-file restore | Owner signs off |
| Yearly / after hosting change | Full disaster rebuild on a spare host (can be a Windows/Linux PC) | Site runs from scratch |
Results are logged in a `docs/restore-drills.md` table (date, backup ID, operator, time, issues).

## 9. What I propose to build (after your answers)
1. `tools/backup.php` – DB dump (PDO), manifest/incremental uploads, chunking, public-key encryption, status file, pruning, alerts. CLI; runs on Linux and Windows.
2. `tools/restore.php` – list backups, verify, decrypt, restore into a named staging DB/folder, run the §5.7 checks automatically, then an explicit `--promote` step with pre-restore snapshot and maintenance mode.
3. `tools/pull.php` (owner PC) – fetch new chunks, verify, rotate folders (Task Scheduler / cron).
4. Maintenance-mode flag support in `public/index.php`, optional protected download endpoint if SFTP is unavailable.
5. Owner-only "Backups" status page + storage meter (also closes the storage-alert item from the roadmap).
6. Runbook copies in the Knowledge base (procedures: "Run a restore drill", "Restore after incident") and recurring tasks (monthly drill, key rotation) so staff are reminded inside the portal.
7. Automated tests for backup→restore round trip (including corrupted-archive and truncated-dump cases).

## 10. Decisions
### Confirmed by the owner (2026-10-21)
- **Off-site #1 (push): Google Drive, ~10 GB.** **Off-site #2 (pull): owner's PC, ~100 GB.**
- **RPO: 24 hours accepted** → one nightly run is enough (no 6-hourly dumps needed).
- **Dolibarr database (and documents) are included** in the same job.
- **Key holders: the two owners** (each generates their own key pair on their own device; every archive is encrypted to both). Neither shares a private key or passphrase with the other.
- **Transfer method decided: rclone** (installed on the dev PC): the owner's PC pulls from Hostinger (SFTP/FTPS, to be confirmed in Phase 0) and uploads to Google Drive. To be built later, not before Phase 0.
- **Google Drive details can wait:** the backup tool will use a pluggable destination (local folder / PC-pull first, Drive adapter added when the account is chosen).

### Consequences of those choices
- **Drive 10 GB is tight:** uploads ≈ 5 GB (brief scenario) + DB archives (14 daily + 8 weekly + 12 monthly, compressed, roughly 1–2 GB/year) leaves little growth room. Plan: **the PC holds everything** (full uploads history, all DB archives, 100 GB is plenty); **Drive holds the DB/config archives and as many uploads as fit**, with a hard cap and alerts at 70/80/90 %. If uploads outgrow Drive, Drive keeps only DB+config+manifest and the PC remains the full copy (or upgrade Drive/choose B2 later).
- **Drive cannot be made delete-protected** for a credential that can add files; its trash/version history gives ~30 days of grace only. That is why the PC pull copy is the one the host can never touch.
- Drive access via a dedicated service account or OAuth client limited to one folder (`drive.file` scope), not the owner's full Drive.
- Dolibarr backup needs its own DB credentials in the backup config and its `documents/` directory path **[verify after Dolibarr install, Phase 2]**.

### Still open
1. ~~Second key holder~~ → the second owner (decided).
2. **Which Google account/Workspace** owns the 10 GB Drive folder (can be decided later; not needed to build).
3. **Phase 0 facts** on Hostinger: cron availability/min interval, `exec`/`mysqldump` allowed or not, SSH/SFTP, outbound HTTPS to Google, DB count, whether the 10 GB quota includes DB/backups, built-in backup frequency/retention.
