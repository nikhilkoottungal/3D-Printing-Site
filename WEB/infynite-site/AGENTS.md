# Instructions for AI agents working on this repo
Read `docs/AI_AGENT_GUIDE.md` first (rules, code map, pitfalls, how to add a feature), then `docs/SYSTEM_WRITEUP.md` and `docs/FEATURES.md`.

Key rules (details in the guide):
- Staff-only internal system on the office LAN. No public pages, no customer logins, no paid services. Customers are records entered by staff.
- Never print, copy, commit or upload secrets (`app/config.php`, `storage/secret.key`, setup token, `*.key`, passwords, backup keys, personal/bank data). Build source archives with `offsite_source_zip()` and scan before upload.
- Never run destructive commands against the live database; tests use their own database via `INFYNITE_CONFIG`.
- Admin safety rules stay: no admin removes another alone; personal admin keys; deletion only for removed non-admin accounts (or self-deletion).
- After any change: `php -l` on changed files, `php tools/smoke_test.php`, and `php tools/test_roundtrip.php` if backups were touched. Both must report 0 failed. Add tests for new behaviour.
- New table or column: `sql/schema.sql` + bump `SCHEMA_VERSION` in `app/lib/migrate.php` (+ ALTER for existing databases).
