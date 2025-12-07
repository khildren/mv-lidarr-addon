#!/usr/bin/env bash
set -e

# Default: run every 60 minutes
: "${INTERVAL_MINUTES:=60}"

echo "[entrypoint] Lidarr MV Sync starting..."
echo "[entrypoint] Interval: ${INTERVAL_MINUTES} minutes"
echo "[entrypoint] Web UI on port 8080"

# Ensure logs dir exists
mkdir -p /app/logs

# Start PHP dev server for the Web UI
php -S 0.0.0.0:8080 -t /app/public >/app/logs/php-server.log 2>&1 &

# Main loop
while true; do
  echo "[entrypoint] === Run start $(date -Iseconds) ==="
  php /app/sync.php || echo "[entrypoint] WARN: sync.php exited with non-zero status $?"
  sleep "$((INTERVAL_MINUTES * 60))"
done
