#!/usr/bin/env bash
# End-to-end smoke test of import and export over real HTTP, including real multipart uploads.
#   tests/smoke-import.sh http://127.0.0.1:8099 admin@example.com 'admin-pass' staff@example.com 'staff-pass' manager@example.com 'manager-pass'
# Use a dev database: it imports IMP* jobs and soft-deletes them again at the end. Needs python3 (to inspect .xlsx files).
set -u

BASE="${1:?base url}"; A_USER="${2:?admin username}"; A_PASS="${3:?admin password}"
S_USER="${4:?staff username}"; S_PASS="${5:?staff password}"; M_USER="${6:?manager username}"; M_PASS="${7:?manager password}"
AJ="$(mktemp)"; SJ="$(mktemp)"; MJ="$(mktemp)"; WORK="$(mktemp -d)"; PASSED=0; FAILED=0
trap 'rm -rf "$AJ" "$SJ" "$MJ" "$WORK"' EXIT
RUN="IMP$(date +%s)"
HERE="$(cd "$(dirname "$0")" && pwd)"
H="$HERE/support/smoke_helpers.py"

ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }
get()   { curl -s -b "$1" -c "$1" "$BASE$2"; }
text()  { sed 's/<[^>]*>/ /g' | tr -s ' \n' ' '; }
flat()  { tr '\n' ' ' | sed 's/  */ /g'; }
signin() { local t; t=$(curl -s -c "$1" "$BASE/login" | token); curl -s -o /dev/null -b "$1" -c "$1" --data-urlencode "_csrf=$t" --data-urlencode "username=$2" --data-urlencode "password=$3" "$BASE/login"; }
tok()  { curl -s -b "$1" -c "$1" "$BASE/account" | token; }
# multipart upload: jar file [name] -> response headers + body
upload() { curl -s -i -b "$1" -c "$1" -F "_csrf=$(tok "$1")" -F "file=@$2${3:+;filename=$3}" "$BASE/jobs/import"; }
loc()  { grep -i '^location:' <<<"$1" | head -1 | tr -d '\r'; }
batch_id() { grep -oP '/jobs/import/\K[0-9]+' <<<"$(loc "$1")" | head -1; }
total() { get "$1" "$2" | flat | grep -oP 'of \K[0-9]+(?= job)' | head -1; }

signin "$AJ" "$A_USER" "$A_PASS"; signin "$SJ" "$S_USER" "$S_PASS"; signin "$MJ" "$M_USER" "$M_PASS"

echo "== Who may import / export"
for p in /jobs/import /jobs/import/template/csv; do check "Staff: GET $p -> 403" '[[ $(code -b "$SJ" "$BASE$p") == 403 ]]'; done
check "Staff: GET /jobs/export/all -> 403" '[[ $(code -b "$SJ" "$BASE/jobs/export/all") == 403 ]]'
check "Staff: forged upload -> 403" '[[ $(upload "$SJ" /dev/null x.csv | head -1 | grep -c 403) == 1 ]]'
check "signed-out visitors are redirected" '[[ $(code "$BASE/jobs/import") == 302 && $(code "$BASE/jobs/export/all") == 302 ]]'
check "Staff see no Export / Import buttons" '! get "$SJ" /jobs | grep -q "format=csv" && ! get "$SJ" /jobs | grep -q "</svg> Import</a>"'
check "Manager sees both on All Jobs" 'L=$(get "$MJ" /jobs); grep -q "format=csv" <<<"$L" && grep -q "format=xlsx" <<<"$L" && grep -q "</svg> Import</a>" <<<"$L"'
check "the import page explains the steps, limits and columns" 'P=$(get "$AJ" /jobs/import | text); grep -q "Upload and preview" <<<"$P" && grep -q "Customer First Name" <<<"$P" && grep -q "5,000 rows" <<<"$P"'

echo "== Templates"
curl -s -b "$AJ" "$BASE/jobs/import/template/csv" -o "$WORK/template.csv"; curl -s -b "$AJ" "$BASE/jobs/import/template/xlsx" -o "$WORK/template.xlsx"
check "CSV template: UTF-8 BOM, headings and an example row" '[[ "$(head -c 3 "$WORK/template.csv" | od -An -tx1 | tr -d " \n")" == "efbbbf" ]] && grep -q "Job #" "$WORK/template.csv" && [[ $(wc -l < "$WORK/template.csv") -ge 2 ]]'
check "Excel template is a real zip with a workbook" 'python3 "$H" zip "$WORK/template.xlsx"'
check "the template itself imports cleanly" 'R=$(upload "$AJ" "$WORK/template.csv" template.csv); B=$(batch_id "$R"); [[ -n "$B" ]] && get "$AJ" "/jobs/import/$B" | flat | grep -qP "jt-stat-value\">1</span> ?<span class=\"jt-stat-label\">Ready to import"; curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/import/$B/cancel"'

