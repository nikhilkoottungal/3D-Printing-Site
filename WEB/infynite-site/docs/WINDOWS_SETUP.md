# Windows setup – a local copy that mirrors the real host

Goal: run the portal, Dolibarr and the backup tools on a Windows 10/11 PC **the same way they will run on Hostinger**: Apache serving a `public` folder, PHP, MariaDB, the application code and private storage **outside** the web root, virtual hosts instead of `php -S`, Task Scheduler instead of cron, HTTPS as in production.
Everything below was derived from a Linux-tested code base. The core Windows setup and automated checks were verified on 2026-10-21 with PHP 8.5.11 and MariaDB 13.0.2. Apache/XAMPP, Dolibarr, HTTPS, and scheduled-backup sections remain deployment guidance and were not fully exercised in this pass.
Time: ~1.5 hours the first time. Use **PowerShell** unless a step says "cmd".

> Differences from the host to remember: Windows file names are case-insensitive (Linux is not – keep exact case in code and links), `chmod` does nothing on Windows, Hostinger runs LiteSpeed (reads `.htaccess` like Apache), cron → Task Scheduler. Fix real problems on Linux-like behaviour by also testing on the host's staging.

## 1. Install the tools
1. **Git for Windows** – https://git-scm.com/download/win (choose "Checkout as-is, commit Unix-style line endings" or run `git config --global core.autocrlf input`). Needed by the AI agent tooling as well.
2. **XAMPP for Windows with PHP 8.3** (Apache 2.4 + PHP + MariaDB) – https://www.apachefriends.org (pick the **8.3** installer; the portal needs **PHP ≥ 8.1**). Install to `C:\xampp`. Components needed: Apache, MySQL (MariaDB), PHP, phpMyAdmin (optional). Allow it through the Windows firewall for private networks only. The verified test run used Chocolatey PHP 8.5.11 and MariaDB 13.0.2 instead of XAMPP.
   *Alternative:* Laragon (auto virtual hosts) works too, but keep the folder layout in §2.
3. **Add PHP to PATH:** Start → "Edit environment variables" → Path → add `C:\xampp\php` and `C:\xampp\mysql\bin`. Open a **new** PowerShell and check:
   ```powershell
   php -v            # PHP 8.3.x
   mysql --version   # MariaDB
   ```
4. **rclone** (for the later backup transfer): `winget install Rclone.Rclone` (not needed until Phase 0 is done).
5. **Claude Code / AI agent tool of your choice**, VS Code optional. The AI agent reads `AGENTS.md` and `docs/HANDOVER.md` in the project root.
6. *(Optional but recommended)* **mkcert** for local HTTPS: `winget install FiloSottile.mkcert`.

## 2. Folder layout (mirrors the host)
Create these folders (the names are suggestions; keep the idea: web roots separate from code and storage):
```
C:\sites\
  infynite\                  <- the project (git repo). Unzip/clone here.
    public\                  <- WEB ROOT of the portal  (= public_html on the host)
    app\  storage\  sql\  tools\  docs\   <- NOT web accessible
  dolibarr\
    htdocs\                  <- WEB ROOT of Dolibarr (erp.localhost / erp.<domain> on the host)
  dolibarr-documents\        <- Dolibarr data dir, OUTSIDE its web root
  backups\                   <- PC copy of backups (pull target)
  secrets\                   <- API keys, key files while testing (never in git, never in the web roots)
```
Get the project into `C:\sites\infynite`: either `git clone <your private repo> C:\sites\infynite` or unzip `infynite-site-transfer.zip` there (the zip deliberately has **no** secrets, uploads or config).

## 3. Enable PHP extensions and set host-like limits
Edit `C:\xampp\php\php.ini` (open as Administrator). Un-comment (remove the leading `;`) or add:
```ini
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=sodium
extension=zip
; Hostinger-like limits; replace with the real values after Phase 0
upload_max_filesize = 32M
post_max_size = 40M
memory_limit = 256M
max_execution_time = 120
max_input_vars = 3000
date.timezone = Asia/Kolkata
```
Restart Apache from the XAMPP control panel. Verify in a new PowerShell (CLI and Apache must both be OK):
```powershell
php -m | findstr /i "curl fileinfo gd intl mbstring mysqli openssl pdo_mysql sodium zip zlib"
php tools\phase0_probe.php          # (run from C:\sites\infynite) – "Extensions" section must show no MISSING
```
If `sodium` or `intl` is missing, check `C:\xampp\php\ext` for `php_sodium.dll` / `php_intl.dll`; if absent, your PHP build is incomplete – reinstall XAMPP.

