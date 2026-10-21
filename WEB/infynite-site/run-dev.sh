#!/usr/bin/env bash
# Local development server (Linux/macOS).
cd "$(dirname "$0")" || exit 1
[ -f app/config.php ] || { echo "Missing app/config.php - copy app/config.example.php first"; exit 1; }
echo "Serving http://localhost:8080  (Ctrl+C to stop)"
exec php -S localhost:8080 -t public public/index.php
