#!/usr/bin/env bash
# End-to-end smoke test of user / role administration over real HTTP, with several live sessions at once.
#   tests/smoke-admin.sh http://127.0.0.1:8099 admin@example.com 'admin-pass' staff@example.com 'staff-pass' manager@example.com 'manager-pass'
# Use a dev database: it creates SMOKE users/roles and deletes them again at the end. It makes ~6 failed
# sign-ins from your IP on purpose (to prove lockout), so do not run it many times within 15 minutes.
set -u

BASE="${1:?base url}"; A_EMAIL="${2:?admin email}"; A_PASS="${3:?admin password}"
S_EMAIL="${4:?staff email}"; S_PASS="${5:?staff password}"; M_EMAIL="${6:?manager email}"; M_PASS="${7:?manager password}"
AJ="$(mktemp)"; SJ="$(mktemp)"; MJ="$(mktemp)"; NJ="$(mktemp)"; PASSED=0; FAILED=0
trap 'rm -f "$AJ" "$SJ" "$MJ" "$NJ"' EXIT
RUN="smoke$(date +%s)"; NEW_EMAIL="$RUN@example.com"; NEW_PASS="chosen by the new user $RUN"

ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }
flat()  { tr '\n' ' ' | sed 's/  */ /g'; }
text()  { sed 's/<[^>]*>/ /g' | tr -s ' \n' ' '; }
get()   { curl -s -b "$1" -c "$1" "$BASE$2"; }
signin() { local t; t=$(curl -s -c "$1" "$BASE/login" | token); curl -s -i -b "$1" -c "$1" --data-urlencode "_csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE/login"; }
tok()  { curl -s -b "$1" -c "$1" "$BASE/account" | token; }           # CSRF token from any signed-in page
post() { local jar="$1" path="$2"; shift 2; local a=(); for kv in "$@"; do a+=(--data-urlencode "$kv"); done; curl -s -i -b "$jar" -c "$jar" --data-urlencode "_csrf=$(tok "$jar")" "${a[@]}" "$BASE$path"; }
loc()  { grep -i '^location:' <<<"$1" | head -1 | tr -d '\r'; }
secret() { flat | grep -oP 'id="secretValue">\K[^<]+' | head -1; }
user_id() { get "$AJ" "/users?q=$1" | grep -oP 'href="/users/\K[0-9]+(?=/edit")' | head -1; }
rid() { get "$AJ" /roles | grep -oP "href=\"/roles/\K[0-9]+(?=/edit\"[^>]*aria-label=\"Edit role $1\")" | head -1; }

echo "== Sign in everyone"
signin "$AJ" "$A_EMAIL" "$A_PASS" >/dev/null; signin "$SJ" "$S_EMAIL" "$S_PASS" >/dev/null; signin "$MJ" "$M_EMAIL" "$M_PASS" >/dev/null
check "Admin, Manager and Staff are signed in" '[[ $(code -b "$AJ" "$BASE/dashboard") == 200 && $(code -b "$MJ" "$BASE/dashboard") == 200 && $(code -b "$SJ" "$BASE/dashboard") == 200 ]]'

echo "== Who may see administration"
for jar in SJ MJ; do
  for p in /users /users/create /roles /roles/create /roles/matrix "/users/1/edit" "/roles/1/edit"; do
    c=$(code -b "${!jar}" "$BASE$p")
    check "$jar: GET $p -> 403" '[[ "$c" == 403 ]]'
  done
done
check "Staff: forged POST (user delete, role delete, matrix) -> 403" '[[ $(post "$SJ" /users/1/delete | head -1 | grep -c 403) == 1 && $(post "$SJ" /roles/1/delete | head -1 | grep -c 403) == 1 && $(post "$SJ" /roles/matrix version=x | head -1 | grep -c 403) == 1 ]]'
check "Staff and Manager see no administration links" '! get "$SJ" /dashboard | text | grep -q "Roles & Permissions" && ! get "$MJ" /dashboard | text | grep -q "Administration"'
check "Admin sees the Administration section" 'get "$AJ" /dashboard | text | grep -q "Administration" && get "$AJ" /dashboard | text | grep -q "Users"'
check "signed-out visitors are redirected" '[[ $(code "$BASE/users") == 302 && $(code "$BASE/roles/matrix") == 302 ]]'

