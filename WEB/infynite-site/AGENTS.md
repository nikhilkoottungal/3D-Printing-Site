# Instructions for AI agents working on this repo
Read `docs/HANDOVER.md` first (what exists, how it works, rules, gaps). Then the doc for your task:
- Hosting check on the real Hostinger account (**next milestone**): `docs/PHASE0_HOSTING_CHECKLIST.md` + `tools/phase0_probe.php`
- Setting up / testing on Windows (mirrors the host): `docs/WINDOWS_SETUP.md`
- Backups and restore: `docs/BACKUP_RESTORE_PLAN.md` (design) and `docs/BACKUP_OPERATIONS.md` (how-to)
- Tests: `php tools/smoke_test.php` (62 end-to-end checks) and `php tools/test_roundtrip.php` (22 backup/restore checks)
Key rules:
- PHP 8.0+, no framework, no Composer, no persistent processes. Hosting target: shared hosting (Hostinger Single) – do not add paid services or a VPS dependency.
- Never run `drop`/destructive DB commands or touch production without explicit owner approval and a backup. Never hard-code or print credentials; `app/config.php` is secret and not committed.
- After any PHP change run `php -l` on the changed files, then `php tools/smoke_test.php` and (if backups were touched) `php tools/test_roundtrip.php`; both must report 0 failed.
- Do not start the rclone transfer, Drive upload or any production deployment before the Phase 0 report says GO.
- Do not copy `app/config.php`, key files, API keys or uploads into docs, git or chat.
