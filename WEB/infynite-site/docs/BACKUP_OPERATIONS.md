# Backup & restore – operations guide (what is built)

Design and reasoning: `BACKUP_RESTORE_PLAN.md`. This file is the **how-to** for the tools in `tools/`.
All tools are CLI PHP (Linux and Windows), need `sodium`, `zip`, `pdo_mysql`, `zlib` (all present in a standard PHP 8 build).

## What exists
| Tool | Runs where | Purpose |
|---|---|---|
| `tools/keygen.php` | each **owner's own PC** | Creates the owner's key pair. Private key file is passphrase-protected (Argon2id). Prints the PUBLIC key. |
| `tools/backup.php` | the **host** (cron) | Dumps DB(s) in one consistent snapshot, hashes files, archives **new/changed** files only, encrypts to all owners' public keys, ships to destinations (sha256-verified), keeps only a few small runs locally. Writes `storage/backup/status.json`. |
| `tools/pull.php` | the **owner PC** (Task Scheduler / cron) | Copies new runs from a source folder, verifies every file by sha256, **dead-man alert** if no backup in 26 h, optional `--prune` (GFS 14 daily / 8 weekly / 12 monthly; only the PC may prune). No private key needed. |
| `tools/restore.php` | a trusted PC (needs private key) | `list`, `verify`, staging `restore`, and guarded `--live` restore (pre-restore snapshot + maintenance page + checks). |
| `tools/test_roundtrip.php` | dev | 22 automated checks (crypto, tamper/truncation, DB round trip, incremental, outage recovery, retention). |
| Portal **Backups** page (`/staff/backups`, owner only) | portal | Last run age, result, backlog, key IDs, hosting storage meter (alerts at 70/80/90 %). |
| Maintenance page | `public/index.php` | If `storage/maintenance.flag` exists every request gets a 503 "back soon" page. |

Backup folder layout (same on host, off-site folder and PC copy): `parts/<run>-files-NNN.bak` (encrypted file chunks, shared across runs), `runs/<run>/core.bak` (encrypted: DB dumps + file manifest + config copies) and `runs/<run>/meta.json` (plain: counts and hashes only, written last = commit marker).

## One-time setup
1. **Each owner**, on their own PC: `php tools/keygen.php --label="Owner 1" --out=owner1.key` (prompts for a 12+ character passphrase). Store the key file and passphrase **separately** in the password manager, print both on paper (sealed, different places), copy the key to an offline USB. Never put it on the host, in git or in Drive.
2. Put **both public keys** in `app/config.php`:
   ```php
   'backup' => [
       'recipients'   => ['<owner1 public key>', '<owner2 public key>'],
       'destinations' => [['type' => 'folder', 'path' => '/path/to/offsite']],   // Google Drive adapter: later
       'notify_cmd'   => null,   // optional: command that gets the result message as its single argument
       // when Dolibarr exists, add to 'databases': ['name'=>'dolibarr','host'=>..,'port'=>3306,'db'=>..,'user'=>..,'pass'=>..]
       //   to 'paths': ['name'=>'dolibarr-docs','path'=>'/path/to/dolibarr/documents']
       //   to 'config_files': '/path/to/dolibarr/conf/conf.php'
   ],
   ```
3. Test: `php tools/backup.php`, then `php tools/restore.php verify --src=<destination folder> --key=owner1.key` and again with owner 2's key.
4. Schedule (host): daily at 02:30 `php /path/to/tools/backup.php` (cron; Hostinger hPanel → Cron Jobs **[verify availability]**).
5. Schedule (owner PC, 04:30): `php tools/pull.php --from=<source> --to=<pc folder> --prune`. Windows: Task Scheduler → action `php.exe`, arguments `C:\path\tools\pull.php --from=... --to=... --prune`. A non-zero exit code (1 problem, 2 stale) should trigger an alert/email.
6. Do the **restore drill** (KB procedure "Restore drill (monthly)") before launch and then monthly.

