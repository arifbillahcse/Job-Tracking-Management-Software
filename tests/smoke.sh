#!/usr/bin/env bash
# End-to-end smoke test over real HTTP against a running instance.
#   tests/smoke.sh http://127.0.0.1:8099 admin@example.com 'the-admin-password'
# WARNING: it changes the account's password at the end of the run (and restores it), so use a dev database.
set -u

BASE="${1:?base url}"; USER_="${2:?admin username}"; PASS="${3:?admin password}"
JAR="$(mktemp)"; JAR2="$(mktemp)"; PASSED=0; FAILED=0
trap 'rm -f "$JAR" "$JAR2"' EXIT

ok()   { PASSED=$((PASSED+1)); echo "  ok   $1"; }
bad()  { FAILED=$((FAILED+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

token() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== Login page and headers"
HEAD=$(curl -s -i -c "$JAR" "$BASE/login")
check "login page served" '[[ "$HEAD" == HTTP/*200* ]]'
for h in Content-Security-Policy X-Frame-Options X-Content-Type-Options Referrer-Policy Cache-Control; do
  check "header $h present" 'grep -qi "^$h:" <<<"$HEAD"'
done
check "session cookie is HttpOnly + SameSite" 'grep -i "^set-cookie: JT_SESSID" <<<"$HEAD" | grep -qi "HttpOnly" && grep -i "^set-cookie: JT_SESSID" <<<"$HEAD" | grep -qi "SameSite=Lax"'
check "X-Powered-By is not exposed" '! grep -qi "^x-powered-by" <<<"$HEAD"'
T=$(curl -s -b "$JAR" -c "$JAR" "$BASE/login" | token)
check "CSRF token present in the form" '[[ ${#T} -eq 64 ]]'

echo "== CSRF"
check "POST /login without a token -> 403" '[[ $(code -b "$JAR" -c "$JAR" -d "username=$USER_&password=x" "$BASE/login") == 403 ]]'
check "POST /login with a bad token -> 403" '[[ $(code -b "$JAR" -c "$JAR" -d "_csrf=deadbeef&username=$USER_&password=x" "$BASE/login") == 403 ]]'
check "cross-origin POST with a valid token -> 403" '[[ $(code -b "$JAR" -c "$JAR" -H "Origin: https://evil.example" --data-urlencode "_csrf=$T" --data-urlencode "username=$USER_" --data-urlencode "password=$PASS" "$BASE/login") == 403 ]]'

echo "== Failed logins are indistinguishable"
login() { # email password -> prints the page shown after redirect
  local t; t=$(curl -s -b "$JAR" -c "$JAR" "$BASE/login" | token)
  curl -s -L -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" --data-urlencode "username=$1" --data-urlencode "password=$2" "$BASE/login"
}
P1=$(login "$USER_" "wrong-password-123")
P2=$(login "nobody-here@example.com" "wrong-password-123")
check "wrong password shows the generic message" 'grep -q "Invalid username or password." <<<"$P1"'
check "unknown username shows the same message" 'grep -q "Invalid username or password." <<<"$P2"'
P3=$(login "x\"><script>alert(1)</script>@example.com" "whatever-123")
check "XSS string in the username is not echoed unescaped" '! grep -q "<script>alert(1)</script>" <<<"$P3"'
P4=$(login "$USER_" "' OR '1'='1")
check "SQL-injection string as password does not sign in" 'grep -q "Invalid username or password." <<<"$P4"'

echo "== Protected pages while signed out"
check "/dashboard redirects to /login" '[[ $(code "$BASE/dashboard") == 302 ]]'
check "/account redirects to /login" '[[ $(code "$BASE/account") == 302 ]]'
check "GET /logout is not allowed (POST only) -> 405 or redirect" '[[ $(code -b "$JAR" "$BASE/logout") =~ ^(405|302)$ ]]'

echo "== Sign in"
BEFORE=$(grep JT_SESSID "$JAR" | awk '{print $7}')
t=$(curl -s -b "$JAR" -c "$JAR" "$BASE/login" | token)
R=$(curl -s -i -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" --data-urlencode "username=$USER_" --data-urlencode "password=$PASS" "$BASE/login")
check "valid login redirects to the dashboard" 'grep -qi "^location: .*/dashboard" <<<"$R"'
AFTER=$(grep JT_SESSID "$JAR" | awk '{print $7}')
check "session id changed on login" '[[ -n "$BEFORE" && -n "$AFTER" && "$BEFORE" != "$AFTER" ]]'
D=$(curl -s -b "$JAR" -c "$JAR" "$BASE/dashboard")
check "dashboard shows the signed-in user and role" 'grep -q "Welcome" <<<"$D" && grep -q "Admin" <<<"$D"'
check "dashboard shows the job figures and section cards" 'grep -q "jt-stat" <<<"$D" && grep -q "Ready for delivery" <<<"$D"'
check "/login redirects signed-in users away" '[[ $(code -b "$JAR" "$BASE/login") == 302 ]]'
check "unknown URL -> 404 page (no stack trace)" 'N=$(curl -s -b "$JAR" "$BASE/does-not-exist"); grep -q "Page not found" <<<"$N" && ! grep -q "Stack trace" <<<"$N"'
check "wrong method -> 405" '[[ $(code -b "$JAR" -X POST "$BASE/dashboard") =~ ^(403|405)$ ]]'

echo "== Change password"
A=$(curl -s -b "$JAR" -c "$JAR" "$BASE/account"); t=$(token <<<"$A")
post_pw() { curl -s -L -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" --data-urlencode "current_password=$1" --data-urlencode "password=$2" --data-urlencode "password_confirmation=$3" "$BASE/account/password"; }
check "wrong current password rejected" 'grep -q "current password is incorrect" <<<"$(post_pw wrong-current-1 "new passphrase long" "new passphrase long")"'
A=$(curl -s -b "$JAR" -c "$JAR" "$BASE/account"); t=$(token <<<"$A")
check "weak new password rejected" 'grep -q "too common" <<<"$(post_pw "$PASS" "password123" "password123")"'
A=$(curl -s -b "$JAR" -c "$JAR" "$BASE/account"); t=$(token <<<"$A")
check "mismatched confirmation rejected" 'grep -q "confirmation does not match" <<<"$(post_pw "$PASS" "new passphrase long" "different passphrase")"'

NEW="rotated passphrase 2026"
# second browser session, to prove a password change signs out other sessions
t2=$(curl -s -b "$JAR2" -c "$JAR2" "$BASE/login" | token)
curl -s -o /dev/null -b "$JAR2" -c "$JAR2" --data-urlencode "_csrf=$t2" --data-urlencode "username=$USER_" --data-urlencode "password=$PASS" "$BASE/login"
check "second session is signed in" '[[ $(code -b "$JAR2" "$BASE/dashboard") == 200 ]]'

A=$(curl -s -b "$JAR" -c "$JAR" "$BASE/account"); t=$(token <<<"$A")
check "valid password change succeeds" 'grep -q "password has been updated" <<<"$(post_pw "$PASS" "$NEW" "$NEW")"'
check "the changing session stays signed in" '[[ $(code -b "$JAR" "$BASE/dashboard") == 200 ]]'
check "the other session is signed out" '[[ $(code -b "$JAR2" "$BASE/dashboard") == 302 ]]'

echo "== Logout"
t=$(curl -s -b "$JAR" -c "$JAR" "$BASE/dashboard" | token)
check "logout (POST + token) redirects to /login" '[[ $(code -b "$JAR" -c "$JAR" --data-urlencode "_csrf=$t" "$BASE/logout") == 302 ]]'
check "dashboard is closed again after logout" '[[ $(code -b "$JAR" "$BASE/dashboard") == 302 ]]'
check "old password no longer works" 'grep -q "Invalid username or password." <<<"$(login "$USER_" "$PASS")"'

echo "== Restore the original password"
P=$(login "$USER_" "$NEW")
check "new password signs in" 'grep -q "Welcome" <<<"$P"'
A=$(curl -s -b "$JAR" -c "$JAR" "$BASE/account"); t=$(token <<<"$A")
check "original password restored" 'grep -q "password has been updated" <<<"$(post_pw "$NEW" "$PASS" "$PASS")"'

echo
echo "$PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
