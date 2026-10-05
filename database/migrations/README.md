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
