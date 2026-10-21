# Local TEST Dolibarr (not for production)
- Version 23.0.4 (GitHub tag archive, sha256 07f830671b02d834cce3663c0a854b2054ca32231385feffaa6f8057973251cd). Production should use the official release package and a fresh install on Hostinger after Phase 0.
- URL http://localhost:8081 · login and password in `ADMIN_LOGIN.txt` (mode 600) · REST API key in `API_KEY.txt` (mode 600). Both are TEST values; never reuse them.
- Database `infynite_dolibarr` (user `infynite`), documents in `documents/`, config in `src/dolibarr-23.0.4/htdocs/conf/conf.php` (mode 440).
- Start: `./start.sh` (Linux). Windows: `php -S localhost:8081 -t src\dolibarr-23.0.4\htdocs` from this folder.
- `tools/configure.php` enabled modules: API, third parties, proposals, orders, invoices, products, stock, suppliers, and created the API key.
- PHP needs: mysqli, gd, intl, curl, mbstring, xml, zip.
- The installer file `install/install.forced.php` (contained DB credentials) was deleted after install; `documents/install.lock` blocks re-install.
