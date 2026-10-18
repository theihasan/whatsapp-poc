#!/usr/bin/env bash
# Alert (via syslog + optional webhook) when the data volume is above THRESHOLD percent.
set -euo pipefail
THRESHOLD=${THRESHOLD:-85}
USAGE=$(df --output=pcent /var/www | tail -1 | tr -dc '0-9')
if [ "$USAGE" -ge "$THRESHOLD" ]; then
  logger -t whatsapp-workspace "disk usage ${USAGE}% >= ${THRESHOLD}%"
  [ -n "${ALERT_WEBHOOK_URL:-}" ] && curl -fsS -m 10 -X POST -H 'Content-Type: application/json' \
    -d "{\"text\":\"whatsapp-workspace disk usage ${USAGE}%\"}" "$ALERT_WEBHOOK_URL" >/dev/null || true
fi
