#!/usr/bin/env bash
# Build a production release archive: no tests, no .env, no databases, no logs, no uploads.
#   scripts/build-release.sh            -> dist/job-tracker-<VERSION>.tar.gz and a .sha256 file
# Unpack the archive on the server, copy deploy/env.production.example to .env, and follow docs/deployment.md.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"; cd "$HERE"
VERSION="$(tr -d '[:space:]' < VERSION)"
NAME="job-tracker-$VERSION"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
mkdir -p dist "$STAGE/$NAME"

# Copy the project, leaving out everything that must not ship.
tar -cf - \
  --exclude='./.git' --exclude='./dist' --exclude='./tests' --exclude='./vendor' --exclude='./node_modules' \
  --exclude='./.env' --exclude='./.env.*.local' --exclude='*.sqlite' --exclude='*.sqlite-journal' --exclude='*.log' \
  --exclude='./.phpunit.result.cache' --exclude='.DS_Store' \
  . | tar -xf - -C "$STAGE/$NAME"
# .env.example is documentation and is kept; storage keeps only its placeholder files.
find "$STAGE/$NAME/storage" -type f ! -name '.gitkeep' -delete
for d in logs imports sessions backups exports; do mkdir -p "$STAGE/$NAME/storage/$d"; touch "$STAGE/$NAME/storage/$d/.gitkeep"; done
chmod -R go-w "$STAGE/$NAME"

# Refuse to build if the Bootstrap and font files are missing: production does not use a CDN.
for f in public/assets/vendor/bootstrap/css/bootstrap.min.css public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js public/assets/fonts/Inter-Regular.woff2; do
  [[ -f "$STAGE/$NAME/$f" ]] || { echo "Missing $f (run php cli/fetch-assets.php first)" >&2; exit 1; }
done
# A release must never contain secrets or databases.
if find "$STAGE/$NAME" \( -name '.env' -o -name '*.sqlite' \) | grep -q .; then echo "A secret or database slipped into the release." >&2; exit 1; fi
for f in $(cd "$STAGE/$NAME" && find . -name '*.php' | sort); do php -l "$STAGE/$NAME/$f" >/dev/null || { echo "Syntax error in $f" >&2; exit 1; }; done

tar -czf "dist/$NAME.tar.gz" -C "$STAGE" --owner=0 --group=0 --numeric-owner --sort=name "$NAME"
(cd dist && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
echo "Built dist/$NAME.tar.gz ($(du -h "dist/$NAME.tar.gz" | cut -f1))"
cat "dist/$NAME.tar.gz.sha256"
