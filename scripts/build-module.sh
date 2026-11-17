#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/module.xml" | head -n 1)
if [ -z "$VERSION" ]; then
    echo "Could not read module version." >&2
    exit 1
fi
OUT=${1:-"$ROOT/dist"}
mkdir -p "$OUT"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT HUP INT TERM
mkdir -p "$STAGE/advancedpaging"
for path in module.xml Advancedpaging.class.php functions.inc.php page.advancedpaging.php \
    Backup.php Restore.php preinstall.php README.md src bin packaging docs; do
    cp -R "$ROOT/$path" "$STAGE/advancedpaging/"
done
ARCHIVE="$OUT/advancedpaging-$VERSION.tar.gz"
tar -C "$STAGE" -czf "$ARCHIVE" advancedpaging
echo "Created $ARCHIVE"