## 4. Apache virtual hosts (portal and Dolibarr on separate "domains")
1. Open `C:\Windows\System32\drivers\etc\hosts` as Administrator and add:
   ```
   127.0.0.1  portal.localhost  erp.localhost
   ```
2. Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf` (make sure `httpd.conf` has `Include conf/extra/httpd-vhosts.conf` un-commented and `LoadModule rewrite_module` is not commented):
   ```apache
   <VirtualHost *:80>
       ServerName portal.localhost
       DocumentRoot "C:/sites/infynite/public"
       <Directory "C:/sites/infynite/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>

   <VirtualHost *:80>
       ServerName erp.localhost
       DocumentRoot "C:/sites/dolibarr/htdocs"
       <Directory "C:/sites/dolibarr/htdocs">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
3. Restart Apache. Check `C:\xampp\apache\logs\error.log` for syntax errors. Note: `app\`, `storage\`, `sql\`, `tools\` are **not** under a DocumentRoot, so they cannot be browsed – exactly like on the host.

## 5. MariaDB: create databases and a dev user
1. Start MySQL in the XAMPP panel. Set a root password (XAMPP's default is empty):
   ```powershell
   mysqladmin -u root password "CHOOSE-A-ROOT-PASSWORD"
   ```
2. Create the databases and a **dev** user. On the real host each database gets its own least-privilege user; for local testing one user with rights on every `infynite_*` database is convenient (it also lets the test tools create throw-away databases):
   ```powershell
   mysql -u root -p -e "CREATE DATABASE infynite_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE infynite_dolibarr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'infynite'@'localhost' IDENTIFIED BY 'CHOOSE-A-DEV-PASSWORD'; GRANT ALL PRIVILEGES ON \`infynite\_%\`.* TO 'infynite'@'localhost'; FLUSH PRIVILEGES;"
   ```
   (In PowerShell the backtick before `infynite\_%` is the escape character; if the quoting fights you, run the SQL in phpMyAdmin → SQL tab instead, using plain backticks.)
3. Import the portal schema (cmd.exe shown; in PowerShell use the second form):
   ```bat
   cd C:\sites\infynite
   mysql -u infynite -p infynite_portal < sql\schema.sql
   ```
   ```powershell
   Get-Content sql\schema.sql -Raw | mysql -u infynite -p infynite_portal
   ```

## 6. Configure and run the portal
1. `copy app\config.example.php app\config.php` and edit `app\config.php`:
   - `'base_url' => 'http://portal.localhost'`, `'force_https' => false` (switch to `true` after §9), `'setup_token' =>` any random 20+ characters,
   - `'db' => ['host'=>'localhost','port'=>3306,'name'=>'infynite_portal','user'=>'infynite','pass'=>'…']`,
   - `'mail' => ['log_only' => true, …]` (Windows has no mail server; verification/reset links are written to `storage\logs\mail.log`).
2. Open http://portal.localhost/ – the home page must show with styling. Then http://portal.localhost/setup: create the **Owner** (the page then disappears). Change `setup_token` afterwards.
3. Look around: Staff area (`/staff`): Orders, Tasks, Inventory, Knowledge, Team, Backups.
4. Customer flow: register a customer in a private window, copy the link from `storage\logs\mail.log`, verify, log in, upload a small STL, approve a quote as staff, etc.

## 7. Automated tests (prove the Windows install behaves like the Linux one)
```powershell
cd C:\sites\infynite
php tools\smoke_test.php          # 62 end-to-end checks; builds and drops its own database infynite_smoke, uses port 8099
php tools\test_roundtrip.php      # backup/restore checks; uses databases infynite_bktest_*
```
Both must end with `0 failed`. If port 8099 is busy, stop the other program. Antivirus can slow or lock temp files – add `C:\sites` and `%TEMP%` to exclusions if tests time out.

### Standalone PHP on Windows

The verified Chocolatey PHP package kept extensions disabled in its default `php.ini`. Enable curl, fileinfo, GD, intl, mbstring, mysqli, openssl, PDO MySQL, sodium, and zip in `php.ini`, or pass them with `-d extension=...` as in the test command. The smoke test now forwards extensions loaded by its parent CLI process to the child PHP server; this keeps the test portable when extensions are enabled through CLI flags.

## 8. Dolibarr (as on the host: separate web root + documents outside it)
1. Download the **official release** of Dolibarr (version recorded in `docs/HANDOVER.md` §11a; tested with 23.0.4) from https://www.dolibarr.org → Download → the `.zip` (SourceForge). Extract so that `C:\sites\dolibarr\htdocs\master.inc.php` exists. Create `C:\sites\dolibarr-documents`.
2. Make the config folder writable for the installer: right-click `C:\sites\dolibarr\htdocs\conf` → Properties → Security → allow your user "Modify" (or run Apache/XAMPP as your user).
3. Open http://erp.localhost/install/ and follow the wizard. Values to use:
   - Documents directory: `C:\sites\dolibarr-documents` (outside the web root!)
   - Database: name `infynite_dolibarr`, server `localhost`, **do not** tick "create database"/"create user", login `infynite` + the dev password, driver `MySQLi`.
   - Admin login: choose your own (not `admin` in production) and a strong password – store in your password manager.
4. After the wizard: confirm `C:\sites\dolibarr-documents\install.lock` exists, then **delete or make read-only** `C:\sites\dolibarr\htdocs\conf\conf.php` write permission (remove the "Modify" you granted in step 2).
5. Enable modules and create the REST API key (the key goes to a file outside any web root, never printed):
   ```powershell
   php tools\dolibarr_configure.php --htdocs=C:\sites\dolibarr\htdocs --key-file=C:\sites\secrets\dolibarr_api_key.txt
   ```
6. In `C:\sites\infynite\app\config.php` set `'dolibarr' => ['url'=>'http://erp.localhost','api_key'=>'<contents of the key file>','ui_url'=>'http://erp.localhost','vat_rate'=>0]`. (`vat_rate` must later be set to the real GST rate.)
7. Test the link: in the portal, as owner, open a **quoted/approved** order → **Sync to Dolibarr**. In Dolibarr: Third parties shows the customer, Commercial → Proposals shows a draft proposal with the right amount; pressing Sync again must say "Already synced".
   Check the REST API directly: `curl.exe -H "DOLAPIKEY: <key>" http://erp.localhost/api/index.php/status` → JSON with the Dolibarr version; without the header → 401.

