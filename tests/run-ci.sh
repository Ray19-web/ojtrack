#!/usr/bin/env bash
set -euo pipefail
if [[ "${OJTRACK_TEST_CONFIRM:-}" != synthetic ]]; then
  echo "Set OJTRACK_TEST_CONFIRM=synthetic. This runner creates synthetic uploads in this checkout." >&2
  exit 2
fi
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo"
work="$(mktemp -d /tmp/ojtrack-ci.XXXXXXXX)"
db_pid=""
php_pid=""
cleanup() {
  outcome=$?
  trap - EXIT
  if [[ $outcome -ne 0 ]]; then
    for log in "$work/db.log" "$work/php.log" "$work/install.log"; do
      if [[ -f "$log" ]]; then tail -n 50 "$log"; fi
    done
  fi
  if [[ -n "$php_pid" ]]; then kill "$php_pid" 2>/dev/null || true; wait "$php_pid" 2>/dev/null || true; fi
  if [[ -n "$db_pid" ]]; then kill "$db_pid" 2>/dev/null || true; wait "$db_pid" 2>/dev/null || true; fi
  # Keep the temporary fixture for failure diagnosis; hosted runners discard it afterwards.
  exit "$outcome"
}
trap cleanup EXIT
mkdir -p "$work/db" "$work/sessions" "$work/public" "$work/private"
ln -s "$repo" "$work/public/ojtrack"
export OJTRACK_DB_HOST=localhost OJTRACK_DB_USER=root OJTRACK_DB_PASS="" OJTRACK_DB_NAME=ojtrack_test
export OJTRACK_TEST_SOCKET="$work/db.sock"
export OJTRACK_TEST_URL=http://127.0.0.1:8087/ojtrack/
export OJTRACK_PRIVATE_UPLOAD_DIR="$work/private"
export OJTRACK_PUBLIC_ROOT="$work/public"
mariadb-install-db --no-defaults --datadir="$work/db" --auth-root-authentication-method=normal --skip-test-db >"$work/install.log" 2>&1
mariadbd --no-defaults --datadir="$work/db" --socket="$OJTRACK_TEST_SOCKET" --pid-file="$work/db.pid" --skip-networking --user="$(id -un)" --log-error="$work/db.log" &
db_pid=$!
for attempt in {1..60}; do
  if mariadb-admin --no-defaults --socket="$OJTRACK_TEST_SOCKET" -uroot ping >/dev/null 2>&1; then break; fi
  if ! kill -0 "$db_pid" 2>/dev/null; then exit 1; fi
  sleep 1
done
mariadb --no-defaults --socket="$OJTRACK_TEST_SOCKET" -uroot -e 'CREATE DATABASE ojtrack_test'
mariadb --no-defaults --socket="$OJTRACK_TEST_SOCKET" -uroot ojtrack_test < database/schema.sql
while IFS= read -r -d '' source; do php -l "$source" >/dev/null; done < <(find . -name '*.php' -not -path './uploads/*' -not -path './.git/*' -print0)
echo "PHP lint passed"
node --check assets/js/main.js
php tests/storage.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" -d session.save_path="$work/sessions" -d display_errors=1 -d upload_max_filesize=10M -d post_max_size=32M -S 127.0.0.1:8087 -t "$work/public" "$repo/tests/router.php" >"$work/php.log" 2>&1 &
php_pid=$!
for attempt in {1..30}; do
  if curl --fail --silent "${OJTRACK_TEST_URL}login.php" >/dev/null; then break; fi
  if ! kill -0 "$php_pid" 2>/dev/null; then exit 1; fi
  sleep 1
done
python3 tests/integration.py
python3 tests/uploads.py
python3 tests/migration.py
