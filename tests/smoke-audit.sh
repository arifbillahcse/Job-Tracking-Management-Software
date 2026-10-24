#!/usr/bin/env bash
# End-to-end smoke test of settings, the audit log and exposure of private files over real HTTP.
#   tests/smoke-audit.sh http://127.0.0.1:8099 admin@example.com 'admin-pass' staff@example.com 'staff-pass' manager@example.com 'manager-pass'
# Changes settings and puts them back at the end. Use a dev database.
set -u

BASE="${1:?base url}"; A_USER="${2:?admin username}"; A_PASS="${3:?admin password}"
S_USER="${4:?staff username}"; S_PASS="${5:?staff password}"; M_USER="${6:?manager username}"; M_PASS="${7:?manager password}"
AJ="$(mktemp)"; SJ="$(mktemp)"; MJ="$(mktemp)"; PASSED=0; FAILED=0
trap 'rm -f "$AJ" "$SJ" "$MJ"' EXIT

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
total() { get "$1" "$2" | flat | grep -oP 'of \K[0-9]+(?= job)' | head -1; }
field() { get "$AJ" /settings | grep -oP "name=\"$1\"[^>]*value=\"\K[^\"]*" | head -1; }
selected() { get "$AJ" /settings | tr '\n' ' ' | grep -oP "<select[^>]*id=\"$1\".*?</select>" | grep -oP 'value="\K[^"]*(?=" selected)' | head -1; }
save() { curl -s -i -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$@" "$BASE/settings" | head -1 | tr -d '\r'; }
sv() { save --data-urlencode "company_name=${1:-$NAME0}" --data-urlencode "ready_threshold_days=${2:-$DAYS0}" --data-urlencode "currency_symbol=$SYM0" --data-urlencode "timezone=$TZ0" --data-urlencode "page_size=$PS0"; }

signin "$AJ" "$A_USER" "$A_PASS"; signin "$SJ" "$S_USER" "$S_PASS"; signin "$MJ" "$M_USER" "$M_PASS"
NAME0=$(field company_name); DAYS0=$(field ready_threshold_days); SYM0=$(field currency_symbol); TZ0=$(selected timezone); PS0=$(selected page_size)
echo "(current: $NAME0 / $DAYS0 days / $SYM0 / $TZ0 / $PS0 per page)"

echo "== Who may open settings and the audit log"
for p in /settings /audit /audit/1; do
  check "Staff: GET $p -> 403" '[[ $(code -b "$SJ" "$BASE$p") == 403 ]]'
  check "Manager: GET $p -> 403" '[[ $(code -b "$MJ" "$BASE$p") == 403 ]]'
  check "signed out: GET $p is redirected" '[[ $(code "$BASE$p") == 302 ]]'
  check "Admin: GET $p -> 200" '[[ $(code -b "$AJ" "$BASE$p") == 200 ]]'
done
check "Staff cannot save settings" '[[ $(curl -s -o /dev/null -w "%{http_code}" -b "$SJ" -c "$SJ" --data-urlencode "_csrf=$(tok "$SJ")" --data-urlencode "company_name=Hacked" "$BASE/settings") == 403 ]]'
check "Manager cannot save settings" '[[ $(curl -s -o /dev/null -w "%{http_code}" -b "$MJ" -c "$MJ" --data-urlencode "_csrf=$(tok "$MJ")" --data-urlencode "company_name=Hacked" "$BASE/settings") == 403 ]]'
check "saving without a CSRF token -> 403" '[[ $(curl -s -o /dev/null -w "%{http_code}" -b "$AJ" -c "$AJ" --data-urlencode "company_name=NoToken" --data-urlencode "ready_threshold_days=30" "$BASE/settings") == 403 ]]'
check "the company name is unchanged by all of that" '[[ "$(field company_name)" == "$NAME0" ]]'
check "the menu offers both pages to an administrator only" 'get "$AJ" /dashboard | grep -q "href=\"/audit\"" && get "$AJ" /dashboard | grep -q "href=\"/settings\"" && ! get "$MJ" /dashboard | grep -q "href=\"/settings\""'

echo "== Settings"
READY0=$(total "$AJ" /jobs/ready); LATER0=$(total "$AJ" /jobs/more-than-30)
check "the form shows the current values" '[[ -n "$NAME0" && "$DAYS0" =~ ^[0-9]+$ ]]'
check "invalid values are refused with a message and nothing is saved" 'R=$(sv "" 0); grep -q "302" <<<"$R"; get "$AJ" /settings | text | grep -q "whole number of days" && [[ "$(field ready_threshold_days)" == "$DAYS0" ]]'
check "a day limit of 366 is refused" 'sv "$NAME0" 366 >/dev/null; get "$AJ" /settings | text | grep -q "1 to 365"; [[ "$(field ready_threshold_days)" == "$DAYS0" ]]'
check "a valid save redirects and confirms" 'R=$(sv "Smoke Co" 365); grep -q 302 <<<"$R" && get "$AJ" /settings | text | grep -q "Settings saved"'
check "the new name appears in the menu and page title" 'get "$AJ" /dashboard | grep -q "Smoke Co"'
READY1=$(total "$AJ" /jobs/ready); LATER1=$(total "$AJ" /jobs/more-than-30)
check "a 365-day limit moves later jobs into Ready for Delivery ($READY0 -> $READY1)" '[[ $READY1 -ge $READY0 && $LATER1 -le $LATER0 && $((READY1 + LATER1)) -eq $((READY0 + LATER0)) ]]'
check "no job was lost or duplicated by the move" '[[ $((READY1 + LATER1)) -eq $((READY0 + LATER0)) ]]'
check "markup in the company name is escaped" 'sv "<script>alert(1)</script>" 365 >/dev/null; D=$(get "$AJ" /dashboard); ! grep -q "<script>alert(1)</script>" <<<"$D" && grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" <<<"$D"'

echo "== Audit log"
check "the setting changes are listed" 'L=$(get "$AJ" /audit | text); grep -q "Settings changed" <<<"$L"'
check "the entry shows old and new values" 'ID=$(get "$AJ" "/audit?action=settings_updated" | grep -oP "href=\"/audit/\K[0-9]+" | tail -1); D=$(get "$AJ" "/audit/$ID" | text); grep -q "ready_threshold_days" <<<"$D" && grep -q "365" <<<"$D"'
check "hostile text in the log is escaped on the list and detail pages" '! get "$AJ" "/audit?action=settings_updated" | grep -q "<script>alert(1)" && ! get "$AJ" "/audit/$ID" | grep -q "<script>alert(1)"'
check "filtering by action shows only that action" 'T=$(get "$AJ" "/audit?action=login_success" | sed -n "/<tbody>/,\$p" | text); grep -q "Signed in" <<<"$T" && ! grep -q "Settings changed" <<<"$T"'
check "filtering by date works and an empty range is explained" 'get "$AJ" "/audit?from=2001-01-01&to=2001-01-02" | text | grep -q "No entries match"'
check "a malformed filter is ignored instead of erroring" '[[ $(code -b "$AJ" "$BASE/audit?action=%27%3BDROP&from=nope&user=-1&page=abc") == 200 ]]'
check "an unknown entry -> 404" '[[ $(code -b "$AJ" "$BASE/audit/99999999") == 404 ]]'
check "the log cannot be changed: POST/DELETE find no route" '[[ $(curl -s -o /dev/null -w "%{http_code}" -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" "$BASE/audit/$ID/delete") == 404 && $(curl -s -o /dev/null -w "%{http_code}" -b "$AJ" -c "$AJ" -X DELETE "$BASE/audit/$ID") =~ ^(403|404|405)$ ]]'
check "the audit pages are not cached" 'curl -s -D - -o /dev/null -b "$AJ" "$BASE/audit" | grep -qi "^cache-control:.*no-store"'
check "no password or token appears anywhere in the audit pages" '! get "$AJ" "/audit?per_page=500" | grep -qiE "hunter|[$]argon2|\"password\"|password_hash"'

echo "== Private files are not served"
for p in /.env /.env.example /.git/config /composer.json /app/Config/app.php /app/bootstrap.php /storage/dev.sqlite /storage/logs /storage/imports /database/migrations/001_create_roles_and_permissions.sql /cli/seed.php /tests/run.php /docs/assumptions.md /views/layouts/main.php /README.md /public/../.env /%2e%2e/.env /assets/../../.env; do
  check "$p is not served" '[[ $(code "$BASE$p") =~ ^(404|403|302|400)$ ]]'
done
check "no file contents leak on a probe of the SQLite database" '! curl -s "$BASE/storage/dev.sqlite" | head -c 100 | grep -q "SQLite format"'
check "directory listing is not offered for assets" '! curl -s "$BASE/assets/" | grep -qi "index of"'
check "a very long URL is handled" '[[ $(code "$BASE/$(head -c 6000 /dev/zero | tr "\0" a)") =~ ^(404|414|400)$ ]]'
check "a hostile Host header does not appear in redirects" 'H=$(curl -s -i -H "Host: evil.example" "$BASE/dashboard" | grep -i "^location:"); ! grep -qi "evil.example" <<<"$H"'
check "open redirect through the sign-in return path is refused" 'C=$(mktemp); t=$(curl -s -c $C "$BASE/login?next=//evil.example" | token); R=$(curl -s -i -b $C -c $C --data-urlencode "_csrf=$t" --data-urlencode "username=$A_USER" --data-urlencode "password=$A_PASS" "$BASE/login?next=//evil.example" | grep -i "^location:"); rm -f $C; ! grep -qi "evil.example" <<<"$R"'

echo "== Restore"
check "settings are put back" 'sv "$NAME0" "$DAYS0" >/dev/null; [[ "$(field company_name)" == "$NAME0" && "$(field ready_threshold_days)" == "$DAYS0" && "$(total "$AJ" /jobs/ready)" == "$READY0" ]]'

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
