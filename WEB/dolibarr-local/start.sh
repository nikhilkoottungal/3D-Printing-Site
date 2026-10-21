#!/usr/bin/env bash
# Starts the LOCAL TEST Dolibarr on http://localhost:8081 (login in ADMIN_LOGIN.txt). Needs php-gd, php-intl, php-mysql(mysqli).
cd "$(dirname "$0")/src/dolibarr-23.0.4/htdocs" || exit 1
exec php -S localhost:8081 -t .
