#!/usr/bin/env bash
# End-to-end smoke test of sections, dashboard and the status workflow over real HTTP.
#   tests/smoke-workflow.sh http://127.0.0.1:8099 admin@example.com 'admin-pass' staff@example.com 'staff-pass'
# Use a dev database: it creates WF-* jobs and soft-deletes them again at the end. Needs GNU date.
set -u

BASE="${1:?base url}"; A_USER="${2:?admin username}"; A_PASS="${3:?admin password}"; S_USER="${4:?staff username}"; S_PASS="${5:?staff password}"
AJ="$(mktemp)"; SJ="$(mktemp)"; PASSED=0; FAILED=0; MADE=()
trap 'rm -f "$AJ" "$SJ"' EXIT
RUN="WF-$(date +%s)"
TODAY="$(date -u +%F)"; D5="$(date -u -d '+5 days' +%F)"; D10="$(date -u -d '+10 days' +%F)"; D90="$(date -u -d '+90 days' +%F)"; P90="$(date -u -d '-90 days' +%F)"; P5="$(date -u -d '-5 days' +%F)"; FUTURE="$(date -u -d '+3 days' +%F)"
TODAY_FMT="$(date -u +'%b %-d, %Y')"

ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }
signin() { local t; t=$(curl -s -c "$1" "$BASE/login" | token); curl -s -o /dev/null -b "$1" -c "$1" --data-urlencode "_csrf=$t" --data-urlencode "username=$2" --data-urlencode "password=$3" "$BASE/login"; }
tok() { curl -s -b "$1" -c "$1" "$BASE/dashboard" | token; }
get() { curl -s -b "$1" -c "$1" "$BASE$2"; }                                  # jar path
flat() { tr '\n' ' ' | sed 's/  */ /g'; }
loaded_at() { grep -o 'name="loaded_at" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
job_id() { grep -i '^location:' <<<"$1" | grep -o '/jobs/[0-9]*' | head -1 | grep -o '[0-9]*$'; }

# dashboard card value: card "Ready for delivery" -> number
card() { get "$AJ" /dashboard | flat | grep -oP "jt-stat-value\">\K[0-9]+(?=</span> <span class=\"jt-stat-label\">$1<)" | head -1; }
# "of N jobs" total shown on a list page
total() { get "$AJ" "$1" | flat | grep -oP 'of \K[0-9]+(?= job)' | head -1; }

post_job() { # jar number first available [extra...]
  local jar="$1" num="$2" first="$3" avail="$4"; shift 4
  curl -s -i -b "$jar" -c "$jar" --data-urlencode "_csrf=$(tok "$jar")" --data-urlencode "job_number=$num" \
    --data-urlencode "customer_first_name=$first" --data-urlencode "customer_last_name=Workflow" \
    --data-urlencode "first_available_date=$avail" --data-urlencode "from_location=Austin, TX" --data-urlencode "to_location=Denver, CO" \
    --data-urlencode "balance=100" --data-urlencode "storage_fee=0" "$@" "$BASE/jobs"
}
status() { # jar id to [k=v ...]  -> response headers
  local jar="$1" id="$2" to="$3"; shift 3
  local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -i -b "$jar" -c "$jar" --data-urlencode "_csrf=$(tok "$jar")" --data-urlencode "to=$to" "${args[@]}" "$BASE/jobs/$id/status"
}
in_list() { get "$AJ" "$1" | grep -q "$2<"; }                                 # path number

signin "$AJ" "$A_USER" "$A_PASS"; signin "$SJ" "$S_USER" "$S_PASS"

echo "== Dashboard and lists agree"
declare -A PAGE=( ["Total jobs"]=/jobs ["Ready for delivery"]=/jobs/ready ["More than 30 days"]=/jobs/more-than-30 ["In transit"]=/jobs/in-transit ["Delivered"]=/jobs/delivered )
for label in "Total jobs" "Ready for delivery" "More than 30 days" "In transit" "Delivered"; do
  c=$(card "$label"); t=$(total "${PAGE[$label]}"); t=${t:-0}
  check "card '$label' ($c) equals the list it links to ($t)" '[[ -n "$c" && "$c" == "$t" ]]'
done
R0=$(card "Ready for delivery"); L0=$(card "More than 30 days"); T0=$(card "In transit"); D0=$(card "Delivered"); ALL0=$(card "Total jobs")
check "every section page loads" 'for p in /jobs /jobs/ready /jobs/more-than-30 /jobs/in-transit /jobs/delivered /dashboard; do [[ $(code -b "$AJ" "$BASE$p") == 200 ]] || exit 1; done'
check "section tabs show live counts" 'get "$AJ" /jobs/ready | sed "s/<[^>]*>/ /g" | tr -s " \n" " " | grep -q "Ready for Delivery $R0 "'
check "signed-out visitors cannot see any section" 'for p in /dashboard /jobs/ready /jobs/in-transit; do [[ $(code "$BASE$p") == 302 ]] || exit 1; done'

echo "== Dates decide the section automatically"
R=$(post_job "$AJ" "$RUN-R" "Rita" "$D5"); RID=$(job_id "$R"); MADE+=("$RID")
L=$(post_job "$AJ" "$RUN-L" "Leo" "$D90" --data-urlencode "pickup_date=$P90"); LID=$(job_id "$L"); MADE+=("$LID")
check "both jobs were created" '[[ -n "$RID" && -n "$LID" ]]'
check "job due in 5 days is in Ready, not More Than 30 Days" 'in_list /jobs/ready "$RUN-R" && ! in_list /jobs/more-than-30 "$RUN-R"'
check "job picked up 90 days ago is in More Than 30 Days, not Ready" 'in_list /jobs/more-than-30 "$RUN-L" && ! in_list /jobs/ready "$RUN-L"'
check "dashboard: Ready +1, More Than 30 +1, Total +2" '[[ $(card "Ready for delivery") == $((R0+1)) && $(card "More than 30 days") == $((L0+1)) && $(card "Total jobs") == $((ALL0+2)) ]]'
check "the Ready row shows a countdown" 'get "$AJ" /jobs/ready | flat | grep -q "in 5 days"'
check "the later row says it is counted from the pickup date" 'get "$AJ" /jobs/more-than-30 | flat | grep -q "picked up more than 30 days ago"'

E=$(get "$AJ" "/jobs/$LID/edit"); LOADED=$(loaded_at <<<"$E"); sleep 1
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" --data-urlencode "loaded_at=$LOADED" \
  --data-urlencode "job_number=$RUN-L" --data-urlencode "customer_first_name=Leo" --data-urlencode "customer_last_name=Workflow" \
  --data-urlencode "first_available_date=$D10" --data-urlencode "pickup_date=$P5" --data-urlencode "from_location=Austin, TX" --data-urlencode "to_location=Denver, CO" \
  --data-urlencode "balance=100" --data-urlencode "storage_fee=0" "$BASE/jobs/$LID"
check "changing the pickup date to 5 days ago moves the job into Ready by itself" 'in_list /jobs/ready "$RUN-L" && ! in_list /jobs/more-than-30 "$RUN-L"'
check "dashboard: Ready +2, More Than 30 back to start" '[[ $(card "Ready for delivery") == $((R0+2)) && $(card "More than 30 days") == "$L0" ]]'

echo "== Move to In Transit (one click)"
check "details page offers one-click Move to In Transit, plus Put on hold / Cancel dialogs" 'P=$(get "$AJ" "/jobs/$RID"); grep -q "Move to In Transit" <<<"$P" && ! grep -q "statusModal-in_transit" <<<"$P" && grep -q "statusModal-on_hold" <<<"$P" && grep -q "statusModal-cancelled" <<<"$P" && ! grep -q "statusModal-delivered" <<<"$P"'
check "the Ready list has the button on the job's row (a form that posts to the job)" 'get "$AJ" /jobs/ready | grep -q "action=\"/jobs/$RID/status\"" && get "$AJ" /jobs/ready | grep -q "Move to In Transit"'
check "the button asks for confirmation and names the date it will save" 'get "$AJ" /jobs/ready | grep -q "data-confirm=\"Move job $RUN-R to In Transit? Today ($TODAY_FMT) will be saved as the loaded date."'
R1=$(status "$AJ" "$RID" in_transit "note=Picked up" "loaded_date=2001-01-01" "carrier_name=Smuggled" "status=delivered")
check "one click moves the job into In Transit" 'in_list /jobs/in-transit "$RUN-R" && ! in_list /jobs/ready "$RUN-R"'
P=$(get "$AJ" "/jobs/$RID")
check "the loaded date is TODAY, whatever the request said, and no carrier was smuggled in" 'F=$(sed "s/<[^>]*>/ /g" <<<"$P" | tr -s " \n" " "); grep -q "Loaded date $TODAY_FMT" <<<"$F" && ! grep -q "Smuggled" <<<"$P" && ! grep -q "Jan 1, 2001" <<<"$P"'
check "the In Transit list shows the loaded date and a Mark as Delivered button" 'T=$(get "$AJ" /jobs/in-transit); grep -q "$TODAY_FMT" <<<"$T" && grep -q "Mark as Delivered" <<<"$T"'
check "dashboard: Ready -1, In Transit +1" '[[ $(card "Ready for delivery") == $((R0+1)) && $(card "In transit") == $((T0+1)) ]]'
E=$(get "$AJ" "/jobs/$RID/edit"); LOADED=$(loaded_at <<<"$E"); sleep 1
check "an in-transit job can have carrier details (optional) and its dates corrected on Edit" 'grep -q "name=\"driver_phone\"" <<<"$E" && grep -q "name=\"loaded_date\"" <<<"$E" && ! grep -q "name=\"delivery_date\"" <<<"$E"'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" --data-urlencode "loaded_at=$LOADED" \
  --data-urlencode "job_number=$RUN-R" --data-urlencode "customer_first_name=Rita" --data-urlencode "customer_last_name=Workflow" \
  --data-urlencode "first_available_date=$D5" --data-urlencode "from_location=Austin, TX" --data-urlencode "to_location=Denver, CO" \
  --data-urlencode "balance=100" --data-urlencode "loaded_date=$TODAY" --data-urlencode "carrier_name=<b>Blue</b> Ridge" \
  --data-urlencode "driver_name=Marcus Reed" --data-urlencode "driver_phone=(555) 010-2231" "$BASE/jobs/$RID"
T=$(get "$AJ" /jobs/in-transit)
check "In Transit shows carrier, driver and a tap-to-call link; markup is escaped" 'grep -q "Marcus Reed" <<<"$T" && grep -q "href=\"tel:5550102231\"" <<<"$T" && grep -q "&lt;b&gt;Blue&lt;/b&gt; Ridge" <<<"$T" && ! grep -q "<b>Blue</b>" <<<"$T"'
check "searching by driver finds it" 'get "$AJ" "/jobs/in-transit?q=marcus" | grep -q "$RUN-R<"'

echo "== Mark as Delivered (one click)"
status "$AJ" "$RID" delivered "delivered_on=$FUTURE" "delivery_date=$FUTURE" "note=Left at front desk" >/dev/null
check "one click moves it to Delivered; a forged future date is ignored and TODAY is saved" 'in_list /jobs/delivered "$RUN-R" && ! in_list /jobs/in-transit "$RUN-R" && get "$AJ" /jobs/delivered | flat | grep -q "$TODAY_FMT"'
check "dashboard: In Transit back to start, Delivered +1" '[[ $(card "In transit") == "$T0" && $(card "Delivered") == $((D0+1)) ]]'
P=$(get "$AJ" "/jobs/$RID")
check "Job Details shows both dates" 'F=$(sed "s/<[^>]*>/ /g" <<<"$P" | tr -s " \n" " "); grep -q "Loaded date $TODAY_FMT" <<<"$F" && grep -q "Delivery date $TODAY_FMT" <<<"$F"'
check "the history shows creation, the move and delivery with notes and dates" 'F=$(sed "s/<[^>]*>/ /g" <<<"$P" | tr -s " \n" " "); grep -q "Job created" <<<"$F" && grep -q "Picked up" <<<"$F" && grep -q "Left at front desk" <<<"$F" && grep -q "Loaded on $TODAY_FMT" <<<"$F" && grep -q "Delivered on $TODAY_FMT" <<<"$F"'
check "delivering twice is refused" 'status "$AJ" "$RID" delivered >/dev/null; get "$AJ" "/jobs/$RID" | grep -q "not allowed"'

echo "== Staff permissions"
check "Staff cannot reopen a delivered job (403) and nothing changes" '[[ $(status "$SJ" "$RID" in_transit "note=try" | head -1 | grep -c 403) == 1 ]] && in_list /jobs/delivered "$RUN-R"'
check "Staff is offered no actions on a delivered job" '! get "$SJ" "/jobs/$RID" | grep -q "Update status"'
SD=$(status "$SJ" "$LID" in_transit)
check "Staff can move a ready job to In Transit" 'in_list /jobs/in-transit "$RUN-L"'
check "Staff pages show no money" '! get "$SJ" /jobs/in-transit | flat | grep -qE "Balance|Storage fee|[$][0-9]" && ! get "$SJ" /dashboard | flat | grep -qE "Outstanding balance|[$][0-9]"'
check "a status change without a CSRF token is refused" '[[ $(code -b "$SJ" -d "to=on_hold&note=x" "$BASE/jobs/$LID/status") == 403 ]]'
check "an impossible change (in transit -> booked) is refused with a message" 'status "$SJ" "$LID" booked >/dev/null; get "$SJ" "/jobs/$LID" | grep -q "not allowed"'

echo "== Reopen, hold, resume, cancel (Admin)"
check "reopening a delivered job needs a reason" 'status "$AJ" "$RID" in_transit "note=" >/dev/null; get "$AJ" "/jobs/$RID" | grep -q "Reason is required\|Note is required" && in_list /jobs/delivered "$RUN-R"'
status "$AJ" "$RID" in_transit "note=Delivered to the wrong address" >/dev/null
check "reopen sends it back to In Transit (carrier kept)" 'in_list /jobs/in-transit "$RUN-R" && get "$AJ" /jobs/in-transit | flat | grep -q "Marcus Reed"'
check "hold needs a reason" 'status "$AJ" "$RID" on_hold "note=" >/dev/null; in_list /jobs/in-transit "$RUN-R"'
status "$AJ" "$RID" on_hold "note=Truck broke down" >/dev/null
check "a held job is in no section but still in All Jobs" '! in_list /jobs/in-transit "$RUN-R" && ! in_list /jobs/ready "$RUN-R" && get "$AJ" "/jobs?status=on_hold&q=$RUN-R" | grep -q "$RUN-R<"'
status "$AJ" "$RID" booked "note=Fixed" >/dev/null
check "resume puts it back into Ready (its date is still +5 days) with the carrier cleared" 'in_list /jobs/ready "$RUN-R" && ! get "$AJ" "/jobs/$RID" | flat | grep -q "Carrier Marcus"'
check "the history kept what was cleared" 'get "$AJ" "/jobs/$RID" | flat | grep -q "Cleared on return to Booked"'
status "$AJ" "$RID" cancelled "note=Customer cancelled" >/dev/null
check "cancel removes it from every section" '! in_list /jobs/ready "$RUN-R" && get "$AJ" "/jobs?status=cancelled&q=$RUN-R" | grep -q "$RUN-R<"'
status "$AJ" "$RID" booked "note=Customer is back" >/dev/null
check "a cancelled job can be reopened as booked" 'in_list /jobs/ready "$RUN-R"'

echo "== Search, sort, filter, paging"
check "search finds both test jobs" 'S=$(get "$AJ" "/jobs?q=$RUN"); grep -q "$RUN-R<" <<<"$S" && grep -q "$RUN-L<" <<<"$S"'
check "multi-word search (first name + last name)" 'get "$AJ" "/jobs?q=rita+workflow" | grep -q "$RUN-R<" && ! get "$AJ" "/jobs?q=rita+workflow" | grep -q "$RUN-L<"'
check "a literal % does not match everything" '[[ $(total "/jobs?q=%25") == "" || $(total "/jobs?q=%25") -lt $(total /jobs) ]]'
check "SQL-injection text returns nothing and the app keeps working" '[[ $(code -b "$AJ" "$BASE/jobs?q=%27%3B+DROP+TABLE+jobs%3B--") == 200 ]] && [[ -n "$(total /jobs)" ]]'
check "a hostile search term is escaped in the page" '! get "$AJ" "/jobs?q=%3Cscript%3Ealert(1)%3C/script%3E" | grep -q "<script>alert(1)"'
check "sorting by job # ascending then descending" 'A=$(get "$AJ" "/jobs?q=$RUN&sort=job&dir=asc" | grep -o "$RUN-[RL]<" | head -1); B=$(get "$AJ" "/jobs?q=$RUN&sort=job&dir=desc" | grep -o "$RUN-[RL]<" | head -1); [[ "$A" == "$RUN-L<" && "$B" == "$RUN-R<" ]]'
check "header links toggle direction and keep the search" 'get "$AJ" "/jobs?q=$RUN&sort=job&dir=asc" | grep -q "sort=job&amp;dir=desc"'
check "garbage sort/page/per_page values are harmless" '[[ $(code -b "$AJ" "$BASE/jobs?sort=x%3Bdrop&dir=sideways&page=-9&per_page=999") == 200 ]]'
check "date filter narrows a section" 'get "$AJ" "/jobs/ready?from_date=$D5&to_date=$D5" | grep -q "$RUN-R<" && ! get "$AJ" "/jobs/ready?from_date=$D5&to_date=$D5" | grep -q "$RUN-L<"'
check "10 rows per page paginates, and page links keep the filters" 'P=$(get "$AJ" "/jobs?per_page=10&q=a"); grep -q "aria-label=\"Pagination\"" <<<"$P" && grep -q "per_page=10" <<<"$P"'
check "no matches shows the reset message" 'get "$AJ" "/jobs?q=zzz-no-such-job" | grep -q "No jobs match your search"'

echo "== Clean up"
for id in "${MADE[@]}"; do
  [[ -n "$id" ]] && curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/$id/delete"
done
check "dashboard totals are back to the start" '[[ $(card "Total jobs") == "$ALL0" ]]'

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
