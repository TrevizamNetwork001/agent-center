#!/bin/bash
# Fixed-path Nginx rotation, with delayed compression. No tenant/user-controlled input.
set -euo pipefail
umask 027
log=/var/log/nginx/agent-center-status.log
exec 9>/run/agent-center-status-rotate.lock
flock -n 9 || exit 0
[ -f "$log" ] && [ ! -L "$log" ] && [ -s "$log" ] || exit 0
# Refuse unexpected links at every managed destination.
for suffix in .1 .2 .2.gz .3.gz .4.gz .5.gz .6.gz .7.gz; do [ ! -L "$log$suffix" ] || exit 1; done
# Delay compression for one full daily cycle so workers can finish reopening.
rm -f "$log.7.gz"
for n in 6 5 4 3 2; do
  if [ -f "$log.$n.gz" ]; then mv "$log.$n.gz" "$log.$((n+1)).gz"; fi
done
if [ -f "$log.1" ]; then gzip -c "$log.1" > "$log.2.gz.pending"; mv "$log.2.gz.pending" "$log.2.gz"; rm "$log.1"; fi
mv "$log" "$log.1"
install -m 0640 -o root -g adm /dev/null "$log"
/usr/sbin/nginx -s reopen