echo "== Upload checks (real multipart)"
printf 'Job #,Name\nX,Y\n' > "$WORK/headers.csv"
printf '<?php system($_GET["c"]); ?>\n' > "$WORK/evil.csv"
printf '<html><script>alert(1)</script></html>\n' > "$WORK/page.csv"
printf 'plain text\n' > "$WORK/notes.txt"
python3 -c "import zipfile; z=zipfile.ZipFile('$WORK/fake.csv','w'); z.writestr('a.txt','x'); z.close()"
head -c 3000000 /dev/urandom | base64 -w0 | head -c 3000000 > "$WORK/big.csv"            # 3 MB: over PHP's default 2 MB upload_max_filesize
JOBS0=$(total "$AJ" /jobs)
for f in evil.csv page.csv fake.csv; do
  R=$(upload "$AJ" "$WORK/$f"); check "$f is refused by its real content" 'get "$AJ" /jobs/import | text | grep -q "not really a CSV"'
done
R=$(upload "$AJ" "$WORK/notes.txt"); check "a .txt file is refused" 'get "$AJ" /jobs/import | text | grep -q "Only .csv"'
R=$(upload "$AJ" "$WORK/headers.csv"); check "a file missing the required columns says which" 'get "$AJ" /jobs/import | text | grep -q "missing required column(s): Customer First Name"'
R=$(upload "$AJ" "$WORK/big.csv"); check "a file over the server's upload limit is explained" 'get "$AJ" /jobs/import | text | grep -q "larger than the"'
check "nothing from those refused uploads was imported" '[[ "$(total "$AJ" /jobs)" == "$JOBS0" ]]'
check "an upload without a CSRF token is refused" '[[ $(curl -s -o /dev/null -w "%{http_code}" -b "$AJ" -F "file=@$WORK/headers.csv" "$BASE/jobs/import") == 403 ]]'
dd if=/dev/zero bs=1M count=9 2>/dev/null | tr '\0' 'a' > "$WORK/huge.csv"
check "a body bigger than post_max_size gets a clear 413 page" 'R=$(curl -s -i -b "$AJ" -c "$AJ" -F "_csrf=$(tok "$AJ")" -F "file=@$WORK/huge.csv" "$BASE/jobs/import"); head -1 <<<"$R" | grep -q 413 && grep -q "larger than this server accepts" <<<"$R"'

echo "== Upload, preview, confirm"
python3 - "$WORK/jobs.csv" "$RUN" <<'EOF'
import csv, sys
path, run = sys.argv[1:3]
head = ["Job #","customer Fname","customer L name","pickupdate","1st availble date","from","To","cf","Balance","job location","Status","carrier name","driver","phone","Delivered Date","Mystery"]
rows = [
 [f"{run}-1","Ada","Smith","11/01/2026","12/01/2026","Austin, TX","Denver, CO","900","$1,200.50","Main warehouse","","","","","","x"],
 [f"{run}-2","Zoë","O'Brien, Jr.","2026-10-01","2026-11-15","Boston, MA","Miami, FL","12.5","80","","In Transit","Blue Ridge","Marcus Reed","(555) 010-2231","","x"],
 [f"{run}-3","Alan","Turing","","Nov 6, 2026","Seattle, WA","Portland, OR","","0","","Delivered","Summit","Priya","5550104417","2026-10-20","x"],
 [f"{run}-BAD","Grace","Hopper","","2026-02-30","A","B","","","","","","","","","x"],
 [f"{run}-1","Dup","Row","","2026-12-01","A","B","","","","","","","","","x"],
 ["=cmd|' /C calc'!A0","Evil","Formula","","2026-12-01","A","B","","","","","","","","","x"],
 [f"{run}-<script>","Xss","Row","","2026-12-01","A","B","","","","","","","","","x"],
]
with open(path, "w", newline="", encoding="utf-8-sig") as f:
    w = csv.writer(f); w.writerow(head); w.writerows(rows)
