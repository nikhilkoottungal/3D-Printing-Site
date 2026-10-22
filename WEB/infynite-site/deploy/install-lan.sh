#!/usr/bin/env bash
# Installs the portal as a per-user systemd service on the office LAN (port 8080). No root needed.
set -e
cd "$(dirname "$0")/.."
mkdir -p ~/.config/systemd/user
cp deploy/infynite-portal.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now infynite-portal.service
loginctl enable-linger "$USER" 2>/dev/null || echo "Run once as admin: sudo loginctl enable-linger $USER  (so it starts at boot without login)"
systemctl --user --no-pager status infynite-portal.service | head -5
echo "LAN address: http://$(hostname -I | awk '{print $1}'):8080"
