# OJTrack Database Migration Policy

The final target model is `../schema.normalized.sql`.

Do not create a destructive "one shot" migration from the current database. OJTrack contains historical OJT records and uploaded files, so normalization must be staged and reversible.

## Planned migration packages

1. **001_core_identity_and_terms** — academic terms, OJT enrollments, company users, coordinator assignment history and placements.
2. **002_attendance** — attendance day/session model and correction audit trail.
3. **003_journals_and_attachments** — journal revisions and centralized private attachment metadata.
4. **004_requirements** — template/version/assignment/submission model.
5. **005_reports** — template/version/assignment/submission and evidence snapshots.
6. **006_evaluations** — immutable form versions, stable criteria, requests, submissions and answers.
7. **007_certificates_and_announcements** — issued certificate records and attachment linking.
8. **008_application_cutover** — switch PHP reads/writes to normalized tables after parity tests.
9. **009_legacy_retirement** — drop legacy columns/tables only after approved backup, parity and UAT.

Each executable migration must:
- record its version in `schema_migrations`;
- fail safely if prerequisites are not met;
- never delete source data in the same migration that first copies it;
- include a verification query/checklist;
- be rehearsed against a restored staging copy before production.


## Migration 001 — core identity, academic terms and placements

The executable runner is `bin/migrate-normalized-phase1.php`. It is additive: it creates normalized tables and copies current relationships without deleting or changing legacy rows.

Always run the preflight first, using the real academic term represented by the existing student records:

```powershell
php bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply it:

```powershell
php bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --apply
```

A successful apply returns `PASS` and records `001_core_identity_and_terms` in `schema_migrations`. Running the same command again returns `ALREADY_APPLIED` and does not duplicate records.

If the preflight reports invalid/missing role users, programs, coordinators, companies or duplicate role profiles, fix those legacy records first. The runner refuses to migrate them silently.


## Migration 002 — normalized attendance

Migration 002 depends on migration 001. It converts the legacy one-row attendance model into placement-scoped attendance days and sessions while keeping the original `attendance` table unchanged.

Run the preflight first:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only apply when the result is `READY`:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --apply
```

The preflight blocks if a legacy attendance row cannot be tied to exactly one normalized placement. It prints the attendance ID, student, date and placement count so the legacy assignment can be corrected without guessing.

A successful apply:
- creates `attendance_days`, `attendance_sessions` and `attendance_corrections`;
- preserves legacy statuses and credited minutes;
- reconstructs generic or morning/afternoon punch sessions without inventing missing times;
- verifies row counts, statuses and credited minutes;
- hashes the legacy attendance rows before/after to ensure they were not changed;
- records `002_attendance` in `schema_migrations`.

The PHP application continues using the legacy attendance table until attendance read/write cutover is implemented and verified.


### Resolving an attendance row with missing historical placement

If migration 002 reports `attendance_without_exactly_one_placement`, do not guess or directly edit the legacy attendance row.

List the attendance details and current company candidates:

```powershell
C:\xampp\php\php.exe bin\resolve-legacy-attendance.php --attendance-id=18 --academic-year=2026-2027 --semester=1st --dry-run
```

After confirming the real historical company, preview the exact mapping:

```powershell
C:\xampp\php\php.exe bin\resolve-legacy-attendance.php --attendance-id=18 --academic-year=2026-2027 --semester=1st --company-id=<COMPANY_ID> --dry-run
```

Then apply the explicit resolution:

```powershell
C:\xampp\php\php.exe bin\resolve-legacy-attendance.php --attendance-id=18 --academic-year=2026-2027 --semester=1st --company-id=<COMPANY_ID> --apply
```

The resolver does not change `attendance` or `students.company_id`. It creates or reuses a historical normalized placement and records the explicit attendance-to-placement mapping in `legacy_attendance_resolutions`.


## Migration 003 — journals and centralized attachment metadata

Migration 003 requires migrations 001 and 002. It converts each legacy `journal_entries` row into a placement-scoped `journal_days` record plus revision 1 in `journal_revisions`.

Legacy status mapping:

- `pending` → `submitted`
- `rejected` → `returned`
- `approved` → `approved`

Hours are converted to integer claimed minutes per journal row. Coordinator remarks, submitted/reviewed timestamps, activities, learnings and challenges are preserved.

Existing proof images are **not moved or deleted**. When a legacy `proof_image` exists, the migration resolves the real file, records its storage key, detected MIME type, byte size and SHA-256 hash in `attachments`, then links it through `journal_revision_attachments`.

Run the preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --apply
```

The preflight blocks instead of guessing if:
- a journal cannot be tied to exactly one placement;
- a referenced proof file is missing or unreadable;
- a proof path is invalid;
- duplicate student/date journals exist;
- a journal has an invalid status or hours value.

A successful apply verifies day/revision counts, status mapping, claimed minutes, proof links, and hashes the legacy journal rows before and after to prove the source rows were not changed.


## Migration 004 — requirements

Migration 004 requires migrations 001–003. It preserves both legacy requirement sources:

- `requirement_templates` remains the current coordinator library.
- `ojt_requirements` remains the current assignment/submission table.

The normalized target uses:

```text
requirement_definitions
└── requirement_definition_versions
    └── requirement_assignments
        └── requirement_submissions
            └── requirement_submission_attachments
