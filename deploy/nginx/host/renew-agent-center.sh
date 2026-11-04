#!/bin/sh
set -eu
case "${RENEWED_LINEAGE:-}" in /etc/letsencrypt/live/agent-center) ;; *) exit 0;; esac
# Certbot runs this only after a successful renewal. Verify before a graceful reload.
/usr/sbin/nginx -t
/bin/systemctl reload nginx
