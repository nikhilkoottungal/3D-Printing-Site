# Infynite Local Server: Fresh Installation Guide

This guide installs the Infynite staff-only portal on a local office computer. The application is plain PHP and MariaDB; it does not require a PHP framework, Composer, Node.js, or Dolibarr.

## 1. Required components

- PHP 8.3 or newer with CLI support
- MariaDB 10.11 or newer
- PHP extensions: `pdo_mysql`, `mbstring`, `curl`, `gd`, `sodium`, and `zip`
- Git, when installing from the repository

Optional components:

- `rclone` for Google Drive, FTP, or SFTP off-site copies
- An SMTP account for e-mail notifications and password links

## 2. Obtain the application

Place the repository on the local server. The application directory is `WEB/infynite-site`.

Expose only the `public/` directory through the web server. Keep `app/`, `storage/`, `sql/`, `tools/`, and `docs/` private.

## 3. Create the database

Create an empty MariaDB database and a dedicated user:

```sql
CREATE DATABASE infynite_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'infynite'@'127.0.0.1' IDENTIFIED BY 'choose-a-long-database-password';
GRANT ALL PRIVILEGES ON infynite_portal.* TO 'infynite'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Keep MariaDB bound to localhost unless remote administration is explicitly required.

## 4. Create the local configuration

From `WEB/infynite-site`, copy `app/config.example.php` to `app/config.php` and set:

- `base_url`, such as `http://127.0.0.1:8080`
- MariaDB host, port, database name, user, and password
- A long random `setup_token`
- Local session and mail settings

`app/config.php` is a secret file. Never commit or upload it.

## 5. Start the application

For a local development run:

```text
php -S 127.0.0.1:8080 -t public public/index.php
```

For a Linux office server:

```bash
./deploy/install-lan.sh
./deploy/server.sh status
./deploy/server.sh start
```

Keep the service LAN-only; do not expose port 8080 directly to the public internet.

## 6. Create the first user

The first account is the Owner. The setup page works only while there are no staff accounts.

1. Open `http://127.0.0.1:8080/setup`.
2. Enter the `setup_token` from `app/config.php`.
3. Enter the Owner's name and e-mail address.
4. Choose a strong password of at least 10 characters.
5. Submit **Create owner & load starter content**.

The application creates the Owner, applies the schema, loads starter procedures and tasks, and signs the Owner in. Once the first account exists, `/setup` is disabled.

## 7. Immediately after first login

1. Open **Admin settings** and create the personal admin key.
2. Configure company identity, GSTIN/PAN, GST rates, invoice numbering, payment terms, and bank details.
3. Configure SMTP and send a test e-mail. Until SMTP is configured, password links are written to the local mail log.
4. Add staff under **Team & roles**.
5. Configure notification rules.
6. Create the encrypted-backup key pair and configure a private destination.
7. Take a first backup and perform a restore drill using a scratch database.

## 8. Verification checklist

Run from the application directory:

```bash
php -l public/index.php
php tools/smoke_test.php
php tools/test_roundtrip.php
```

The smoke and backup round-trip tests must report zero failures. Also verify that MariaDB is localhost-only, the portal is office-LAN-only, secrets are not in Git, and backups can be restored with the Owner's private key and passphrase.