EOF
R=$(upload "$AJ" "$WORK/jobs.csv" "$RUN-march.csv"); BID=$(batch_id "$R")
check "a real multipart upload of the client-style sheet redirects to the preview" '[[ -n "$BID" ]]'
JOBS_BEFORE=$(total "$AJ" /jobs)
P=$(get "$AJ" "/jobs/import/$BID"); PT=$(text <<<"$P")
check "preview: 7 rows read, 3 ready, 4 problems" 'F=$(flat <<<"$P"); grep -qP "jt-stat-value\">7</span> ?<span class=\"jt-stat-label\">Rows read" <<<"$F" && grep -qP "jt-stat-value\">3</span> ?<span class=\"jt-stat-label\">Ready to import" <<<"$F" && grep -qP "jt-stat-value\">4</span> ?<span class=\"jt-stat-label\">Problems" <<<"$F"'
check "preview: the client's own headings (typos and all) were understood; Mystery was ignored" 'grep -q "1st availble date" <<<"$PT" && grep -q "Available Date" <<<"$PT" && grep -q "Mystery" <<<"$PT"'
check "preview: reasons are specific and quote what was typed" 'grep -q "not a valid date" <<<"$PT" && grep -q "2026-02-30" <<<"$PT" && grep -q "appears again (first on row 2)" <<<"$PT"'
check "preview: hostile text from the file is escaped" '! grep -q "$RUN-<script>" <<<"$P" && grep -q "&lt;script&gt;" <<<"$P"'
check "preview: nothing is saved yet" '[[ "$(total "$AJ" /jobs)" == "$JOBS_BEFORE" ]] && ! get "$AJ" "/jobs?q=$RUN" | grep -q "$RUN-1<"'
check "another user (Manager) cannot see or confirm this batch" '[[ $(code -b "$MJ" "$BASE/jobs/import/$BID") == 404 ]] && [[ $(code -b "$MJ" -c "$MJ" --data-urlencode "_csrf=$(tok "$MJ")" "$BASE/jobs/import/$BID/commit") == 404 ]]'
check "confirming without a CSRF token is refused" '[[ $(code -b "$AJ" -X POST "$BASE/jobs/import/$BID/commit") == 403 ]]'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/import/$BID/commit"
check "confirm imports the 3 valid jobs and skips the rest" '[[ "$(total "$AJ" /jobs)" == "$((JOBS_BEFORE+3))" ]]'
D=$(get "$AJ" "/jobs/import/$BID" | text)
check "the result page shows 3 imported" 'grep -q "Jobs imported" <<<"$D" && grep -q "Problem report" <<<"$D"'
L=$(get "$AJ" "/jobs?q=$RUN&per_page=50")
check "imported jobs are in All Jobs with the right statuses" 'grep -q "$RUN-1<" <<<"$L" && grep -q "jt-badge-transit" <<<"$L" && grep -q "jt-badge-delivered" <<<"$L" && grep -q "jt-badge-booked" <<<"$L"'
check "the in-transit job is in In Transit with its driver; the delivered one in Delivered" 'get "$AJ" "/jobs/in-transit?q=$RUN" | grep -q "Marcus Reed" && get "$AJ" "/jobs/delivered?q=$RUN" | flat | grep -q "Oct 20, 2026"'
check "accents and commas arrived intact" 'get "$AJ" "/jobs?q=$RUN-2" | grep -q "Zoë O&#039;Brien, Jr."'
check "formula-looking and script-looking job numbers were refused" '! get "$AJ" "/jobs?q=cmd" | grep -q "calc"'
curl -s -b "$AJ" "$BASE/jobs/import/$BID/problems" -o "$WORK/problems.csv"
check "the problem report is a CSV with row numbers, reasons, original cells, and formulas neutralised" 'python3 "$H" problems "$WORK/problems.csv" 4'
check "confirming again does nothing" 'curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/import/$BID/commit"; [[ "$(total "$AJ" /jobs)" == "$((JOBS_BEFORE+3))" ]]'
R=$(upload "$AJ" "$WORK/jobs.csv" again.csv); B2=$(batch_id "$R")
check "uploading the same file again finds every valid row already exists" 'get "$AJ" "/jobs/import/$B2" | flat | grep -qP "jt-stat-value\">0</span> ?<span class=\"jt-stat-label\">Ready to import"'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/import/$B2/cancel"
check "cancelling leaves nothing behind and the page says so" 'get "$AJ" "/jobs/import/$B2" | text | grep -q "This import was cancelled"'
check "the file is gone from the server (not reachable, not listed)" '[[ $(code -b "$AJ" "$BASE/storage/imports/") == 404 ]]'

