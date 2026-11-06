# Web regression checks

Tests are maintained with the source checkout and excluded from the deployment ZIP. Use synthetic accounts and disposable storage/databases only. Current executed results and their limits are in `docs/VERIFICATION.md`.

## Database-free checks

Run with PHP CLI:

- `php tests/coordinator-assignment.php`
- `php tests/storage.php`
- `php tests/onboarding-flow.php`
- `php tests/student-id-entry.php`
- `php tests/student-creation-flow.php`
- `php tests/company-registration.php`
- `php tests/student-evaluation.php`
- `php tests/student-conversations.php`
- `php tests/evaluation-pdf.php`
- `php tests/journal-upload-cleanup.php`
- `php tests/bsit-evaluation-scoring.php`

The controller tests use in-memory persistence. Storage/journal checks create and clean up only their own temporary files. The PDF test accepts an optional existing output directory for synthetic fixtures; render the PDFs and inspect ordinary and continuation pages after layout changes.

## Temporary-table checks

Configure `OJTRACK_DB_*` for an isolated database initialized from `database/install.sql`. These commands use connection-scoped temporary tables rather than application records:

- `php tests/conversation-archiving.php`
- `php tests/coordinator-filters.php`
- `php tests/record-filters.php`
- `php tests/archived-student-reviews.php`
- `php tests/requirement-editing.php`
- `php tests/points-evaluation-submission.php`
- `php tests/training-assignment-sync.php --lean`

## Monthly report integration

`php tests/release-monthly-report.php` deliberately writes synthetic records into a disposable database whose configured name must begin `ojtrack_release_verify_`. Start with an empty imported release schema. The test exercises failed-storage rollback, PDF generation/persistence, the evidence snapshot, and coordinator attachment visibility. It cleans up its temporary PDF storage; discard the whole separately created test database after verification. Never point this command at an application database.

## Browser checks

`php tests/profiles.php` exercises the shared profile read/write helper on the same guarded disposable database after synthetic first-admin setup and `release-monthly-report.php`. It checks role-specific information, protected fields, validation, optional student contact email, and transactional rollback. It changes synthetic fixture records; never run it against real application data.

With Playwright and an installed Chromium/Edge available, run:

- `node tests/student-guide.cjs` (optional existing screenshot output directory)
- `node tests/confirmations.cjs`

The tests use synthetic browser forms/pages, not real messages or submissions. Configure normal Node module resolution or `NODE_PATH` to the test dependency directory; browser test dependencies are not deployment dependencies. Repeat authenticated workflow and real-device acceptance before deployment.

## Legacy migration coverage

`tests/run-ci.sh` and the staged `phase*` fixtures exercise the historical schema/migration path on a separately provisioned Linux test server. They require MariaDB/Apache/PHP/Python/Node, create disposable resources, and must not run against real data. They are retained for existing-installation maintenance and were not claimed as rerun in the Windows web release checks. A new release installation uses `database/install.sql`, not the legacy test seed or schema.