```

Historical duplicate assignments are kept as separate assignments. They are not merged.

Legacy status mapping:

- pending + submitted_at → open assignment + submitted submission
- rejected + submitted_at → open assignment + returned submission
- approved + submitted_at → closed assignment + approved submission
- approved without submitted_at → waived assignment with no fabricated submission
- pending without submitted_at → open assignment with no submission

Existing requirement files are not moved or deleted. Their storage key, MIME type, size and SHA-256 hash are registered in `attachments` and linked to the corresponding normalized submission.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --apply
```

The migration blocks rather than guessing if a source row lacks a normalized enrollment, a reviewer ID is invalid, an attachment is missing/unreadable, a file path is invalid, a file exists without a submitted timestamp, or a legacy template match is ambiguous.


## Migration 005 — reports

Migration 005 requires migrations 001–004. It converts every legacy `reports` row into a normalized report assignment and, when `submitted_at` exists, submission version 1.

Normalized structure:

```text
report_templates
└── report_template_versions
    └── report_assignments
        └── report_submissions
            └── report_submission_attachments
```

Distinct legacy report name + type pairs share one normalized template/version, while every historical report row remains a separate assignment.

Legacy status mapping:

- pending without submission → assigned, no submission
- pending/for_review with submission → assigned + submitted
- rejected with submission → assigned + returned
- approved with submission → closed + approved
- approved without submission → waived, no fabricated submission

Legacy `remarks` is inherently ambiguous because the old table used the same field for student notes and coordinator feedback. Migration 005 preserves it without guessing:
- `pending` / `for_review` submitted rows → `student_note`
- `approved` / `rejected` submitted rows → `review_notes`
- unknown/lost prior text is not fabricated

For submitted monthly reports, migration 005 creates `evidence_snapshot` JSON containing the normalized attendance days and latest journal revisions for that month. Because the old system did not save an immutable source snapshot at submission time, migrated snapshots explicitly identify their basis as **current normalized records at migration time, not the original historical submission snapshot**.

Existing report files are not moved or deleted. Their storage key, MIME type, byte size and SHA-256 are registered in `attachments` and linked to the normalized submission.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --apply
```

The migration blocks rather than guessing if it finds a missing normalized enrollment, invalid reviewer, file without submission timestamp, missing/unreadable file, invalid path, invalid status/type, or a returned/for-review state without a submission timestamp.


## Migration 006 — evaluations

Migration 006 requires migrations 001–005 and preserves both evaluation systems that exist in legacy OJTrack:

1. the fixed `evaluations` table with six percentage criteria;
2. the dynamic form builder using `evaluation_forms`, `eval_sections`, `eval_criteria`, `eval_rating_rules`, `eval_submissions` and `eval_answers`.

The collision-safe normalized target is:

```text
evaluation_definitions
└── evaluation_definition_versions
    ├── evaluation_version_sections
    │   └── evaluation_version_criteria
    ├── evaluation_version_rating_rules
    └── evaluation_requests
        └── evaluation_submissions
            └── evaluation_answers
```

Each legacy dynamic form is migrated as its own immutable normalized definition/version snapshot. Structured sections and criteria are preserved. Older draft forms that only contain the legacy JSON `criteria` list are preserved under a synthetic `Criteria` section.

Each `eval_submissions` row becomes an evaluation request. Completed rows create a normalized submission plus criterion answers; pending rows remain requests only. Answer migration resolves the exact legacy section title + criterion label and stores both as snapshots.

The older fixed six-score `evaluations` table is preserved under one archived historical form with these criteria: Technical Skills, Work Ethic & Punctuality, Communication Skills, Teamwork & Collaboration, Initiative & Problem Solving, and Adaptability.

The transitional `evaluation_assignments` table is not used by the current application. If it contains any rows, migration 006 deliberately returns `BLOCKED` rather than ignoring or guessing how its serialized answers should map.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --apply
```

The migration blocks rather than guessing when a form snapshot/version is inconsistent, criteria cannot be resolved uniquely, a placement/evaluator cannot be mapped exactly, completed rows lack required scores/timestamps, pending rows contain completed data, or transitional assignments exist.


## Migration 006 — evaluations

Migration 006 requires migrations 001–005 and preserves both legacy evaluation systems: the fixed six-score `evaluations` table and the dynamic form builder using `evaluation_forms`, `eval_sections`, `eval_criteria`, `eval_rating_rules`, `eval_submissions`, and `eval_answers`.

Normalized structure:

```text
evaluation_definitions
└── evaluation_definition_versions
    ├── evaluation_version_sections
    │   └── evaluation_version_criteria
    ├── evaluation_version_rating_rules
    └── evaluation_requests
        └── evaluation_submissions
            └── evaluation_answers
```

Dynamic forms are migrated as immutable snapshots. Older JSON-only criteria are preserved under a synthetic `Criteria` section. Completed dynamic requests become submissions with exact section/criterion label snapshots; pending requests remain request-only records.

