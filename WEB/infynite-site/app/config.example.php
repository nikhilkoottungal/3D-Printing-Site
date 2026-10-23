<?php
// Copy to app/config.php (never commit that copy) and fill in. Keep this folder outside the web root.
return [
    'app_name'    => 'Infynite Solutions',
    'base_url'    => 'https://infynitesolutions.in',   // used in e-mailed links
    // 'base_path' => '/portal',   // optional: only if mounted in a subfolder; auto-detected from SCRIPT_NAME otherwise
    'force_https' => true,                             // set false only for local development
    'debug'       => false,
    'setup_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING', // needed once, at /setup, to create the owner

    // Fill these values only in the local, ignored app/config.php. Never commit database passwords.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'infynite_portal',
        'user' => 'infynite',
        // 'pass' => 'PUT_DATABASE_PASSWORD_HERE',
        'pass' => '',
    ],

    'mail' => [
        'from' => 'no-reply@infynitesolutions.in',
        'from_name' => 'Infynite Solutions',
        'log_only' => false,
        // SMTP username/password belong only in the ignored app/config.php or encrypted Admin settings.
        // 'smtp_user' => '...',
        // 'smtp_pass' => '...',
    ],

    'storage' => APP_ROOT . '/storage',   // uploads + logs; MUST be outside the public document root

    // Initial proposals, not confirmed Hostinger limits. Verify in Phase 0 before promising anything.
    'upload' => [
        'max_bytes'       => 25 * 1024 * 1024,
        'max_files'       => 10,
        'quota_bytes'     => 500 * 1024 * 1024,  // per customer
        'max_unzipped'    => 200 * 1024 * 1024,  // 3MF zip-bomb guard
        'max_zip_entries' => 2000,
    ],

    // Backups: see docs/BACKUP_RESTORE_PLAN.md. Only PUBLIC keys go here (generate keys with tools/keygen.php on an owner's PC).
    'backup' => [
        'recipients'   => [],   // e.g. ['<owner1 public key>', '<owner2 public key>']  - BOTH owners
        'destinations' => [],   // e.g. [['type' => 'folder', 'path' => '/path/to/offsite-folder']]  (Google Drive adapter: later)
        'notify_cmd'   => null, // optional command that receives the message as its single argument
        // optional overrides: dir, chunk_mb (500), keep_local (3), databases[] (add Dolibarr), paths[] (add Dolibarr documents),
        // config_files[] (add Dolibarr conf.php), disk_quota_bytes (10 GB), retention (daily 14 / weekly 8 / monthly 12)
    ],

];
