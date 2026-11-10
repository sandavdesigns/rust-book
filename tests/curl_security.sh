#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PORT="${PORT:-21114}"
BASE="http://127.0.0.1:${PORT}"
TEST_TMP="$(mktemp -d)"
SERVER_PID=""
cleanup() {
    if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi
    rm -rf "$TEST_TMP"
}
trap cleanup EXIT
export RUSTDESK_API_DATABASE_PATH="$TEST_TMP/test.sqlite3"
export RUSTDESK_API_TRUSTED_PROXIES=""
export RUSTDESK_API_LOGIN_MAX_FAILURES=3
php scripts/migrate.php >/dev/null
start_server() {
    php -S "127.0.0.1:${PORT}" -t public public/index.php >"$TEST_TMP/server.log" 2>&1 &
    SERVER_PID="$!"
    for _ in $(seq 1 80); do
        if curl -sS -o /dev/null "$BASE/health" 2>/dev/null; then return; fi
        sleep 0.1
    done
    cat "$TEST_TMP/server.log" >&2; exit 1
}
status() { curl -sS -o "$TEST_TMP/response" -w '%{http_code}' "$@"; }
expect() { if [ "$1" != "$2" ]; then echo "Expected HTTP $1, got $2: $3" >&2; cat "$TEST_TMP/response" >&2; exit 1; fi; }
export RUSTDESK_API_REQUIRE_HTTPS=1
start_server
expect 426 "$(status "$BASE/health")" 'HTTP refused by default, including loopback'
expect 426 "$(status -H 'X-Forwarded-Proto: https' "$BASE/health")" 'untrusted proxy cannot forge TLS'
kill "$SERVER_PID"; wait "$SERVER_PID" 2>/dev/null || true; SERVER_PID=""
export RUSTDESK_API_REQUIRE_HTTPS=0 # Disposable loopback test only.
start_server
expect 200 "$(status "$BASE/health")" 'explicit test HTTP works'
php -r 'echo str_repeat("x", 1048577);' >"$TEST_TMP/large"
expect 413 "$(status -H 'Content-Type: application/json' --data-binary @"$TEST_TMP/large" "$BASE/api/login")" 'Content-Length body limit'
expect 413 "$(status -H 'Content-Type: application/json' -H 'Transfer-Encoding: chunked' --data-binary @"$TEST_TMP/large" "$BASE/api/login")" 'streaming body limit'
expect 413 "$(status -H 'Content-Type: application/x-www-form-urlencoded' -H 'Transfer-Encoding: chunked' --data-binary @"$TEST_TMP/large" "$BASE/admin/login")" 'chunked admin form limit'
expect 415 "$(status -F 'test=fixture' "$BASE/admin/login")" 'unsupported multipart body refused'
expect 400 "$(status -H 'Content-Type: application/json' --data-binary '{"username":[],"password":"wrong"}' "$BASE/api/login")" 'non-string login field'
php -r 'echo json_encode(array("username" => str_repeat("a",129), "password" => "wrong"));' >"$TEST_TMP/login"
expect 400 "$(status -H 'Content-Type: application/json' --data-binary @"$TEST_TMP/login" "$BASE/api/login")" 'bounded username'
php -r 'echo json_encode(array("username" => "alpha", "password" => str_repeat("x",4097)));' >"$TEST_TMP/login"
expect 400 "$(status -H 'Content-Type: application/json' --data-binary @"$TEST_TMP/login" "$BASE/api/login")" 'bounded password'
for name in alpha beta gamma; do
    payload="$(printf '{"username":"%s","password":"wrong"}' "$name")"
    actual="$(status -H 'Content-Type: application/json' --data-binary "$payload" "$BASE/api/login")"
    expect 401 "$actual" 'initial attempt'
done
expect 429 "$(status -H 'Content-Type: application/json' --data-binary '{"username":"delta","password":"wrong"}' "$BASE/api/login")" 'cross-username IP throttle'
echo 'HTTP security tests passed: TLS enforcement, spoofed header rejection, declared/chunked body limits, login spraying.'