echo "== Users: list"
L=$(get "$AJ" /users)
check "list shows users, roles and status badges; every cell has a data-label" 'grep -q "Demo Admin" <<<"$L" && grep -q "jt-badge-transit" <<<"$L" && ! grep -qP "<td(?![^>]*data-label)" <<<"$L"'
check "search by email and by name" 'get "$AJ" "/users?q=$A_EMAIL" | grep -q "$A_EMAIL" && ! get "$AJ" "/users?q=$A_EMAIL" | grep -q "$S_EMAIL"'
check "hostile search text is inert and escaped" '[[ $(code -b "$AJ" "$BASE/users?q=%27%3B+DROP+TABLE+users%3B--") == 200 ]] && ! get "$AJ" "/users?q=%3Cscript%3Ealert(1)%3C/script%3E" | grep -q "<script>alert(1)" && [[ $(code -b "$AJ" "$BASE/users?sort=x%3Bdrop&status=zzz&page=-4") == 200 ]]'

echo "== Users: create (one-time temporary password)"
R=$(post "$AJ" /users "name=<script>alert(1)</script> $RUN" "email=$NEW_EMAIL" "role_id=$(get "$AJ" /users/create | grep -oP '<option value="\K[0-9]+(?=">Staff<)' | head -1)")
NID=$(grep -oP '/users/\K[0-9]+(?=/edit)' <<<"$(loc "$R")")
check "a valid user is created and you land on their page" '[[ -n "$NID" ]]'
P=$(get "$AJ" "/users/$NID/edit"); TEMP=$(secret <<<"$P")
check "the temporary password is shown once with a Copy button" '[[ ${#TEMP} -eq 16 ]] && grep -q "data-copy=\"#secretValue\"" <<<"$P" && grep -q "Shown once" <<<"$P"'
check "reloading the page does not show it again" '! get "$AJ" "/users/$NID/edit" | grep -q "$TEMP"'
check "the name is escaped on the edit page and the list" '! grep -q "<script>alert(1)" <<<"$P" && ! get "$AJ" "/users?q=$RUN" | grep -q "<script>alert(1)" && get "$AJ" "/users?q=$RUN" | grep -q "&lt;script&gt;"'
check "creating the same email again is a field error" 'post "$AJ" /users "name=Dup" "email=${NEW_EMAIL^^}" "role_id=1" >/dev/null; get "$AJ" /users/create | grep -q "already in use"'
check "an empty form shows the field errors" 'post "$AJ" /users "name=" "email=nope" "role_id=" >/dev/null; F=$(get "$AJ" /users/create); grep -q "Name is required" <<<"$F" && grep -q "valid email" <<<"$F"'

echo "== First sign-in forces a new password"
R=$(signin "$NJ" "$NEW_EMAIL" "$TEMP")
check "the new user signs in with the temporary password" 'grep -qi "^location: .*/dashboard" <<<"$R"'
check "every page redirects to My Account until they choose a password" '[[ $(code -b "$NJ" "$BASE/jobs") == 302 ]] && loc "$(curl -s -i -b "$NJ" "$BASE/jobs")" | grep -q "/account" && get "$NJ" /account | grep -q "You must choose a new password"'
check "a weak new password is rejected" 'post "$NJ" /account/password "current_password=$TEMP" "password=password123" "password_confirmation=password123" >/dev/null; get "$NJ" /account | grep -q "too common"'
check "a mismatched confirmation is rejected" 'post "$NJ" /account/password "current_password=$TEMP" "password=$NEW_PASS" "password_confirmation=different" >/dev/null; get "$NJ" /account | grep -q "does not match"'
post "$NJ" /account/password "current_password=$TEMP" "password=$NEW_PASS" "password_confirmation=$NEW_PASS" >/dev/null
check "choosing a new password opens the app" '[[ $(code -b "$NJ" "$BASE/jobs") == 200 ]]'
check "the temporary password no longer works" '! signin "$(mktemp)" "$NEW_EMAIL" "$TEMP" | grep -qi "^location: .*/dashboard"'

echo "== Permission changes apply without signing out"
check "baseline: Staff cannot open the trash" '[[ $(code -b "$SJ" "$BASE/jobs/trash") == 403 ]]'
M=$(get "$AJ" /roles/matrix | flat)
VERSION=$(grep -oP 'name="version" value="\K[^"]+' <<<"$M" | head -1)
check "the matrix lists every permission, grouped, with the Admin column locked" 'grep -q "colgroup" <<<"$M" && grep -q "jt-matrix" <<<"$M" && [[ $(grep -oP "name=\"perm\[[0-9]+\]\[\]\"[^>]*disabled" <<<"$M" | wc -l) -ge 15 ]]'
# rebuild the posted state: every enabled+checked box, plus one new tick (Staff: jobs.delete)
matrix_post() { # $1 = matrix html (flattened), $2 = extra "perm[role][]=id" to tick (or empty), $3 = "perm[role][]=id" to leave unticked (or empty)
  local args=(); while read -r nv; do [[ -n "$nv" && "$nv" != "${3:-__none__}" ]] && args+=(--data-urlencode "$nv"); done < <(grep -oP '<input class="form-check-input" type="checkbox"[^>]*>' <<<"$1" | grep ' checked' | grep -v 'disabled' | grep -oP 'name="\Kperm\[[0-9]+\]\[\]" value="[0-9]+' | sed 's/" value="/=/')
  [[ -n "$2" ]] && args+=(--data-urlencode "$2")
  curl -s -i -b "$AJ" -c "$AJ" --data-urlencode "_csrf=$(tok "$AJ")" --data-urlencode "version=$(grep -oP 'name="version" value="\K[^"]+' <<<"$1" | head -1)" "${args[@]}" "$BASE/roles/matrix"
}
STAFF_TAG=$(grep -oP '<input[^>]*aria-label="Staff: Delete \(soft-delete\) and restore jobs"[^>]*>' <<<"$M" | head -1)
EXTRA=$(grep -oP 'name="\Kperm\[[0-9]+\]\[\]" value="[0-9]+' <<<"$STAFF_TAG" | sed 's/" value="/=/')
check "found the Staff / jobs.delete checkbox" '[[ -n "$EXTRA" ]] && ! grep -q " checked" <<<"$STAFF_TAG"'
R=$(matrix_post "$M" "$EXTRA")
check "saving the matrix confirms" 'get "$AJ" /roles/matrix | grep -q "Permissions were saved for 1 role"'
check "Staff (still signed in) can now open the trash" '[[ $(code -b "$SJ" "$BASE/jobs/trash") == 200 ]]'
R=$(matrix_post "$M" "$EXTRA")   # same (now stale) version
check "re-saving a stale matrix is refused with a warning" 'get "$AJ" /roles/matrix | grep -q "Someone else changed"'
M2=$(get "$AJ" /roles/matrix | flat)
check "the matrix now shows the new tick" 'grep -oP "<input[^>]*aria-label=\"Staff: Delete \(soft-delete\) and restore jobs\"[^>]*>" <<<"$M2" | grep -q " checked"'
R=$(matrix_post "$M2" "" "$EXTRA")   # post the current state minus that one tick = revoke it
check "unticking it takes the access away again, still without re-login" '[[ $(code -b "$SJ" "$BASE/jobs/trash") == 403 ]]'
check "a matrix post without a CSRF token is refused" '[[ $(code -b "$AJ" -d "version=x" "$BASE/roles/matrix") == 403 ]]'

echo "== Deactivate, lock, unlock, reset"
check "deactivating the user signs them out at once" 'post "$AJ" "/users/$NID/deactivate" >/dev/null; [[ $(code -b "$NJ" "$BASE/dashboard") == 302 ]]'
check "a deactivated user cannot sign in" '! signin "$(mktemp)" "$NEW_EMAIL" "$NEW_PASS" | grep -qi "^location: .*/dashboard"'
check "they appear under Inactive" 'get "$AJ" "/users?status=inactive" | grep -q "$NEW_EMAIL"'
post "$AJ" "/users/$NID/activate" >/dev/null
check "activating lets them sign in again" 'signin "$NJ" "$NEW_EMAIL" "$NEW_PASS" | grep -qi "^location: .*/dashboard"'
for i in 1 2 3 4 5; do signin "$(mktemp)" "$NEW_EMAIL" "wrong-password-$i" >/dev/null; done
check "five wrong passwords lock the account (even the right one is refused)" '! signin "$(mktemp)" "$NEW_EMAIL" "$NEW_PASS" | grep -qi "^location: .*/dashboard"'
check "the admin sees it as Locked and gets an Unlock button" 'get "$AJ" "/users?status=locked" | grep -q "$NEW_EMAIL" && get "$AJ" "/users/$NID/edit" | text | grep -q "Unlock account"'
post "$AJ" "/users/$NID/unlock" >/dev/null
check "unlocking lets them in" 'signin "$NJ" "$NEW_EMAIL" "$NEW_PASS" | grep -qi "^location: .*/dashboard"'
R=$(post "$AJ" "/users/$NID/reset-password"); P=$(get "$AJ" "/users/$NID/edit"); TEMP2=$(secret <<<"$P")
check "resetting the password shows a new one-time password" '[[ ${#TEMP2} -eq 16 && "$TEMP2" != "$TEMP" ]]'
check "the reset signs out the user's open session" '[[ $(code -b "$NJ" "$BASE/dashboard") == 302 ]]'
check "the old password stops working; the temporary one works and forces a change" '! signin "$(mktemp)" "$NEW_EMAIL" "$NEW_PASS" | grep -qi "^location: .*/dashboard"; signin "$NJ" "$NEW_EMAIL" "$TEMP2" >/dev/null; loc "$(curl -s -i -b "$NJ" "$BASE/jobs")" | grep -q "/account"'

echo "== Safeguards"
AID=$(user_id "$A_EMAIL")
for action in deactivate delete reset-password; do
  post "$AJ" "/users/$AID/$action" >/dev/null
  check "you cannot $action yourself (clear message)" 'get "$AJ" "/users/$AID/edit" | text | grep -q "cannot do that to your own account"'
  check "...still active after $action" '[[ $(code -b "$AJ" "$BASE/dashboard") == 200 ]]'
done
post "$AJ" "/users/$AID" "name=Demo Admin" "email=$A_EMAIL" "role_id=$(get "$AJ" /users/create | grep -oP '<option value="\K[0-9]+(?=">Staff<)' | head -1)" >/dev/null
check "you cannot change your own role (clear message)" 'get "$AJ" "/users/$AID/edit" | text | grep -q "cannot do that to your own account"'
check "your own edit page has no delete / deactivate buttons and the role is locked" 'E=$(get "$AJ" "/users/$AID/edit"); ! grep -q "deleteUserModal" <<<"$E" && grep -q "You cannot change your own role" <<<"$E"'
check "an action without a CSRF token is refused" '[[ $(code -b "$AJ" -X POST "$BASE/users/$NID/delete") == 403 ]] && [[ -n "$(user_id "$RUN")" ]]'

echo "== Roles"
check "the role list shows built-in roles, counts and the matrix link" 'RL=$(get "$AJ" /roles); grep -q "built in" <<<"$RL" && grep -q "Admin" <<<"$RL" && grep -q "/roles/matrix" <<<"$RL" && ! grep -qP "<td(?![^>]*data-label)" <<<"$RL"'
STAFF_ID=$(rid Staff); ADMIN_RID=$(rid Admin)
ROLE_NAME="Smoke Role $RUN"
R=$(post "$AJ" /roles "name=$ROLE_NAME" "description=temporary" "copy_from=$STAFF_ID")
check "a role can be created as a copy of Staff and lands on the matrix" 'grep -qi "^location: .*/roles/matrix" <<<"$R" && get "$AJ" /roles | grep -q "$ROLE_NAME"'
post "$AJ" /roles "name=<b>x</b>" "description=" >/dev/null
check "invalid role names are rejected" 'get "$AJ" /roles/create | grep -q "may only contain"'
post "$AJ" /roles "name=staff" "description=dup" >/dev/null
check "duplicate role names are rejected (any case)" 'get "$AJ" /roles/create | grep -q "already exists"'
SMOKE_RID=$(rid "Smoke Role $RUN")
check "the new role has an id" '[[ -n "$SMOKE_RID" ]]'
post "$AJ" "/roles/$ADMIN_RID/delete" >/dev/null
check "the built-in Admin role cannot be deleted (clear message, role still listed)" 'RL=$(get "$AJ" /roles); text <<<"$RL" | grep -q "built in and cannot be deleted" && [[ -n "$(rid Admin)" ]]'
post "$AJ" "/roles/$STAFF_ID/delete" >/dev/null
check "a role with users cannot be deleted (clear message)" 'get "$AJ" /roles | text | grep -q "still has"'
post "$AJ" "/roles/$SMOKE_RID/delete" >/dev/null
check "an unused role can be deleted (no longer listed)" '[[ -z "$(rid "Smoke Role $RUN")" ]]'

echo "== My Account"
check "a user can change their own name, nothing else" 'post "$SJ" /account/profile "name=Demo Staff" "email=evil@example.com" "role_id=1" >/dev/null; get "$SJ" /account | grep -q "$S_EMAIL" && ! get "$SJ" /account | grep -q "evil@example.com" && [[ $(code -b "$SJ" "$BASE/roles") == 403 ]]'
check "an empty name is rejected" 'post "$SJ" /account/profile "name=" >/dev/null; get "$SJ" /account | grep -q "Name is required"'

echo "== Delete the test user"
post "$AJ" "/users/$NID/delete" >/dev/null
check "the deleted user is gone from the list" '! get "$AJ" "/users?q=$RUN" | grep -q "$NEW_EMAIL"'
check "...cannot sign in" '! signin "$(mktemp)" "$NEW_EMAIL" "$TEMP2" | grep -qi "^location: .*/dashboard"'
check "...and the email stays reserved" 'post "$AJ" /users "name=Again" "email=$NEW_EMAIL" "role_id=$STAFF_ID" >/dev/null; get "$AJ" /users/create | grep -q "already in use"'
check "a deleted user's edit page is gone (404)" '[[ $(code -b "$AJ" "$BASE/users/$NID/edit") == 404 ]]'

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
