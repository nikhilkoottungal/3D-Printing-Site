#!/usr/bin/env bash
# Everyday control of the Infynite internal server (a systemd user service on this machine).
#   ./deploy/server.sh start|stop|restart|status|logs|url|backup|test|timers
cd "$(dirname "$0")/.." || exit 1
svc=infynite-portal.service
case "${1:-status}" in
  start)   systemctl --user start "$svc" && sleep 1 && systemctl --user --no-pager status "$svc" | head -4 ;;
  stop)    systemctl --user stop "$svc" && echo "stopped" ;;
  restart) systemctl --user restart "$svc" && sleep 1 && systemctl --user --no-pager status "$svc" | head -4 ;;
  status)  systemctl --user --no-pager status "$svc" | head -8; echo; "$0" url ;;
  logs)    journalctl --user -u "$svc" -n 50 --no-pager; echo "--- PHP errors:"; tail -n 20 storage/logs/php-error.log 2>/dev/null ;;
  url)     echo "Open: http://$(hostname -I | awk '{print $1}'):8080   (this computer: http://localhost:8080)" ;;
  backup)  php tools/backup.php ;;
  test)    php tools/smoke_test.php | tail -3 && php tools/test_roundtrip.php | tail -1 ;;
  timers)  systemctl --user --no-pager list-timers 'infynite-*' ;;
  *) echo "usage: $0 start|stop|restart|status|logs|url|backup|test|timers"; exit 2 ;;
esac