echo "== Export"
check "CSV export of All Jobs: attachment, UTF-8 BOM, headings" 'curl -s -D "$WORK/h.txt" -b "$AJ" "$BASE/jobs/export/all?format=csv" -o "$WORK/all.csv"; grep -qi "^content-disposition: attachment; filename=\"jobs-all-" "$WORK/h.txt" && grep -qi "^content-type: text/csv" "$WORK/h.txt" && [[ "$(head -c 3 "$WORK/all.csv" | od -An -tx1 | tr -d " \n")" == "efbbbf" ]] && head -1 "$WORK/all.csv" | grep -q "Job #"'
csvrows() { python3 "$H" csvrows "$1"; }
check "the CSV has exactly as many rows as the list says" '[[ "$(csvrows "$WORK/all.csv")" == "$(total "$AJ" /jobs)" ]]'
csvrows() { python3 "$H" csvrows "$1"; }
for pair in ready:/jobs/ready later:/jobs/more-than-30 transit:/jobs/in-transit delivered:/jobs/delivered; do
  key="${pair%%:*}"; path="${pair#*:}"
  curl -s -b "$AJ" "$BASE/jobs/export/$key?format=csv" -o "$WORK/$key.csv"
  check "export of '$key' has as many rows as $path shows" '[[ "$(csvrows "$WORK/$key.csv")" == "$(total "$AJ" "$path")" ]]'
done
check "export follows the search and filters" 'curl -s -b "$AJ" "$BASE/jobs/export/all?format=csv&q=$RUN" -o "$WORK/q.csv"; [[ "$(csvrows "$WORK/q.csv")" == 3 ]]'
check "export follows the sort" 'A=$(curl -s -b "$AJ" "$BASE/jobs/export/all?format=csv&q=$RUN&sort=job&dir=asc" | sed -n 2p | cut -c1-30); B=$(curl -s -b "$AJ" "$BASE/jobs/export/all?format=csv&q=$RUN&sort=job&dir=desc" | sed -n 2p | cut -c1-30); [[ "$A" == "$RUN-1"* && "$B" == "$RUN-3"* ]]'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" --data-urlencode "job_number=$RUN-INJ" --data-urlencode 'customer_first_name==HYPERLINK("http://evil")' --data-urlencode "customer_last_name=+cmd" --data-urlencode "first_available_date=2026-12-01" --data-urlencode "from_location=-1" --data-urlencode "to_location=@x" "$BASE/jobs"
curl -s -b "$AJ" "$BASE/jobs/export/all?format=csv&q=$RUN-INJ" -o "$WORK/inj.csv"
check "cells starting with = + - @ are exported with a leading apostrophe (CSV injection guard)" 'python3 "$H" injection "$WORK/inj.csv"'
check "Manager's export includes money; a user without financial access gets none" 'curl -s -b "$MJ" "$BASE/jobs/export/all?format=csv" | head -1 | grep -q "Balance"'
curl -s -D "$WORK/hx.txt" -b "$AJ" "$BASE/jobs/export/all?format=xlsx" -o "$WORK/all.xlsx"
check "xlsx export is a valid workbook with real dates, a frozen header, a filter and every job" 'grep -qi "spreadsheetml" "$WORK/hx.txt" && python3 "$H" xlsx "$WORK/all.xlsx" "$(total "$AJ" /jobs)"'
check "export is not cached" 'grep -qi "^cache-control:.*no-store" "$WORK/h.txt"'
check "unknown section / format -> 404" '[[ $(code -b "$AJ" "$BASE/jobs/export/bogus") == 404 && $(code -b "$AJ" "$BASE/jobs/export/all?format=pdf") == 404 ]]'

echo "== Round trip through real Excel and CSV files"
curl -s -b "$AJ" "$BASE/jobs/export/all?format=xlsx&q=$RUN-" -o "$WORK/rt.xlsx"
R=$(upload "$AJ" "$WORK/rt.xlsx" roundtrip.xlsx); B3=$(batch_id "$R")
check "the exported workbook is accepted by the importer" '[[ -n "$B3" ]]'
check "every job in it is recognised as already existing (nothing would be duplicated)" 'get "$AJ" "/jobs/import/$B3" | flat | grep -qP "jt-stat-value\">0</span> ?<span class=\"jt-stat-label\">Ready to import" && ! get "$AJ" "/jobs/import/$B3" | text | grep -q "not a valid date"'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/import/$B3/cancel"
check "an Excel file renamed to .csv is refused" 'upload "$AJ" "$WORK/rt.xlsx" renamed.csv >/dev/null; get "$AJ" /jobs/import | text | grep -q "not really a CSV"'
check "a CSV renamed to .xlsx is refused" 'upload "$AJ" "$WORK/jobs.csv" renamed.xlsx >/dev/null; get "$AJ" /jobs/import | text | grep -q "not really a XLSX"'

echo "== Clean up"
for id in $(get "$AJ" "/jobs?q=$RUN&per_page=50" | grep -oP 'href="/jobs/\K[0-9]+(?=")' | sort -u); do
  curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/$id/delete"
done
check "test jobs removed from the list" '! get "$AJ" "/jobs?q=$RUN" | grep -q "$RUN-1<"'

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
