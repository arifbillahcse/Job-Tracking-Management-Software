#!/usr/bin/env bash
# End-to-end smoke test of job management over real HTTP, as Admin and as Staff.
#   tests/smoke-jobs.sh http://127.0.0.1:8099 admin@example.com 'admin-pass' staff@example.com 'staff-pass'
# Use a dev database: it creates (and soft-deletes again) a few jobs named SMOKE-*.
set -u

BASE="${1:?base url}"; A_EMAIL="${2:?admin email}"; A_PASS="${3:?admin password}"; S_EMAIL="${4:?staff email}"; S_PASS="${5:?staff password}"
AJ="$(mktemp)"; SJ="$(mktemp)"; PASSED=0; FAILED=0; MADE=()
trap 'rm -f "$AJ" "$SJ"' EXIT
RUN="SMOKE-$(date +%s)"

ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }

signin() { # jar email pass
  local t; t=$(curl -s -c "$1" "$BASE/login" | token)
  curl -s -o /dev/null -b "$1" -c "$1" --data-urlencode "_csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE/login"
}
tok() { curl -s -b "$1" -c "$1" "$BASE/dashboard" | token; } # CSRF token for a signed-in jar

# POST a job form and print the response headers. args: jar path job_number first_name balance [extra curl args...]
post_job() {
  local jar="$1" path="$2" num="$3" first="$4" bal="$5"; shift 5
  local t; t=$(tok "$jar")
  curl -s -i -b "$jar" -c "$jar" --data-urlencode "_csrf=$t" \
    --data-urlencode "job_number=$num" --data-urlencode "customer_first_name=$first" --data-urlencode "customer_last_name=Tester" \
    --data-urlencode "pickup_date=2026-11-01" --data-urlencode "first_available_date=2026-12-01" \
    --data-urlencode "from_location=Austin, TX" --data-urlencode "to_location=Denver, CO" \
    --data-urlencode "job_location=Main warehouse" --data-urlencode "cf=900" \
    --data-urlencode "balance=$bal" --data-urlencode "storage_fee=25" --data-urlencode "notes=smoke test" "$@" "$BASE$path"
}
job_id() { grep -i '^location:' <<<"$1" | grep -o '/jobs/[0-9]*' | head -1 | grep -o '[0-9]*$'; }
loaded_at() { grep -o 'name="loaded_at" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
form_flash() { curl -s -b "$AJ" -c "$AJ" "$BASE/jobs/create"; } # reading the form consumes the flashed errors

echo "== Signed out"
check "/jobs redirects to /login" '[[ $(code "$BASE/jobs") == 302 ]]'
check "/jobs/create redirects to /login" '[[ $(code "$BASE/jobs/create") == 302 ]]'
check "POST /jobs without a session is refused (403/302)" '[[ $(code -d "job_number=x" "$BASE/jobs") =~ ^(403|302)$ ]]'

signin "$AJ" "$A_EMAIL" "$A_PASS"; signin "$SJ" "$S_EMAIL" "$S_PASS"

echo "== Admin: list"
L=$(curl -s -b "$AJ" -c "$AJ" "$BASE/jobs")
check "list shows jobs, the Balance column and the New job button" 'grep -q "jt-jobno" <<<"$L" && grep -q ">Balance<" <<<"$L" && grep -q "New job" <<<"$L"'
check "every table cell carries a data-label (card layout on phones)" '! grep -qP "<td(?![^>]*data-label)" <<<"$L"'
check "pagination is shown for a long list" 'grep -q "aria-label=\"Pagination\"" <<<"$L"'
check "page 2 works" 'curl -s -b "$AJ" "$BASE/jobs?page=2" | grep -q "Showing 26"'
check "a page past the end is clamped, not an error" '[[ $(code -b "$AJ" "$BASE/jobs?page=99999") == 200 ]]'
check "a garbage page parameter is harmless" '[[ $(code -b "$AJ" "$BASE/jobs?page=abc%27") == 200 ]]'
check "security headers on the list" 'curl -s -i -b "$AJ" "$BASE/jobs" | grep -qi "^content-security-policy:"'

echo "== Admin: create"
check "new-job form renders with the money fields" 'F=$(curl -s -b "$AJ" "$BASE/jobs/create"); grep -q "name=\"balance\"" <<<"$F" && grep -q "name=\"job_number\"" <<<"$F"'
R=$(curl -s -i -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs")
check "an empty form goes back to the form" 'grep -qi "^location: .*/jobs/create" <<<"$R"'
check "...and shows the required-field errors" 'form_flash | grep -q "Job # is required."'
R=$(post_job "$AJ" /jobs "$RUN" "Ada" "1200.50"); JID=$(job_id "$R"); MADE+=("$JID")
check "a valid job is created and redirects to its page" '[[ -n "$JID" ]]'
D=$(curl -s -b "$AJ" -c "$AJ" "$BASE/jobs/$JID")
check "details page shows the job, customer, money and a success message" 'grep -q "$RUN" <<<"$D" && grep -q "Ada Tester" <<<"$D" && grep -q "\$1,200.50" <<<"$D" && grep -q "was created" <<<"$D"'
check "status is Booked and the carrier section says not dispatched" 'grep -q "jt-badge-booked" <<<"$D" && grep -q "Not dispatched yet" <<<"$D"'
R=$(post_job "$AJ" /jobs "$RUN" "Dup" "1")
check "a duplicate job # goes back to the form" 'grep -qi "^location: .*/jobs/create" <<<"$R"'
check "...with a field error" 'form_flash | grep -q "already in use"'
R=$(post_job "$AJ" /jobs "BAD NUMBER!" "Ada" "1")
check "an invalid job # is rejected with a clear message" 'grep -qi "^location: .*/jobs/create" <<<"$R" && form_flash | grep -q "may only contain"'
R=$(post_job "$AJ" /jobs "$RUN-NEG" "Ada" "-5")
check "a negative balance is rejected" 'grep -qi "^location: .*/jobs/create" <<<"$R"'
form_flash >/dev/null
R=$(post_job "$AJ" /jobs "$RUN-X" "<script>alert(1)</script>" "5"); XID=$(job_id "$R"); MADE+=("$XID")
X=$(curl -s -b "$AJ" "$BASE/jobs/$XID"); XL=$(curl -s -b "$AJ" "$BASE/jobs?page=1"); XE=$(curl -s -b "$AJ" "$BASE/jobs/$XID/edit")
check "script tags are escaped on details, list and edit" '! grep -q "<script>alert(1)" <<<"$X$XL$XE" && grep -q "&lt;script&gt;alert(1)" <<<"$X" && grep -q "&lt;script&gt;alert(1)" <<<"$XE"'
R=$(post_job "$AJ" /jobs "$RUN-SQL" "Robert'); DROP TABLE jobs;--" "5"); QID=$(job_id "$R"); MADE+=("$QID")
check "SQL-injection text is stored as plain text and the table survives" 'S=$(curl -s -b "$AJ" "$BASE/jobs/$QID"); grep -q "DROP TABLE jobs" <<<"$S" && [[ $(code -b "$AJ" "$BASE/jobs") == 200 ]]'
R=$(post_job "$AJ" /jobs "$RUN-F" "Forged" "5" --data-urlencode "status=delivered" --data-urlencode "carrier_name=Evil Carrier"); FID=$(job_id "$R"); MADE+=("$FID")
FD=$(curl -s -b "$AJ" "$BASE/jobs/$FID")
check "forged status and carrier fields are ignored" 'grep -q "jt-badge-booked" <<<"$FD" && ! grep -q "Evil Carrier" <<<"$FD"'

echo "== Admin: edit"
E=$(curl -s -b "$AJ" -c "$AJ" "$BASE/jobs/$JID/edit"); LOADED=$(loaded_at <<<"$E")
check "the edit form is prefilled and carries loaded_at" 'grep -q "value=\"$RUN\"" <<<"$E" && [[ -n "$LOADED" ]]'
sleep 1
R=$(post_job "$AJ" "/jobs/$JID" "$RUN" "Grace" "1300" --data-urlencode "loaded_at=$LOADED")
check "a valid edit redirects to the details page" 'grep -qi "^location: .*/jobs/$JID" <<<"$R"'
D=$(curl -s -b "$AJ" -c "$AJ" "$BASE/jobs/$JID")
check "the edit is saved (name, balance) with a success message" 'grep -q "Grace Tester" <<<"$D" && grep -q "\$1,300.00" <<<"$D" && grep -q "was updated" <<<"$D"'
check "'Last updated' appears in the activity card" 'grep -q "Last updated" <<<"$D"'
R=$(post_job "$AJ" "/jobs/$JID" "$RUN" "Stale" "1" --data-urlencode "loaded_at=$LOADED")
check "a stale form is bounced back to the edit page" 'grep -qi "^location: .*/jobs/$JID/edit" <<<"$R"'
E2=$(curl -s -b "$AJ" -c "$AJ" "$BASE/jobs/$JID/edit")
check "...with a warning, the user's entry kept, and the saved data untouched" 'grep -q "Someone else changed this job" <<<"$E2" && grep -q "value=\"Stale\"" <<<"$E2" && curl -s -b "$AJ" "$BASE/jobs/$JID" | grep -q "Grace Tester"'
post_job "$AJ" "/jobs/$JID" "$RUN" "Grace" "1300" --data-urlencode "loaded_at=$(loaded_at <<<"$E2")" >/dev/null
check "saving unchanged data says no changes were made" 'curl -s -b "$AJ" "$BASE/jobs/$JID" | grep -q "No changes were made"'

echo "== Staff"
SL=$(curl -s -b "$SJ" -c "$SJ" "$BASE/jobs")
check "Staff sees the list without a Balance column or amounts" 'grep -q "jt-jobno" <<<"$SL" && ! grep -q ">Balance<" <<<"$SL" && ! grep -qE "[$][0-9]" <<<"$SL"'
check "Staff sees no Trash link" '! grep -q "/jobs/trash" <<<"$SL"'
SF=$(curl -s -b "$SJ" "$BASE/jobs/create")
check "the Staff form has no balance or storage fee inputs" '! grep -q "name=\"balance\"" <<<"$SF" && ! grep -q "name=\"storage_fee\"" <<<"$SF"'
R=$(post_job "$SJ" /jobs "$RUN-S" "StaffMade" "999"); SID=$(job_id "$R"); MADE+=("$SID")
check "Staff can create a job" '[[ -n "$SID" ]]'
SD=$(curl -s -b "$SJ" "$BASE/jobs/$SID")
check "the Staff details page shows no money" '! grep -q "Balance" <<<"$SD" && ! grep -q "Storage fee" <<<"$SD" && ! grep -q "999" <<<"$SD"'
check "the forged balance of 999 was NOT stored (Admin sees \$0.00)" 'curl -s -b "$AJ" "$BASE/jobs/$SID" | grep -q "\$0.00"'
ST=$(curl -s -b "$SJ" "$BASE/jobs/$JID/edit"); sleep 1
post_job "$SJ" "/jobs/$JID" "$RUN" "Grace" "5" --data-urlencode "loaded_at=$(loaded_at <<<"$ST")" >/dev/null
check "Staff editing a job cannot change its balance (still \$1,300.00)" 'curl -s -b "$AJ" "$BASE/jobs/$JID" | grep -q "\$1,300.00"'
check "Staff: trash page -> 403" '[[ $(code -b "$SJ" "$BASE/jobs/trash") == 403 ]]'
check "Staff: delete -> 403 and the job survives" '[[ $(code -b "$SJ" -c "$SJ" --data-urlencode "_csrf=$(tok "$SJ")" "$BASE/jobs/$SID/delete") == 403 ]] && [[ $(code -b "$AJ" "$BASE/jobs/$SID") == 200 ]]'
check "Staff: restore -> 403" '[[ $(code -b "$SJ" -c "$SJ" --data-urlencode "_csrf=$(tok "$SJ")" "$BASE/jobs/$SID/restore") == 403 ]]'
check "delete without a CSRF token is refused even for Admin" '[[ $(code -b "$AJ" -X POST "$BASE/jobs/$SID/delete") == 403 ]] && [[ $(code -b "$AJ" "$BASE/jobs/$SID") == 200 ]]'

echo "== Admin: soft delete, trash and restore"
check "the details page has a delete confirmation dialog" 'curl -s -b "$AJ" "$BASE/jobs/$SID" | grep -q "deleteJobModal"'
R=$(curl -s -i -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/$SID/delete")
check "delete redirects to the list" 'grep -qiE "^location: .*/jobs[[:space:]]*$" <<<"$R"'
check "the deleted job is 404 on its page and edit form" '[[ $(code -b "$AJ" "$BASE/jobs/$SID") == 404 ]] && [[ $(code -b "$AJ" "$BASE/jobs/$SID/edit") == 404 ]]'
check "...and gone from the list" '! curl -s -b "$AJ" "$BASE/jobs?page=1" | grep -q "$RUN-S<"'
check "a deleted job cannot be updated via a forged POST" 'post_job "$AJ" "/jobs/$SID" "$RUN-S" "x" "1" --data-urlencode "loaded_at=2020-01-01 00:00:00" | head -1 | grep -q 404'
TR=$(curl -s -b "$AJ" "$BASE/jobs/trash")
check "the trash lists it with a Restore button" 'grep -q "$RUN-S" <<<"$TR" && grep -q "Restore" <<<"$TR"'
R=$(post_job "$AJ" /jobs "$RUN-S" "Again" "1")
check "its job # stays reserved while it is deleted" 'grep -qi "^location: .*/jobs/create" <<<"$R" && form_flash | grep -q "already in use"'
curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/$SID/restore"
check "restore brings it back" '[[ $(code -b "$AJ" "$BASE/jobs/$SID") == 200 ]]'

echo "== Clean up: soft-delete the SMOKE jobs"
for id in "${MADE[@]}"; do
  [[ -n "$id" ]] && curl -s -o /dev/null -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/jobs/$id/delete"
done
ok "cleanup done"

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