Fixed evaluations are migrated under one archived historical six-criterion form. The transitional `evaluation_assignments` table is intentionally blocked if it contains rows because the current application does not use it and its serialized answers cannot be safely inferred.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --apply
```


## Migration 007 — certificate templates and announcements

Migration 007 requires migrations 001–006.

Certificate handling:
- Creates one normalized effective certificate template per company from the same defaults + saved `companies.cert_template` JSON that the current UI uses.
- Registers a saved company logo in centralized `attachments` metadata when one exists.
- Does **not** create historical `certificates` rows. Legacy OJTrack renders certificates on demand and never persisted a reliable issuance event, certificate number, rendered snapshot, or issued timestamp. Fabricating those would create false history.
- Leaves `companies.cert_template` and public logo files unchanged.

Announcement handling:
- Legacy `announcements` remains untouched.
- Normalized posts use `announcement_posts` to avoid colliding with the legacy table.
- Existing attachment files are hashed/registered in `attachments` and linked through `announcement_attachments`; files are not moved.
- `announcement_recipients` materializes who can currently see each active, unexpired legacy announcement under the existing student/coordinator/company visibility rules and current assignments.
- Recipient rows are explicitly a migration-time visibility snapshot, not a claim about the original recipients at the time the announcement was first posted.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --apply
```

The migration blocks rather than guessing when a company has an invalid certificate-template owner, certificate JSON is malformed, a referenced logo/announcement file is missing or unreadable, or an announcement has an unsupported author/target.


## Migration 008 — application cutover checkpoint

Migration 008 requires migrations 001–007. Unlike the earlier phases, it does not copy business records. The application pages have been cut over to the normalized OJT data layer for training assignments, attendance, journals, requirements, reports, evaluations, certificate issuance/templates and announcements.

Migration 008 verifies:
- all required normalized tables exist;
- requirement/report/evaluation/certificate-template/announcement legacy mapping counts still match;
- normalized submissions, evaluation answers and announcement recipients have no orphan parent records;
- the selected academic term still has normalized enrollment/training data.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, record the checkpoint:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --apply
```

This phase intentionally keeps every legacy table. Phase 9 must not retire legacy structures until the real installation passes Phase 8 plus final user-acceptance/parity checks.


## Migration 009 — reversible legacy retirement

Migration 009 requires the `008_application_cutover` checkpoint.

This phase deliberately does **not** drop historical workflow rows. It quarantines retired legacy workflow tables by atomically renaming them to `legacy_retired_*` names only after these checks pass:

- every expected legacy source table still exists and no retired target name already exists;
- the live PHP application source contains no direct SQL against the legacy workflow table names;
- requirement/report/evaluation/announcement legacy mapping counts still match;
- normalized submissions and evaluation answers have no orphan parent records.

Retired table names include legacy attendance, journals, requirement templates/assignments, reports, fixed/dynamic evaluation tables, and announcements. Core identity tables such as `users`, `students`, `coordinators`, `companies`, programs, messaging, notifications and activity logs are **not** retired by this phase.

Run preflight:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --dry-run
```

Only when the result is `READY`, apply:

```powershell
C:\xampp\php\php.exe bin\migrate-normalized-phase9-retire-legacy.php --academic-year=2026-2027 --semester=1st --apply
```

The apply result must report `rows_deleted: 0` and `legacy_data_preserved: true`.

Structural rollback is available:

```powershell
C:\xampp\php\php.exe bin\rollback-normalized-phase9-retirement.php --dry-run
C:\xampp\php\php.exe bin\rollback-normalized-phase9-retirement.php --apply
```

Rollback restores the old table names only. It does not undo normalized writes or reverse the Phase 8 application cutover.

## Migration 012 — conversation archiving

`bin/migrate-conversation-archiving.php` adds nullable `thread_members.archived_at` and an index by user/archive state. Existing memberships remain in Inbox. No messages, threads or memberships are removed. Archive and restore change only the current member's state; new messages reopen the conversation for its members.

Run `php tests/conversation-archiving.php` to rehearse the exact ALTER statement and archive/restore behavior against connection-scoped temporary tables, without changing application records. Then run the migration with `--dry-run` and `--apply`. The runner verifies row counts, records `012_conversation_archiving` in `schema_migrations`, and safely supports repeated execution. Check Inbox/Archived counts, member isolation, restore and the arrival of a new message after applying.

Rollback: deploy the previous application code before dropping `idx_thread_members_archive` and `archived_at`; this removes only the per-user archive preferences. Preserve the column if those preferences may be needed later.

## Migration 013 - point-based evaluation forms

013_evaluation_points.sql adds the points scoring mode without changing existing forms. Run php bin/import-bsit-ojt-evaluation.php --dry-run to review the supplied BSIT sheet, then --apply to add the mode and import the active form into Sir Jmvy's library. Repeated imports do not duplicate it. The import sends no evaluation requests and includes no handwritten trainee results. Category maxima total 100; existing percentage forms keep their original arithmetic mean. The source overlaps at 91 percent; the imported rule treats 91 as PASSED and lower scores as NEEDS IMPROVEMENT. Three categories with unsplit source totals use shared category scores.
