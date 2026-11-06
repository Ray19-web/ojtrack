# Web release verification - 1 November 2026

## Executed checks

- Architecture migration validation: **211 web PHP files passed** syntax checks; compatibility wrappers preserved existing route and include paths; all selected service regression checks passed; `mobile/` was excluded and unchanged.
- Local unauthenticated route smoke checks passed for admin, coordinator, company, and student dashboards and representative protected pages. Each correctly redirected and returned HTTP 200 after redirect.

- PHP syntax: **211 web PHP files passed**, including maintained application, compatibility, test, and setup files; `mobile/` was excluded.
- Shared browser JavaScript: **8 files passed** syntax validation.
- **18 PHP regression commands passed**: coordinator matching, storage, onboarding, student IDs/profile creation, company creation, student evaluation access/rendering, student conversations, evaluation PDFs, journal upload cleanup, conversation archiving, coordinator/record filters, archived-student review boundaries, requirement editing, BSIT scoring/submission, and lean training-assignment synchronization. Injected duplicate/write failures are expected test cases, not failed commands.
- Student guide browser tests: **32 checks passed**; confirmation browser tests: **38 checks passed**. Fixtures submit no real application data.
- Fresh `database/install.sql` import passed in a separately created disposable database: **51 tables/views**, **19 migration markers**, and **zero accounts** before synthetic setup. The schema contains no application-record inserts.
- The first-admin CLI created a synthetic administrator on that empty schema.
- Monthly report integration: **8 checks passed**, including storage-failure rollback of template/assignment/submission, successful PDF persistence, saved evidence snapshot, and coordinator attachment visibility. The test uses a guarded `ojtrack_release_verify_*` database and temporary private files.
- Authenticated HTTP smoke tests passed on **33 pages** using synthetic accounts in the disposable installation: admin 10, coordinator 9, company 6, student 8. Each page returned HTTP 200 without PHP warnings/fatal errors and retained the correct signed-in session. The student fixture's enrollment was explicitly marked setup-complete before accessing protected pages.
- The paper-style evaluation PDF fixtures and desktop/phone guide/dialog fixtures were visually inspected during their implementation. The ordinary six-category evaluation form fits one A4 page; long-content fixtures remain paginated.
- Local Apache checks: `/ojtrack/login` returned HTTP 200; direct requests to `.git/config`, `config/db.php`, `database/install.sql`, `bin/create-admin.php`, `tests/student-guide.cjs`, `docs/DEPLOYMENT.md`, and private requirements uploads returned HTTP 403.
- Current local release check: PHP `zip`, `gd`, and `mysqli` extensions are enabled; the allowlisted web archive built successfully with **173 packaged files**, included the migrated `app/` and `frontend/` trees, and contained **zero `mobile/` entries**.
- This XAMPP CLI configuration has GD and ZIP installed but disabled by default. The release build loads ZIP explicitly for the build process. Enable both extensions in the deployment PHP configuration before accepting image/Office-document workflows; the provided preflight reports missing extensions rather than silently ignoring them. No global XAMPP service configuration or service restart was performed.

## Package checks

Profile follow-up: **32 database read/write checks passed** on `ojtrack_release_verify_profiles_20261101`, including protected-field attempts, optional student email, validation, duplicate email rejection, missing-enrollment display, and injected failure rollback. **54 authenticated browser checks passed** across all four roles at desktop and phone widths, including modal rendering, read-only records, student hours, optional email, scrolling, and reachable save actions. These used synthetic records and a local development router; the live application database was not modified.

The allowlist builder includes only runtime/setup/documentation inputs and writes a per-file SHA-256 manifest. Final ZIP inspection verifies every manifest hash and excludes source-control directories, unrelated applications, development/test directories, populated data exports, personal rosters, local storage configuration, and uploaded media/documents. Only upload access-control files are included.

## Scope and remaining deployment acceptance

The isolated HTTP smoke tests use a development router; they establish application rendering/authentication on the fresh schema, not the behavior of a separate deployed server. Local Apache route/access-rule checks are recorded in the finalization report. HTTPS certificates/cookies, service-account permissions, mail/external services if configured, production load/concurrency, real-device browsers, and backup restoration must be checked on the intended deployment host. The full historical Linux migration CI sequence was not rerun on Windows; staged legacy migration tools are not part of the deployment ZIP.

The existing local application's users, training records, and uploaded documents were not deleted. The release contains an empty installation schema, not a backup of those records. No production deployment was performed.