## Typical commands
```bash
php tools/restore.php list    --src=/backups
php tools/restore.php verify  --src=/backups --key=owner1.key [--run=ID]
# staging (safe): restores into a separate scratch DB and folder, compares row counts + sha256
php tools/restore.php restore --src=/backups --key=owner1.key --db=portal=infynite_drill --files-to=/tmp/drill
# live (overwrites!): needs --yes AND --confirm=<the run id>; takes a pre-restore snapshot first
php tools/restore.php restore --key=owner1.key --src=/backups --run=ID --live --yes --confirm=ID
```
Passphrase is prompted; set env `BACKUP_PASSPHRASE` for unattended runs (not recommended for the real keys).

## Restore a single thing
- One order/row: staging-restore into a scratch DB, then copy the rows with SQL.
- One file: staging-restore with `--files-to`, copy the file (its path is `uploads/<random name>`; DB row `is_request_files.stored_name` tells which).

## What was tested (local, 2026-10-21)
- 22 automated checks pass (`php tools/test_roundtrip.php`), including identical table checksums after restore for awkward data (quotes, newlines, binary, NULL, emoji, foreign keys), bit-flip / truncation / chunk-cut detection, wrong passphrase / non-recipient key, both owners' keys, incremental runs, destination outage + automatic catch-up, retention rules, truncated-dump refusal.
- Disaster test: dropped a table, wiped audit log, corrupted users, truncated a table and deleted an upload on a throw-away site → live restore returned **byte-identical checksums** and the file; maintenance page lifted; pre-restore snapshot kept.
- Portal Backups page loads for the owner and is 403 for other roles.

## Dolibarr included (tested 2026-10-21 against a real local Dolibarr 23.0.4)
Config to add to `backup` in `app/config.php` once Dolibarr is installed on the real host (adjust names/paths):
```php
'databases' => [
    ['name'=>'portal',   'host'=>'localhost','port'=>3306,'db'=>'<portal db>',   'user'=>'..','pass'=>'..'],
    ['name'=>'dolibarr', 'host'=>'localhost','port'=>3306,'db'=>'<dolibarr db>', 'user'=>'..','pass'=>'..'],
],
'paths' => [
    ['name'=>'uploads',       'path'=>'<storage>/uploads'],
    ['name'=>'dolibarr-docs', 'path'=>'<dolibarr documents dir>'],
],
'config_files' => ['<portal>/app/config.php', '<dolibarr>/htdocs/conf/conf.php'],
```
If both apps share one database the tool dumps it only once. Results: portal (12 tables) + Dolibarr (280 tables, 4,474 rows) + 26 files verified with owner 2's key; staging restore gave **identical checksums for all 292 tables**; after deleting customers/proposals, dropping `llx_const`, corrupting users and deleting a document, a `--live` restore returned the Dolibarr tables to their pre-disaster checksums and restored the file; a second Dolibarr started from the restored DB + documents answered the REST API with the proposal and customer intact. If Dolibarr custom modules are added later (`htdocs/custom`), keep them in git or add a `paths` entry.

## Known limits / next steps
- Only `folder` destinations are implemented. **Google Drive adapter not built yet** (needs the Google account/folder and API credentials; the tool throws a clear error if a `drive` destination is configured).
- Hostinger Phase 0 unknowns: cron, `exec`, outbound HTTPS, SFTP/SSH, whether the 10 GB quota includes backups. Moving files host → PC may need SFTP or a small authenticated download endpoint (not built).
- On the real host, repeat the Dolibarr backup/restore test once it is installed there (paths and DB names differ).
- DB dump is a logical dump of base tables (no views/triggers/routines/events; none are used by the portal; Dolibarr uses few – check at Phase 2). Generated columns are not handled.
- Memory: files are streamed in 1 MB chunks, DB rows in batches of 200; part size configurable (`chunk_mb`, default 500 MB).
- Windows: untested but uses only portable PHP (paths normalised, no shell calls except optional `stty`/notify).