## 9. HTTPS locally (recommended: production uses it, cookies are `Secure`)
```powershell
mkcert -install
cd C:\sites\secrets
mkcert portal.localhost erp.localhost
```
Enable SSL in XAMPP: uncomment `Include conf/extra/httpd-ssl.conf` in `httpd.conf`, make sure `LoadModule ssl_module` is on, and in `httpd-ssl.conf`/`httpd-vhosts.conf` add `<VirtualHost *:443>` blocks for both hosts with the same `DocumentRoot` + `<Directory>` as in §4 and:
```apache
SSLEngine on
SSLCertificateFile    "C:/sites/secrets/portal.localhost+1.pem"
SSLCertificateKeyFile "C:/sites/secrets/portal.localhost+1-key.pem"
```
(mkcert prints the exact file names.) Restart Apache, open https://portal.localhost (padlock). Then set `'base_url' => 'https://portal.localhost'`, `'force_https' => true` in `app\config.php` and re-run `php tools\smoke_test.php` (it uses its own HTTP server, so it is unaffected). `public\.htaccess` redirects any non-localhost host to HTTPS, so on the real domain it enforces TLS.

## 10. Backups on Windows (what Task Scheduler replaces)
Read `docs/BACKUP_OPERATIONS.md` first. Summary for Windows:
1. **Each owner, on their own PC:** `php C:\sites\infynite\tools\keygen.php --label="Owner 1" --out=C:\sites\secrets\owner1.key` (prompts for a 12+ character passphrase – the prompt shows the typing on Windows; clear the screen after). Store key + passphrase separately (password manager, sealed paper, offline USB). Never copy the private key to the host or into git.
2. Put both **public** keys into `app\config.php` → `backup.recipients`, and for local testing add a folder destination, e.g. `'destinations' => [['type'=>'folder','path'=>'C:/sites/backups/offsite']]` (use forward slashes). Add Dolibarr:
   ```php
   'backup' => [
     'recipients'   => ['<owner1 public>', '<owner2 public>'],
     'destinations' => [['type'=>'folder','path'=>'C:/sites/backups/offsite']],
     'databases' => [
        ['name'=>'portal',   'host'=>'localhost','port'=>3306,'db'=>'infynite_portal',   'user'=>'infynite','pass'=>'…'],
        ['name'=>'dolibarr', 'host'=>'localhost','port'=>3306,'db'=>'infynite_dolibarr', 'user'=>'infynite','pass'=>'…'],
     ],
     'paths' => [
        ['name'=>'uploads',       'path'=>'C:/sites/infynite/storage/uploads'],
        ['name'=>'dolibarr-docs', 'path'=>'C:/sites/dolibarr-documents'],
     ],
     'config_files' => ['C:/sites/infynite/app/config.php', 'C:/sites/dolibarr/htdocs/conf/conf.php'],
   ],
   ```
