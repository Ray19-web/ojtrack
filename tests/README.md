# Synthetic integration checks

Run `php tests/coordinator-assignment.php` for database-free program/coordinator matching checks, including legacy names, inactive accounts, exact-match priority, and separate mechanical/electrical programs.

## Responsive UI fixtures (no database)

Create an empty temporary directory and run `php tests/render-responsive-fixtures.php <absolute-output-directory>`. Set `OJTRACK_UI_FIXTURE_DIR` to that directory and start `php -S 127.0.0.1:8091 tests/responsive-router.php` from the repository root. The fixture-only router serves application CSS/JS/images and the generated HTML; it never serves application PHP routes or uploads. Stop the server after checking.

Open `/ojtrack/ui-fixtures/admin.html`, `coordinator.html`, `company.html`, `student.html`, and `onboarding.html` on that host. These use the real shared header/footer and representative synthetic components, not authenticated workflows. Check 320×740, 390×844, 768×1024, 820×1180, 1024×768, 1440×900, and 844×390. Assert no document overflow; tables scroll within their cards; filters, controls, and certificate signatures stay visible; detail grids stack; chat lists/messages scroll independently and the composer remains accessible. Open and close the mobile drawer, notifications, profile, and a long details dialog; verify Escape, focus return, dialog scrolling, and rotating/resizing with the drawer open. Repeat workflow checks with authenticated synthetic accounts before release. Real device keyboards, Safari, and native date/file pickers need device testing.

## Database integration

Requires PHP CLI with mysqli/fileinfo/GD/ZIP, MariaDB client/server, Python 3, and Node.js. These scripts are development-only and must not be deployed.

1. Create an isolated database named **ojtrack_test** and import database/schema.sql. It must be empty before seeding.
2. Set OJTRACK_DB_NAME=ojtrack_test and the connection environment variables, then run php tests/seed.php. For a Unix socket, pass php -d mysqli.default_socket=/path/to/socket.
3. Serve the project at /ojtrack/ through Apache with the documented deny rules, or a local PHP test server with an explicit router denying protected directories. PHP's built-in server does not enforce .htaccess.
4. Set OJTRACK_TEST_CONFIRM=synthetic, OJTRACK_TEST_URL=http://127.0.0.1:8087/ojtrack/, and OJTRACK_TEST_SOCKET=/path/to/socket, then run python tests/integration.py.
5. The suite uses the MariaDB client with the root user over the specified **isolated** socket and the hardcoded ojtrack_test database. Adapt credentials for your disposable environment if needed.
6. Recreate the disposable database and seed it before rerunning. Tests change a password, notifications, journals and evaluation records. Synthetic private files are created beneath uploads and ignored by git.

The direct-file denial assertion verifies the configured test host/router, not deployment correctness. Repeat it on Apache/XAMPP. The tests do not perform visual or concurrent multi-worker testing.

After integration.py passes, run tests/uploads.py against the same disposable database with OJTRACK_TEST_CONFIRM=synthetic. This second suite expects the changed synthetic password and uses real multipart uploads. Configure the test PHP server with upload_max_filesize=10M and post_max_size=32M. It creates synthetic documents/images, updates fixtures and checks invalid-file rejection, no partial batch preflight mutations, image normalization, ownership and size limits.


Private-storage follow-up: create a separate empty directory outside the test server's document root. Pass its absolute path as OJTRACK_PRIVATE_UPLOAD_DIR to both PHP and tests/uploads.py. The legacy seed documents remain beneath uploads so the original integration suite still exercises fallback. New uploaded requirements/reports/proofs are expected in the private root; avatars stay in uploads.

Run php tests/storage.php for isolated path/configuration/symlink checks. It does not use a database and cleans up only its own temporary fixtures. Run the copy command first in dry-run mode against the synthetic database, then copy mode, then repeat copy mode to check that matching files are recognized. The command must report conflicts without replacing destination content. Also verify missing configuration rejects new private uploads with 503, while old authorized downloads continue working.

These new private-storage checks were authored but could not be executed in the latest assistant environment because PHP/MariaDB were unavailable. Earlier reported 414+67 passes apply to the previous upload-validation commit, not this follow-up.

Run `php tests/conversation-archiving.php` for archive migration and behavior checks. It uses only connection-scoped temporary tables to verify per-member isolation, non-member rejection, restoration, message preservation, new-message reopening, folder filtering and counts. It requires the configured local MariaDB connection but never changes application records.

Run `php tests/onboarding-flow.php` for isolated controller checks covering completed and stale forms, redirect-after-submit behavior, one-time feedback, pending approval, and prevention of replacements for approved documents. Uses synthetic in-memory records with no database or file writes.

Run `php tests/student-id-entry.php` for manual/automatic student ID creation checks: exact and leading-zero IDs, initial password, validation, duplicate IDs (including archived accounts), and rollback on a concurrent duplicate. Uses the real controller with in-memory persistence; no database writes.

Run `php tests/student-creation-flow.php` for the Students page creation flow, including its local modal, return destination, one-time confirmation, coordinator assignment, input validation and transaction rollback. Also runs the student ID checks; all records are synthetic and in memory.

Run `php tests/coordinator-filters.php` to check coordinator search, combined program/status filters, legacy program names, literal wildcards and malformed inputs. Requires local MariaDB and uses only connection-scoped temporary tables; application records are unchanged.

Run `php tests/record-filters.php` for archived accounts, announcement categories/audiences, certificate search and inclusive issue dates. Exercises the real certificate list controller to verify company/coordinator access boundaries. Uses connection-scoped temporary tables only; application records are unchanged.

Run `php tests/company-registration.php` for company registration validation, visible error/retry state, preserved form fields, password handling, duplicate email and transaction rollback. Runs the real controller with in-memory persistence; no application records are written.

Run php tests/archived-student-reviews.php for archive filtering and stale-action checks across coordinator requirements, reports, journals, and evaluations. Covers both archive markers, restoration, history preservation, and blocked attendance/assignment actions. Uses connection-scoped temporary tables; application records are unchanged.

Run php tests/requirement-editing.php for requirement-library editing checks. Covers ownership, archived definitions, validation, stale edits, latest-version selection, and preservation of existing assignments and approved submissions. Uses connection-scoped temporary tables only.

Run python tests/logout.py for onboarding sign-out and HTTP session-destruction checks. Starts an isolated PHP server with synthetic sessions and verifies CSRF rejection, login redirection, cookie expiration, and denial of access with the old session. Uses no real user accounts.

Run node tests/confirmations.cjs with Playwright available to test shared confirmation dialogs. Uses synthetic browser forms only. Covers Cancel/Confirm/Escape, focus trapping, existing modals, validation, CSRF/file/submitter preservation, approval/return/restore labels, GET filters, already-confirmed logout, text escaping, duplicate prompts, and mobile sizes. No application records are changed.

Run php tests/training-assignment-sync.php and repeat with --lean for atomic company assignment synchronization checks. Covers dashboard and company-list visibility, repeated saves, reassignment, unassignment, preserved history, invalid companies, and archived students. Uses temporary tables only.

Run php tests/bsit-evaluation-scoring.php and php tests/points-evaluation-submission.php for paper category totals, 90/91 boundaries, point validation, percentage regression, actual submission persistence, and company form input maxima. The submission test uses temporary tables and synthetic notifications only.
