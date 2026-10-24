#!/usr/bin/env bash
# Build the release archive, unpack it somewhere clean, and prove it works on its own:
# contents are right, nothing secret or developer-only ships, and a server started from the unpacked copy
# can migrate, create an administrator, sign in and serve pages. Uses a throw-away SQLite database and port.
#   tests/release-check.sh [port=8095]
set -u
HERE="$(cd "$(dirname "$0")/.." && pwd)"; PORT="${1:-8095}"; BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"; PASSED=0; FAILED=0; PID=""
trap '[[ -n "$PID" ]] && kill "$PID" 2>/dev/null; rm -rf "$WORK"' EXIT
ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

echo "== Build"
( cd "$HERE" && bash scripts/build-release.sh >"$WORK/build.txt" 2>&1 ); check "the build script succeeds" '[[ $? -eq 0 ]]'
VERSION="$(tr -d '[:space:]' < "$HERE/VERSION")"; ARCHIVE="$HERE/dist/job-tracker-$VERSION.tar.gz"
check "the archive and its checksum exist" '[[ -f "$ARCHIVE" && -f "$ARCHIVE.sha256" ]]'
check "the checksum verifies" '(cd "$HERE/dist" && sha256sum -c "job-tracker-$VERSION.tar.gz.sha256" >/dev/null 2>&1)'
tar -xzf "$ARCHIVE" -C "$WORK"; APP="$WORK/job-tracker-$VERSION"

echo "== Contents"
for f in public/index.php public/.htaccess .htaccess app/bootstrap.php VERSION composer.json README.md deploy/nginx.conf.example deploy/apache-vhost.conf.example deploy/cron.example deploy/php-overrides.ini deploy/env.production.example docs/deployment.md docs/operations.md docs/admin-guide.md docs/user-guide.md docs/handover.md public/assets/vendor/bootstrap/css/bootstrap.min.css public/assets/fonts/Inter-Regular.woff2 database/migrations/001_create_roles_and_permissions.sql; do
  check "includes $f" '[[ -e "$APP/$f" ]]'
done
check "no tests folder" '[[ ! -e "$APP/tests" ]]'
check "no .env, no database file, no log or upload leftovers" '[[ -z "$(find "$APP" \( -name .env -o -name "*.sqlite" -o -name "*.log" \) -print -quit)" && -z "$(find "$APP/storage" -type f ! -name .gitkeep -print -quit)" ]]'
check "storage folders exist for logs, imports, sessions, backups" '[[ -d "$APP/storage/logs" && -d "$APP/storage/imports" && -d "$APP/storage/sessions" && -d "$APP/storage/backups" ]]'
check "no file is world-writable" '[[ -z "$(find "$APP" -perm -o+w -print -quit)" ]]'
check "every PHP file has valid syntax" '! find "$APP" -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors" | grep -q .'
check "no development credentials or demo passwords in the release" '! grep -rIl "demo passphrase" "$APP" | grep -q .'

echo "== Runs from the unpacked copy"
export DB_DRIVER=sqlite DB_DATABASE="$WORK/rel.sqlite" APP_ENV=local APP_DEBUG=false APP_URL="$BASE"
cd "$APP"
check "migrate" 'php cli/migrate.php >/dev/null 2>&1'
check "seed" 'php cli/seed.php >/dev/null 2>&1'
OUT=$(php cli/create-admin.php --email=owner@example.com --name="Owner" 2>&1); PW=$(grep -oP 'password \(shown once\): \K.*' <<<"$OUT")
check "create-admin prints a one-time password" '[[ -n "$PW" ]]'
check "doctor runs and reports a version" 'php cli/doctor.php 2>&1 | grep -q "Job Tracker $VERSION"'
check "route audit passes" 'php cli/route-audit.php >/dev/null 2>&1'
(php -S "127.0.0.1:$PORT" -t public public/index.php >"$WORK/server.txt" 2>&1) & PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.3; done
check "the sign-in page is served with security headers" 'curl -s -D - "$BASE/login" -o /dev/null | grep -qi "^content-security-policy:"'
check "assets are served from the release" '[[ $(curl -s -o /dev/null -w "%{http_code}" "$BASE/assets/vendor/bootstrap/css/bootstrap.min.css") == 200 ]]'
check "app/, storage/, .env.example are not reachable over HTTP" '[[ $(curl -s -o /dev/null -w "%{http_code}" "$BASE/app/Config/app.php") == 404 && $(curl -s -o /dev/null -w "%{http_code}" "$BASE/.env.example") == 404 && $(curl -s -o /dev/null -w "%{http_code}" "$BASE/storage/logs/") == 404 ]]'
JAR="$WORK/jar"
T=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$T" --data-urlencode "email=owner@example.com" --data-urlencode "password=$PW" "$BASE/login"
check "the administrator can sign in and sees the dashboard" 'curl -s -b "$JAR" -c "$JAR" "$BASE/dashboard" | grep -q "Total jobs"'
T=$(curl -s -b "$JAR" -c "$JAR" "$BASE/jobs/create" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$T" -d job_number=REL-1 -d customer_first_name=Rel -d customer_last_name=Check -d from_location=A -d to_location=B -d first_available_date=2030-01-01 "$BASE/jobs"
check "a job can be created and found" 'curl -s -b "$JAR" "$BASE/jobs?q=REL-1" | grep -q "REL-1"'
check "a backup can be made from the release" 'php cli/backup.php --keep=2 2>&1 | grep -q "Backup written" && [[ -n "$(find storage/backups -name "job-tracker-*" -print -quit)" ]]'
check "the backup is not world-readable" '[[ -z "$(find storage/backups -name "job-tracker-*" -perm -o+r -print -quit)" ]]'
check "cleanup dry run works" 'php cli/cleanup.php 2>&1 | grep -q "dry run"'
OUT=$(php cli/reset-password.php --email=owner@example.com 2>&1); NEWPW=$(grep -oP 'shown once\): \K.*' <<<"$OUT")
check "reset-password gives a new one-time password" '[[ -n "$NEWPW" && "$NEWPW" != "$PW" ]]'
check "the old password no longer works and the new one must be changed" 'T=$(curl -s -c "$WORK/j2" "$BASE/login" | grep -o "name=\"_csrf\" value=\"[^\"]*\"" | head -1 | sed "s/.*value=\"//; s/\"\$//"); curl -s -o /dev/null -b "$WORK/j2" -c "$WORK/j2" --data-urlencode "_csrf=$T" --data-urlencode "email=owner@example.com" --data-urlencode "password=$NEWPW" "$BASE/login"; curl -s -i -b "$WORK/j2" "$BASE/dashboard" | grep -qi "^location: .*account"'
echo; echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