3. Run, verify, restore into scratch (the restore drill):
   ```powershell
   php tools\backup.php
   php tools\restore.php list --src=C:/sites/backups/offsite
   php tools\restore.php verify --src=C:/sites/backups/offsite --key=C:\sites\secrets\owner1.key
   php tools\restore.php restore --src=C:/sites/backups/offsite --key=C:\sites\secrets\owner1.key --db=portal=infynite_drill_portal --db=dolibarr=infynite_drill_erp --files-to=C:\sites\drill
   ```
   (the scratch databases `infynite_drill_*` are allowed by the dev user's `infynite\_%` grant; drop them and delete `C:\sites\drill` afterwards).
4. **Task Scheduler** equivalents of the future cron jobs (run in an elevated PowerShell; adjust paths):
   ```powershell
   schtasks /Create /TN "Infynite backup (host-simulation)" /SC DAILY /ST 02:30 /TR "C:\xampp\php\php.exe C:\sites\infynite\tools\backup.php"
   schtasks /Create /TN "Infynite pull (PC)"                /SC DAILY /ST 04:30 /TR "C:\xampp\php\php.exe C:\sites\infynite\tools\pull.php --from=C:/sites/backups/offsite --to=C:/sites/backups/pc --prune"
   ```
   On the real setup the first job runs on the host (cron) and the second on the owner's PC pulling from Hostinger via rclone (to be built after Phase 0).
5. Look at http://portal.localhost/staff/backups (owner): last run, key IDs, storage meter.

## 11. Day-to-day
- Start: XAMPP panel → Apache + MySQL. Nothing else is needed (no `php -S`).
- Fast single-process alternative (no Apache): `run-dev.bat` (portal on http://localhost:8080). Use it only for quick checks; the Apache setup above is the one that mirrors the host.
- Logs: `C:\xampp\apache\logs\error.log`, `C:\sites\infynite\storage\logs\php-error.log`, `storage\logs\mail.log` (dev mail).
- After pulling new code: run `php tools\smoke_test.php`. After DB changes: update `sql\schema.sql` **and** add the migration notes to `docs\HANDOVER.md`.
- Never commit `app\config.php`, `*.key`, `API_KEY.txt`, `storage\uploads\*`, backups (`.gitignore` covers them).

## 12. Troubleshooting
| Symptom | Likely cause / fix |
|---|---|
| `php` not recognised | PATH not updated; open a new PowerShell; check `C:\xampp\php\php.exe` exists |
| Page unstyled, CSS 404 | Apache DocumentRoot points to the project root instead of `...\public`; fix §4 |
| 404 for `/login`, `/services` etc. | `mod_rewrite` off or `AllowOverride None`; fix §4 and restart Apache |
| 500 error, blank page | Read `storage\logs\php-error.log` and `apache\logs\error.log`; temporarily set `'debug' => true` in config |
| "Missing app/config.php" | Copy `config.example.php` to `config.php` |
| "could not find driver" | `extension=pdo_mysql` not enabled; edit the **same** php.ini that `php --ini` shows |
| smoke test: "server did not start" | Port 8099 in use or PHP not allowed through the firewall prompt (click Allow) |
| smoke test: DB "Access denied … infynite_smoke" | The DB user lacks the `infynite\_%` grant (§5) |
| Upload fails with no message | `upload_max_filesize`/`post_max_size` in php.ini too low; restart Apache |
| Verification mail never arrives | Expected locally: read `storage\logs\mail.log` (`mail.log_only` true) |
| Dolibarr installer cannot write conf | Give your user Modify rights on `...\htdocs\conf` (then remove them) |
| `Cannot unlink`/access denied during tests | Antivirus lock; add exclusions for `C:\sites` and `%TEMP%` |
| Garbled characters in text files | Save files as UTF-8 without BOM; set `git config core.autocrlf input` |

## 13. Moving to the real host later
Same folder idea: contents of `public\` → `public_html` (or the subdomain's folder), `app\`, `storage\`, `sql\`, `tools\` one level **above** it, `app\config.php` created on the server with the real DB credentials, `force_https` true, `mail.log_only` false, then `/setup`. Do **Phase 0** first (`docs/PHASE0_HOSTING_CHECKLIST.md`).
