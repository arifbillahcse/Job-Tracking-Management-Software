#!/usr/bin/env bash
# Run every automated check against a throw-away database and server. Nothing in your own data is touched.
#   tests/all.sh            unit suite, route audit, all HTTP smoke suites
#   tests/all.sh --perf     ...plus the scale test (20,000 jobs)
#   tests/all.sh --e2e      ...plus the browser walkthrough (needs Playwright: see tests/e2e/uat.js)
# Exit status is 0 only when everything passed.
set -u
HERE="$(cd "$(dirname "$0")/.." && pwd)"; cd "$HERE"
PORT="${PORT:-8096}"; BASE="http://127.0.0.1:$PORT"
DB="$(mktemp -u).sqlite"; LOG="$(mktemp -d)"
export DB_DRIVER=sqlite DB_DATABASE="$DB" APP_ENV=local APP_DEBUG=false APP_URL="$BASE"
AP='Admin demo passphrase 1'; MP='Manager demo passphrase 1'; SP='Staff demo passphrase 1'
RUN_PERF=0; RUN_E2E=0
for a in "$@"; do [[ $a == --perf ]] && RUN_PERF=1; [[ $a == --e2e ]] && RUN_E2E=1; done
FAILED=0; ROWS=()
cleanup() { [[ -n "${PID:-}" ]] && kill "$PID" 2>/dev/null; rm -f "$DB"; }
trap cleanup EXIT

record() { # name status detail
  ROWS+=("$(printf '%-34s %-6s %s' "$1" "$2" "$3")"); [[ $2 == PASS ]] || FAILED=1
}
summary_of() { grep -E '[0-9]+ passed, [0-9]+ failed|within budget' "$1" | tail -1; }

echo "Preparing a throw-away database..."
php cli/migrate.php >/dev/null && php cli/seed.php >/dev/null || { echo "setup failed"; exit 1; }
php cli/create-user.php --username=admin   --name="Demo Admin"   --role=Admin   --password="$AP" >/dev/null
php cli/create-user.php --username=manager --name="Demo Manager" --role=Manager --password="$MP" >/dev/null
php cli/create-user.php --username=staff   --name="Demo Staff"   --role=Staff   --password="$SP" >/dev/null
php cli/seed-demo.php --count=60 >/dev/null

echo "Unit and integration suite..."
php tests/run.php >"$LOG/unit.txt" 2>&1; rc=$?
record "unit + integration" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/unit.txt")"

echo "Route audit..."
php cli/route-audit.php >"$LOG/routes.txt" 2>&1; rc=$?
record "route audit" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(head -1 "$LOG/routes.txt")"

echo "Release archive..."
bash tests/release-check.sh $((PORT + 2)) >"$LOG/release.txt" 2>&1; rc=$?
record "release archive runs on its own" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/release.txt")"

(php -S "127.0.0.1:$PORT" -t public public/index.php >"$LOG/server.txt" 2>&1) & PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.3; done

smoke() { # name script
  echo "$1..."
  bash "tests/$2.sh" "$BASE" admin "$AP" staff "$SP" manager "$MP" >"$LOG/$2.txt" 2>&1; rc=$?
  record "$1" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/$2.txt")"
}
echo "Sign-in and security smoke..."
bash tests/smoke.sh "$BASE" admin "$AP" >"$LOG/smoke.txt" 2>&1; rc=$?
record "sign-in and security (HTTP)" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/smoke.txt")"
smoke "jobs CRUD (HTTP)" smoke-jobs
smoke "sections and workflow (HTTP)" smoke-workflow
smoke "import and export (HTTP)" smoke-import
smoke "settings, audit, private files (HTTP)" smoke-audit
if [[ $RUN_E2E -eq 1 ]]; then
  echo "Browser walkthrough..."
  BASE="$BASE" ADMIN=admin ADMIN_PASS="$AP" STAFF=staff STAFF_PASS="$SP" node tests/e2e/uat.js >"$LOG/e2e.txt" 2>&1; rc=$?
  record "browser walkthrough" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/e2e.txt")"
fi
smoke "users, roles, lockout (HTTP)" smoke-admin   # last: it deliberately trips the sign-in throttle

if [[ $RUN_PERF -eq 1 ]]; then
  echo "Scale test..."
  bash tests/perf.sh 20000 $((PORT + 1)) >"$LOG/perf.txt" 2>&1; rc=$?
  record "scale test (20,000 jobs)" $([[ $rc -eq 0 ]] && echo PASS || echo FAIL) "$(summary_of "$LOG/perf.txt")"
fi

echo; echo "Result"; printf '  %s\n' "${ROWS[@]}"
if [[ $FAILED -ne 0 ]]; then
  echo; echo "Failures (full output kept in $LOG):"
  grep -h -E "^\s+FAIL|^  - " "$LOG"/*.txt | head -30
  trap - EXIT; kill "$PID" 2>/dev/null; rm -f "$DB"
  exit 1
fi
rm -rf "$LOG"
echo; echo "All checks passed."
