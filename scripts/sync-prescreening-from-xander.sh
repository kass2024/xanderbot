#!/usr/bin/env bash
# Dev machine: copy latest prescreening helpers from full Xander repo into xanderbot legacy bundle.
set -euo pipefail

XANDER_SRC="${1:-/c/xampp/htdocs/Xander}"
DEST="$(cd "$(dirname "$0")/.." && pwd)/legacy/xander"

mkdir -p "$DEST/helpers" "$DEST/uploads/prescreening"

for f in env_load.php prescreening_schema.php prescreening_whatsapp_schema.php \
  prescreening_whatsapp_flow.php prescreening_notify.php student_status_notify.php mail_smtp.php; do
  cp -f "$XANDER_SRC/helpers/$f" "$DEST/helpers/$f"
done

rm -rf "$DEST/PHPMailer"
cp -a "$XANDER_SRC/PHPMailer" "$DEST/PHPMailer"

echo "Synced to $DEST"
ls -la "$DEST/helpers/"
