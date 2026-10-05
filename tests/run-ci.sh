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
web_pid=""
cleanup() {
  outcome=$?
  trap - EXIT
  if [[ $outcome -ne 0 ]]; then
    for log in "$work/db.log" "$work/php.log" "$work/apache.log" "$work/install.log"; do
      if [[ -f "$log" ]]; then tail -n 50 "$log"; fi
    done
  fi
  # GitHub-hosted runners clean orphan child processes themselves. Explicitly
  # stopping Apache/MariaDB there can race the runner's own teardown signal.
  if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then
    if [[ -n "$web_pid" ]]; then kill "$web_pid" 2>/dev/null || true; wait "$web_pid" 2>/dev/null || true; fi
    if [[ -n "$db_pid" ]]; then kill "$db_pid" 2>/dev/null || true; wait "$db_pid" 2>/dev/null || true; fi
  fi
  # Keep the temporary fixture for failure diagnosis; hosted runners discard it afterwards.
  return "$outcome"
}
trap cleanup EXIT
mkdir -p "$work/db" "$work/sessions" "$work/public" "$work/private" "$work/apache-runtime"
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
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase1-normalization.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase2-attendance.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/resolve-legacy-attendance.php --attendance-id=105 --academic-year=2026-2027 --semester=1st --company-id=1 --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/resolve-legacy-attendance.php --attendance-id=105 --academic-year=2026-2027 --semester=1st --company-id=1 --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase2-attendance.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase3-journals.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase3-journals.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase4-requirements.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase4-requirements.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase5-reports.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase5-reports.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase6-evaluations.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase6-evaluations.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/seed-phase7-certificates-announcements.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase7-certificates-announcements.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --apply
# Use the same web-server family as XAMPP and exercise real .htaccess denies.
php_module="$(find /usr/lib/apache2/modules -maxdepth 1 -name 'libphp*.so' -print -quit)"
if [[ -z "$php_module" ]]; then echo "Install libapache2-mod-php" >&2; exit 1; fi
cat > "$work/apache.conf" <<CONF
ServerRoot /etc/apache2
DefaultRuntimeDir "$work/apache-runtime"
PidFile "$work/apache.pid"
Listen 127.0.0.1:8087
ServerName localhost
User $(id -un)
Group $(id -gn)
LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule php_module "$php_module"
TypesConfig /etc/mime.types
ErrorLog "$work/apache.log"
DocumentRoot "$work/public"
DirectoryIndex index.php
LimitRequestBody 0
<Directory />
  Require all denied
</Directory>
<Directory "$work/public">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
<Directory "$repo">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
<FilesMatch "[.]php$">
  SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value mysqli.default_socket "$OJTRACK_TEST_SOCKET"
php_admin_value session.save_path "$work/sessions"
php_admin_value upload_max_filesize 10M
php_admin_value post_max_size 32M
php_admin_flag display_errors Off
php_admin_flag log_errors On
php_admin_value error_log "$work/php.log"
CONF
apache2 -t -f "$work/apache.conf"
apache2 -f "$work/apache.conf" -DFOREGROUND &
web_pid=$!
for attempt in {1..30}; do
  if curl --fail --silent "${OJTRACK_TEST_URL}login.php" >/dev/null; then break; fi
  if ! kill -0 "$web_pid" 2>/dev/null; then exit 1; fi
  sleep 1
done
python3 tests/integration.py
python3 tests/uploads.py
python3 tests/migration.py
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase9-retirement.php
python3 tests/phase9-web-smoke.py
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/rollback-normalized-phase9-retirement.php --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/rollback-normalized-phase9-retirement.php --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/phase9-retirement.php
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/cleanup-normalized-migration-artifacts.php --dry-run
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/cleanup-normalized-migration-artifacts.php --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" bin/cleanup-normalized-migration-artifacts.php --apply
php -d mysqli.default_socket="$OJTRACK_TEST_SOCKET" tests/cleanup-normalized-artifacts.php
python3 tests/phase9-web-smoke.py
