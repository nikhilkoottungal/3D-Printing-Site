# Infynite Solutions – portal + staff workspace

**AI agents / new developers: start with `AGENTS.md` and `docs/HANDOVER.md`**; Windows: `docs/WINDOWS_SETUP.md`; hosting check: `docs/PHASE0_HOSTING_CHECKLIST.md` (full description, rules, Windows + Linux setup, status, roadmap).

Plain PHP 8 + MySQL, no framework, no Composer, no background processes (fits shared hosting).
**Status (2026-10-21):** runs and passes 62 end-to-end checks + 22 backup/restore checks on Linux (PHP 8.3, MariaDB); Windows setup guide written (untested); **not yet deployed** – the Hostinger Phase 0 check is the next milestone.

## What is in it
- **Public site:** home, services, materials, gallery, contact, privacy, terms (drafts, need professional review).
- **Customers:** register, e-mail verification, login, reset, dashboard, STL/3MF upload (validated), quote request, approve/cancel quote, order tracking, data export, deletion request.
- **Staff workspace** (`/staff`): orders (incl. direct/walk-in orders), inventory, backups status, team & roles, plus:
  - **Tasks:** title, description (Markdown, checklists), status, priority, assignee, due date, group/phase, comments, automatic activity log, list + board views, filters.
  - **Knowledge & procedures:** versioned articles, search, review-by dates, history/restore, and **"Create task from procedure"** (copies the checklist into a task).
  - **Team & roles:** Owner / Sales / Production / Purchasing; owner adds people, changes roles, deactivates.
  - **Customer requests:** quote, status changes (permission per role), file download, optional Dolibarr sync.
- First setup seeds 10 procedures and 10 implementation tasks taken from the brief.

## Layout
`public/` is the only web-visible folder. `app/`, `sql/`, `storage/` must sit **above** it.

## Deploy / first run
1. Check syntax: `find app public -name '*.php' -exec php -l {} \;`
2. Create the MySQL database; import `sql/schema.sql`.
3. `cp app/config.example.php app/config.php`; fill DB, `base_url`, a long random `setup_token`.
4. Upload so that `public/` content is the document root and `app/`, `storage/` are outside it (adjust the `require` in `public/index.php` if needed). `storage/` must be writable by PHP.
5. Open `/setup` once, create the Owner. The page then disappears. Change `setup_token`.
6. Log in, go to **Team**, add the other three staff.
7. Local test without HTTPS: set `force_https` false and `mail.log_only` true (links go to `storage/logs/mail.log`).

## Roles
| Role | Requests | Quote | Production status | Team admin |
|---|---|---|---|---|
| Owner | ✔ | ✔ | ✔ | ✔ |
| Sales | ✔ | ✔ | – | – |
| Production | ✔ | – | ✔ | – |
| Purchasing | view | – | – | – |

All staff: read/edit procedures, see all tasks, comment, create tasks. Edit a task: assignee, creator or owner. Suppliers/POs/stock live in Dolibarr.

## Known limits / to verify (Phase 0)
- Upload size, DB count, cron, mail delivery and private folder above web root are **unverified** on Hostinger.
- Dolibarr client (`app/lib/dolibarr.php`) is untested; check payloads against your version. Staff UI is this portal, not Dolibarr's task module (decision to confirm).
- No MFA for staff yet (brief asks for it if supported). No automated tests yet.
