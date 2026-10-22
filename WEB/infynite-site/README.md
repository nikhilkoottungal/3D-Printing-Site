# Infynite Solutions internal server

A staff-only business system for a 3D printing company: orders, production, stock, purchasing, billing and GST, tasks, notes, knowledge base, people. Plain PHP 8.3 + MariaDB, no framework. It runs on an office machine and is reachable **only on the office network**. There is no public site and no customer login.

**Open it:** `http://<server LAN IP>:8080` (run `./deploy/server.sh url`).
**Start / stop / check:** `./deploy/server.sh start|stop|restart|status|logs|timers`.
**Tests:** `php tools/smoke_test.php` (331 end-to-end checks) and `php tools/test_roundtrip.php` (22 backup/restore checks).

## Read next
| Document | For |
|---|---|
| `docs/FRESH_INSTALLATION.md` | required components and first-user setup |
| `docs/SYSTEM_WRITEUP.md` | what was built, why, architecture, security, operations, status |
| `docs/FIRST_ADMIN_BACKUP_RESTORE_GUIDE.md` | starting the server, creating the first admin, backup, restore, off-site copies |
| `docs/FEATURES.md` | every feature, where it is, who may use it |
| `docs/AI_AGENT_GUIDE.md` | rules, code map and pitfalls for an AI agent or developer |
| `docs/ROADMAP.md`, `docs/LOCAL_HOSTING.md` | the Owner's decisions and requirements |
| `docs/BACKUP_OPERATIONS.md`, `docs/BACKUP_RESTORE_PLAN.md` | backup toolkit design and commands |
| `docs/archive/` | superseded Hostinger, Dolibarr and Windows material |

## Layout
`public/` (web-visible), `app/` (code, views, `config.php` secret), `sql/schema.sql`, `storage/` (private data), `tools/`, `deploy/` (systemd units, `server.sh`), `docs/`.

## First run on a new machine
1. PHP 8.2+ with `sodium zip pdo_mysql mbstring curl gd`, MariaDB, rclone (optional, for Drive/FTP copies).
2. Create a database and user; copy `app/config.example.php` to `app/config.php` and fill in the database login, `base_url` and a long random `setup_token`.
3. `./deploy/install-lan.sh`, then open `/setup` once to create the Owner, set your admin key, fill in Admin settings.
4. Backups: `tools/keygen.php`, add the public key to `app/config.php`, `systemctl --user enable --now infynite-backup.timer` (see the guide).
