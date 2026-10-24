#!/usr/bin/env bash
# Response times with many jobs, over real HTTP, against a throw-away SQLite database.
#   tests/perf.sh [jobs=20000] [port=8097]
# SQLite plus PHP's single-threaded built-in server is a floor, not a forecast: MySQL on a real host will differ.
# The budgets are generous on purpose; the point is to catch a page that scans or loads everything.
set -u
N="${1:-20000}"; PORT="${2:-8097}"; BASE="http://127.0.0.1:$PORT"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
DB="$(mktemp -u).sqlite"; JAR="$(mktemp)"; OUT="$(mktemp)"; PASSED=0; FAILED=0
export DB_DRIVER=sqlite DB_DATABASE="$DB" APP_ENV=local APP_DEBUG=false APP_URL="$BASE"
cleanup() { [[ -n "${PID:-}" ]] && kill "$PID" 2>/dev/null; rm -f "$DB" "$JAR" "$OUT"; }
trap cleanup EXIT

echo "Building $N jobs..."
php "$HERE/tests/support/perf_seed.php" "$N" | sed 's/^/  /'
(cd "$HERE" && exec php -S "127.0.0.1:$PORT" -t public public/index.php >/dev/null 2>&1) & PID=$!
sleep 1

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
t=$(curl -s -c "$JAR" "$BASE/login" | token)
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" --data-urlencode "email=perf@example.com" --data-urlencode "password=Perf test passphrase 1" "$BASE/login"

# name  path  budget_seconds
measure() {
  local name="$1" path="$2" budget="$3" r secs code size
  r=$(curl -s -o "$OUT" -b "$JAR" -c "$JAR" -w '%{time_total} %{http_code} %{size_download}' "$BASE$path")
  read -r secs code size <<<"$r"
  if [[ "$code" == 200 ]] && awk "BEGIN{exit !($secs <= $budget)}"; then PASSED=$((PASSED+1)); st=ok; else FAILED=$((FAILED+1)); st=FAIL; fi
  printf "  %-5s %-34s %6.3fs (budget %ss)  HTTP %s  %s KB\n" "$st" "$name" "$secs" "$budget" "$code" "$((size/1024))"
}

echo "Pages with $N jobs"
measure "Dashboard" /dashboard 1.5
measure "All Jobs, first page" /jobs 1
measure "All Jobs, last page" "/jobs?page=$(( (N + 24) / 25 ))" 1
measure "All Jobs, deep page" "/jobs?page=$(( N / 50 ))" 1
measure "Ready for Delivery" /jobs/ready 1
measure "More Than 30 Days" /jobs/more-than-30 1
measure "In Transit" /jobs/in-transit 1
measure "Delivered" /jobs/delivered 1
measure "Search by job number" "/jobs?q=PF-012345" 1.5
measure "Search by name (many hits)" "/jobs?q=Smith" 1.5
measure "Search by name, no hits" "/jobs?q=Zzzzqx" 1.5
measure "Sort by customer" "/jobs?sort=customer&dir=desc" 1.5
measure "Sort by balance" "/jobs?sort=balance&dir=desc" 1.5
measure "Filters combined" "/jobs?q=Kim&status=booked&balance=due&sort=job" 1.5
measure "Job details" /jobs/12345 0.5
measure "Audit log" /audit 1
measure "Users" /users 0.5

echo "Export (streamed)"
for fmt in csv xlsx; do
  limit=$([[ $fmt == csv ]] && echo 30 || echo 90)
  start=$(date +%s.%N)
  r=$(curl -s -o "$OUT" -b "$JAR" -w '%{http_code} %{size_download}' "$BASE/jobs/export/all?format=$fmt")
  secs=$(awk "BEGIN{printf \"%.2f\", $(date +%s.%N) - $start}")
  read -r code size <<<"$r"
  rows=$([[ $fmt == csv ]] && wc -l < "$OUT" || python3 -I -c "import zipfile,sys,re; z=zipfile.ZipFile(sys.argv[1]); print(len(re.findall(r'<row ', z.read('xl/worksheets/sheet1.xml').decode())))" "$OUT")
  cap=$([[ $fmt == csv ]] && echo 50000 || echo 20000)
  if [[ "$N" -gt "$cap" ]]; then   # over the export cap: the app must refuse politely instead of trying
    if [[ "$code" == 302 ]]; then PASSED=$((PASSED+1)); st=ok; else FAILED=$((FAILED+1)); st=FAIL; fi
    printf "  %-5s %-34s refused as designed (cap %s rows), HTTP %s\n" "$st" "all $N jobs as $fmt" "$cap" "$code"
    continue
  fi
  if [[ "$code" == 200 && "$rows" -ge "$N" ]] && awk "BEGIN{exit !($secs <= $limit)}"; then PASSED=$((PASSED+1)); st=ok; else FAILED=$((FAILED+1)); st=FAIL; fi
  printf "  %-5s %-34s %6ss (budget %ss)  HTTP %s  %s MB, %s rows\n" "$st" "all $N jobs as $fmt" "$secs" "$limit" "$code" "$((size/1048576))" "$rows"
done

echo "Import (5,000-row file)"
python3 -I - "$OUT.csv" <<'PY'
import csv, sys
with open(sys.argv[1], "w", newline="", encoding="utf-8-sig") as f:
    w = csv.writer(f)
    w.writerow(["Job #", "Customer First Name", "Customer Last Name", "Pickup Date", "1st Available Date", "From", "To", "CF", "Balance"])
    for i in range(5000):
        w.writerow([f"IMPORTPF-{i:05d}", "Ada", "Smith", "2026-11-01", "2026-12-01", "Austin, TX", "Denver, CO", 900, "1200.50"])
PY
t=$(curl -s -b "$JAR" -c "$JAR" "$BASE/jobs/import" | token)
start=$(date +%s.%N)
loc=$(curl -s -i -b "$JAR" -c "$JAR" -F "_csrf=$t" -F "file=@$OUT.csv;filename=perf.csv" "$BASE/jobs/import" | grep -i '^location:' | tr -d '\r')
secs=$(awk "BEGIN{printf \"%.2f\", $(date +%s.%N) - $start}")
id=$(grep -oP '/jobs/import/\K[0-9]+' <<<"$loc" | head -1)
if [[ -n "$id" ]] && awk "BEGIN{exit !($secs <= 20)}"; then PASSED=$((PASSED+1)); st=ok; else FAILED=$((FAILED+1)); st=FAIL; fi
printf "  %-5s %-34s %6ss (budget 20s)\n" "$st" "upload + preview of 5,000 rows" "$secs"
t=$(curl -s -b "$JAR" -c "$JAR" "$BASE/jobs/import/$id" | token)
start=$(date +%s.%N)
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" "$BASE/jobs/import/$id/commit"
secs=$(awk "BEGIN{printf \"%.2f\", $(date +%s.%N) - $start}")
now=$(curl -s -b "$JAR" "$BASE/jobs?q=IMPORTPF" | tr '\n' ' ' | grep -oP 'of \K[0-9,]+(?= job)' | head -1 | tr -d ',')
if [[ "$now" == "5000" ]] && awk "BEGIN{exit !($secs <= 60)}"; then PASSED=$((PASSED+1)); st=ok; else FAILED=$((FAILED+1)); st=FAIL; fi
printf "  %-5s %-34s %6ss (budget 60s)  %s jobs now found\n" "$st" "confirm and save 5,000 rows" "$secs" "$now"
rm -f "$OUT.csv"

echo
echo "$PASSED within budget, $FAILED over"
[[ $FAILED -eq 0 ]]
