#!/bin/sh
# End-to-end check against a running stack: ./docker/smoke-test.sh [base-url]
set -eu

BASE="${1:-http://localhost:8000}"
EMAIL="smoke-$(date +%s)@example.test"
fail() { echo "FAIL: $1"; exit 1; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Waiting for $BASE/up ..."
i=0
until [ "$(status "$BASE/up")" = "200" ]; do
    i=$((i + 1)); [ "$i" -gt 60 ] && fail "app did not become healthy"
    sleep 3
done

JSON='-H Accept:application/json -H Content-Type:application/json'

TOKEN=$(curl -s -X POST "$BASE/api/signup" $JSON \
    -d "{\"name\":\"Smoke Test\",\"email\":\"$EMAIL\",\"phone\":\"080$(date +%s | tail -c 9)\",\"password\":\"secret-pass\",\"password_confirmation\":\"secret-pass\"}" \
    | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] || fail "signup did not return a token"

AUTH="Authorization: Bearer $TOKEN"
[ "$(status "$BASE/api/user" -H 'Accept: application/json' -H "$AUTH")" = "200" ] || fail "token rejected on /api/user"
[ "$(status "$BASE/api/dashboard" -H 'Accept: application/json' -H "$AUTH")" = "200" ] || fail "dashboard"
[ "$(status "$BASE/api/payment" -H 'Accept: application/json')" = "401" ] || fail "guest reached /api/payment"
[ "$(status "$BASE/api/admin/users" -H 'Accept: application/json' -H "$AUTH")" = "403" ] || fail "normal user reached admin"
[ "$(status "$BASE/api/get-users" -H 'Accept: application/json')" = "404" ] || fail "user dump endpoint exists"
[ "$(status -X POST "$BASE/settlement_notif" $JSON -d '{}')" = "403" ] || fail "unsigned webhook accepted"

echo "Smoke test passed"
