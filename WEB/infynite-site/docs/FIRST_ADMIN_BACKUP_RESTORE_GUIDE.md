# Infynite Internal Server: First Admin, Backup and Restore Guide

Version 2026-10-22. Applies to the staff-only portal running on the office server (Linux, PHP 8.3, MariaDB), reachable only on the office LAN.

## 0. Starting and stopping the server

The portal runs as a background service on the office computer and normally keeps running. Check and control it with:

```bash
cd /home/njolly/Documents/WEB/infynite-site
./deploy/server.sh status     # running? and the address to open
./deploy/server.sh start      # start it (or: stop, restart)
./deploy/server.sh logs       # recent service log and PHP errors
./deploy/server.sh timers     # backup (22:30), off-site copy (22:45), daily summary (08:00)
```
Open the address it prints (for example `http://10.0.0.44:8080`) from any computer or phone on the office network. After a reboot the service starts when the user logs in; to start it at boot without anyone logged in, an administrator runs `sudo loginctl enable-linger <user>` once. Installing it on a new machine: `./deploy/install-lan.sh`. For a quick foreground run on this computer only: `./run-dev.sh` (stop with Ctrl+C), but stop the service first (`./deploy/server.sh stop`) because both use port 8080.

## 1. Quick reference

| Item | Value |
|---|---|
| Project folder | `/home/njolly/Documents/WEB/infynite-site` |
| Web address (office LAN) | `http://10.0.0.44:8080` (the server's LAN IP; keep it fixed and keep `base_url` in `app/config.php` equal to it) |
| Web service | systemd user service `infynite-portal` (`systemctl --user status infynite-portal`) |
| Database | MariaDB, database `infynite`, listening on 127.0.0.1 only |
| Secret config | `app/config.php` (owner-only permissions; never copy it into chat, mail, git or Drive) |
| Backup folder (this machine) | `/home/njolly/infynite-backups` |
| Backup private key | `/home/njolly/owner1.key` (plus its passphrase) |
| Backup schedule | systemd user timer `infynite-backup.timer`, daily 22:30 |
| Maintenance page switch | file `storage/maintenance.flag` |

All commands below are run in a terminal. Start every session with:

```bash
cd /home/njolly/Documents/WEB/infynite-site
```

## 2. Create the first admin (Owner) user

The portal ships with no users. The first account, the **Owner**, is created once on the `/setup` page. After that the page disappears (it returns 404) and everyone else is added from **Team & roles**.

### 2.1 Before you start
1. The portal service must be running: `systemctl --user is-active infynite-portal` prints `active`. If not, run `./deploy/install-lan.sh`.
2. The database must be empty (no users). `/setup` only works while there are no staff accounts.
3. You need the **setup token**: the value of `'setup_token'` in `app/config.php`. It is a long random string that proves the person at `/setup` is allowed to create the Owner. To create a fresh one:
   ```bash
   TOK=$(php -r 'echo bin2hex(random_bytes(16));')
   sed -i "s|'setup_token' => '[^']*'|'setup_token' => '$TOK'|" app/config.php
   printf '%s\n' "$TOK" > SETUP_TOKEN.txt && chmod 600 SETUP_TOKEN.txt
   ```
   Read the token from `SETUP_TOKEN.txt` (do not paste it into chat or e-mail).

### 2.2 Create the account
1. Open `http://10.0.0.44:8080/setup` in a browser on the office network.
2. Fill in:
   - **Setup token**: from the previous step.
   - **Your name** and **Email**: the Owner's real name and e-mail.
   - **Password**: your own, at least 10 characters. Nobody else, including the person who installed the server, should know it.
3. Press **Create owner & load starter content**. The portal creates the Owner, loads starter procedures, setup tasks and sample inventory, and logs you in.
4. Confirm `/setup` now shows "Page not found". That means setup is closed.

### 2.3 Immediately afterwards
1. Delete the token file: `rm SETUP_TOKEN.txt`.
2. Open **Admin settings** (sidebar, Owner only). It is protected by a second secret, **your own admin key**. Every admin has a personal key:
   - First visit asks you to create yours (at least 12 characters, a passphrase works well). It is stored only as a hash. The other admins get an e-mail whenever someone creates or changes their key (never the key itself).
   - Afterwards you must enter it to open the page; it locks again after 30 seconds without activity or when you switch browser tab; five wrong attempts in 15 minutes block it for 15 minutes.
   - Lost key: run `php tools/reset_admin_key.php admin@example.com` on the server (without an address it resets every admin), then set a new one.
3. In Admin settings fill in **Email** first (mode, SMTP server, port, encryption, user, password, sender) and press **Send test e-mail**; until e-mail works, password links only appear on screen and in `storage/logs/mail.log`. Then fill in: legal name, address, phone, e-mail, GSTIN, PAN, state, default GST rate, invoice prefix and next number, payment terms, bank details, invoice footer, purchase-order prefix and next number.
4. Open **Notification rules** (button in Admin settings) and tick who receives which alert. Add the other staff under **Team & roles** (roles: Owner, Sales, Production, Purchasing). Each person gets a password-setup link. Mail is currently set to **log only** (`'log_only' => true` in `app/config.php`), so nothing is e-mailed: the Owner copies the link shown on screen when the person is created (valid 2 hours), or finds it later in `storage/logs/mail.log`.
5. Take the first backup (section 3) and test a restore (section 4).

### 2.4 Troubleshooting
| Symptom | Cause and fix |
|---|---|
| `/setup` shows "Page not found" | An account already exists. Log in, or use **Forgot password** (link appears in `storage/logs/mail.log`). |
| "Setup token is wrong" | The token typed differs from `app/config.php`. Copy it again from `SETUP_TOKEN.txt`. |
| Page shows "Back soon" | The maintenance flag exists: `rm storage/maintenance.flag`. |
| Owner password forgotten | **Forgot password** on the login page; copy the link from `storage/logs/mail.log`. |
| Need to start completely over | Take a backup, then follow section 2.5. |

### 2.5 Reset to a clean database (destructive; take a backup first)
```bash
php tools/backup.php
php -r 'define("APP_ROOT",getcwd()); $c=require "app/config.php"; $d=$c["db"];
$p=new PDO("mysql:host={$d["host"]};port={$d["port"]};dbname={$d["name"]};charset=utf8mb4",$d["user"],$d["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec("SET FOREIGN_KEY_CHECKS=0");
foreach($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t){ $p->exec("DROP TABLE `$t`"); }
$p->exec("SET FOREIGN_KEY_CHECKS=1");
foreach(array_filter(array_map("trim",explode(";",preg_replace("/^--.*$/m","",file_get_contents("sql/schema.sql"))))) as $s){ $p->exec($s); }'
```
Then create a new setup token (section 2.1) and repeat section 2.2. Also empty `storage/uploads` if uploaded files should go too.

## 3. Backup

### 3.1 What is backed up
Everything needed to rebuild: all database tables (users, orders, invoices, customers, suppliers, purchase orders, stock, tasks, knowledge base, settings, audit log), the uploaded model files in `storage/uploads`, and a copy of `app/config.php`. Backups are **encrypted** with the owner's public key and chunked; unchanged files are not stored again, so daily runs are small. Only someone holding the private key file **and** its passphrase can read a backup.

### 3.2 One-time setup
1. **Create the key pair** on a device you control (you will be asked for a passphrase of 12+ characters, typed twice, hidden):
   ```bash
   php tools/keygen.php --label="Owner 1" --out=/home/njolly/owner1.key
   ```
   It prints the **public key** (safe to share) and writes the private key file.
2. **Protect the private key.** Copy `owner1.key` to an offline USB stick and the password manager as one entry; keep the passphrase as a **separate** entry, and preferably on separate paper. Do not store the key only on the server, in git or on Drive: if the server is lost with the key on it, the backups are unreadable.
3. **Put the public key in the config.** In `app/config.php` the block is:
   ```php
   'backup' => [
       'recipients'   => ['<public key from step 1>'],
       'destinations' => [['type' => 'folder', 'path' => '/home/njolly/infynite-backups']],
       'notify_cmd'   => '/home/njolly/.config/claude-notify/notify.sh',   // optional: message with the result
   ],
   ```
   A second owner can run `keygen.php` on their own PC and add their public key to `recipients`; then either key can restore.
4. **Create the backup folder** (owner-only): `mkdir -m 700 /home/njolly/infynite-backups`.

### 3.3 Run a backup by hand
```bash
php tools/backup.php
```
The last line must read `Backup OK: run <id>, ...`. Anything else is a failure (exit code 1).

### 3.4 Daily schedule
```bash
cp deploy/infynite-backup.service deploy/infynite-backup.timer ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now infynite-backup.timer
systemctl --user list-timers infynite-backup.timer
```
It runs every day at 22:30 and catches up after a missed run. For it to run while nobody is logged in, an administrator must enable linger once: `sudo loginctl enable-linger njolly`.

### 3.5 Check that backups are healthy
- **Backups page** in the portal (Owner): age and result of the last run, key IDs.
- List runs: `php tools/restore.php list --src=/home/njolly/infynite-backups`
- Verify a run decrypts and every file hash matches (asks for the passphrase):
  ```bash
  php tools/restore.php verify --src=/home/njolly/infynite-backups --key=/home/njolly/owner1.key
  ```
- Old runs are pruned automatically (14 daily, 8 weekly, 12 monthly kept).

### 3.6 Copy backups off this machine
A backup on the same disk does not survive disk failure, theft or fire. At least weekly copy the folder to a USB drive or another PC:
```bash
rsync -a --delete /home/njolly/infynite-backups/ /media/<usb>/infynite-backups/
```
(or with `tools/pull.php` from the other PC). The copy stays encrypted, so it is safe to carry. Never copy the private key together with it.

## 4. Restore

Rule: practise on a scratch copy first; use the live restore only for a real disaster.

### 4.1 Restore drill (safe; nothing live is touched)
1. Find the run id (first column of the newest line):
   ```bash
   php tools/restore.php list --src=/home/njolly/infynite-backups
   ```
2. Restore into a throw-away database and folder (passphrase prompt):
   ```bash
   php tools/restore.php restore --src=/home/njolly/infynite-backups --key=/home/njolly/owner1.key --db=portal=infynite_drill --files-to=/tmp/infynite-drill
   ```
   It reports whether row counts and file checksums match.
3. Clean up the scratch copy:
   ```bash
   php -r 'define("APP_ROOT",getcwd()); require "tools/lib/isbk.php"; $c=isbk_config(); isbk_pdo($c["databases"][0],"information_schema")->exec("DROP DATABASE IF EXISTS infynite_drill");' && rm -rf /tmp/infynite-drill
   ```
Do this monthly and after any big change.

### 4.2 Live restore (overwrites the running system)
Use when data is lost or corrupted. Everything entered **after** the chosen backup is lost, so tell staff to stop working first.

1. Take a fresh backup if the system still works: `php tools/backup.php`.
2. Pick the run to restore: `php tools/restore.php list --src=/home/njolly/infynite-backups`. Write the run id down, for example `20261022-060515`.
3. Restore (replace `RUNID` in both places; the passphrase is asked):
   ```bash
   php tools/restore.php restore --src=/home/njolly/infynite-backups --key=/home/njolly/owner1.key --run=RUNID --live --yes --confirm=RUNID
   ```
   The tool then, automatically:
   1. saves a **pre-restore snapshot** of the current state (in `storage/backup`; keep it until you are sure);
   2. switches the portal to the **maintenance page** ("Back soon", HTTP 503) by creating `storage/maintenance.flag`;
   3. restores the databases and files;
   4. runs checks, removes the maintenance flag and prints `Live restore finished; maintenance mode lifted.`
4. If it prints `LIVE RESTORE HAD FAILURES`, the maintenance page stays on deliberately. Do not remove it. Read the messages, then restore the pre-restore snapshot the same way (its run id is printed at the start) or ask for help.
5. **After the restore, check:** log in as the Owner; open Orders, Customers, Invoices and Inventory and confirm the expected records; open one uploaded file; open the Backups page; confirm `ls storage/maintenance.flag` says "No such file".

Tested on 2026-10-22: a task created after a backup disappeared after the live restore, while the Owner login and everything in the backup stayed intact.

### 4.3 The maintenance page
While `storage/maintenance.flag` exists, every request gets the "Back soon" page (HTTP 503, retry in 30 minutes), including logins. The restore tool creates and removes it. By hand: `touch storage/maintenance.flag` to switch it on, `rm storage/maintenance.flag` to switch it off. If staff see "Back soon" and no restore is running, someone left the file in place: remove it.

### 4.4 Rebuild after losing the whole machine
1. Install a Linux server with PHP 8.2+ (with `sodium`, `zip`, `pdo_mysql`, `mbstring`, `curl`, `zlib`) and MariaDB.
2. Copy the project source folder (section 5) to the new machine.
3. Create an empty database and a database user, then create `app/config.php` from `app/config.example.php`: database name, user and password, `base_url` (the new LAN address), a new `setup_token`, `staff_only` true, and the `backup` block of section 3.2.
4. Import the schema once: `mysql <db> < sql/schema.sql` (or let the first page request create it).
5. Bring the key file `owner1.key`, its passphrase and a copy of the backup folder (USB or other PC). Run the live restore of section 4.2 with `--src` pointing at that folder.
6. Install the services (`./deploy/install-lan.sh`, backup timer), then do the post-restore checks. The Owner and all staff log in with their previous passwords because users are part of the backup.
7. If no backup exists at all, install fresh and follow section 2; only the source code and the settings you retype are recovered.

### 4.5 Restore a single record or file
- **One order, invoice or other row:** run the drill (4.1) into `infynite_drill`, copy the row with SQL (`INSERT ... SELECT` across databases), then drop the scratch database.
- **One uploaded file:** run the drill with `--files-to`; the file is under `uploads/<random name>`; the database table `is_request_files` column `stored_name` tells which file belongs to which order.

## 5. Source code backup
A clean source archive (no secrets, no uploads, no logs, no backups) is kept on Google Drive in `PROJECTS/SAIU_MSCD/INFYNITE_LOCAL_SERVER`. It contains `app/`, `public/`, `sql/`, `tools/`, `deploy/`, `docs/`, `README.md`, `AGENTS.md` and `app/config.example.php` (the template for the real config). The real `app/config.php`, the setup token, key files and uploaded customer files are deliberately excluded; they are protected by the encrypted backup instead.

## 6. Checklist
- [ ] Owner created, `SETUP_TOKEN.txt` deleted
- [ ] Admin key set; company, GST and invoice details entered
- [ ] Staff added with the correct roles
- [ ] Private backup key stored off the server with its passphrase kept separately
- [ ] Daily backup timer enabled; Backups page green
- [ ] Backup folder copied off the machine weekly
- [ ] Restore drill done monthly
- [ ] Still open: `sudo loginctl enable-linger njolly`, firewall for the office subnet only, HTTPS in front of the portal

## 7. Admin safety rules, e-mail and two-factor login (added 2026-10-22)
- **Removing an admin needs everyone's agreement.** On **Team & roles** an admin cannot be removed or demoted by one person. Use **Request removal**; every admin, including the person concerned, must press Agree. Any admin can Reject, and the requester can cancel. All admins are told by e-mail at each step.
- **Admin keys are personal.** One admin's key does not open another admin's session; key creation and changes are announced to the other admins by e-mail.
- **E-mail** is configured in Admin settings > Email and the SMTP password is stored encrypted with a key in `storage/secret.key` (included in the backups; if you restore without it, just enter the SMTP password again).
- **Notification rules** (Admin settings > Notification rules): new order, order status, invoice paid, invoices overdue, PO received, low stock, tasks overdue. Daily summaries are sent at 08:00 by `deploy/infynite-daily.timer` (`systemctl --user enable --now infynite-daily.timer`).
- **My account** (sidebar): change name, phone and password; turn on **two-factor login** with any authenticator app (Google Authenticator, Authy, 1Password). Recovery codes are shown once. A lost phone: an admin runs `php tools/reset_2fa.php person@example.com` on the server.

## 8. Off-site copies to Google Drive or an FTP server (added 2026-10-22)
Admin settings > **Integrations** (Owner, admin key needed).
1. **Google Drive:** connect a Drive once on the server with `rclone config` (it opens a browser sign-in), then pick that remote in the page, type the folder inside the drive (for example `PROJECTS/SAIU_MSCD/INFYNITE_LOCAL_SERVER`) and press **Test connection**. It creates the folder, writes, reads back and deletes a small file.
2. **FTP / FTPS / SFTP:** choose the protocol (SFTP or FTPS are encrypted; plain FTP is not), server, port, user, password (stored encrypted) and folder, then **Test connection**.
3. **What is copied where:** tick per destination: the encrypted full backup, customer model files (readable names, NOT encrypted, so use a private place), knowledge base PDFs, application source (no secrets). Tick **Enabled** on each destination.
4. Copies run every night at 22:45 (`systemctl --user enable --now infynite-offsite.timer`), add new or changed files and never delete on the far side. Press **Run now** to copy immediately. A failure is e-mailed to the admins and shown in the **Last run** table.
5. Command line: `php tools/offsite.php` (optionally `drive` or `ftp`).
A restore still uses the encrypted backup (section 4); off-site copies are extra safety, not a replacement for the restore drill.
