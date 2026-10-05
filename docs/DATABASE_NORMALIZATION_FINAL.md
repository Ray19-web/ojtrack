# OJTrack Final Normalized Database Design

## Status

This document is the canonical target data model for OJTrack. The executable target DDL is `database/schema.normalized.sql`.

**Important:** the current application still uses the legacy 25-table schema in several PHP pages. Do not import the normalized schema over an existing database. Migrate and refactor the application in stages, verify the data, then retire legacy columns/tables only after acceptance.

## Design rules

1. A table owns one business concept.
2. User login identity is separate from role-specific profile data.
3. Student identity is separate from each OJT enrollment/attempt.
4. Company organization data is separate from supervisor/login accounts.
5. Coordinator and company assignments keep history instead of overwriting old relationships.
6. Requirements, reports, journals and evaluations preserve revisions/versions.
7. Files are stored once in `attachments`; business records link to them with real foreign keys.
8. Published forms/templates are immutable. Editing creates a new version.
9. Historical academic/training records use `RESTRICT` instead of destructive cascade deletes.
10. Cached summaries may exist only when they are deliberate and reproducible; source records remain authoritative.

## Core relationship map

```text
users
├── students
├── coordinators
└── company_users ── companies

students
└── ojt_enrollments
    ├── academic_terms
    ├── programs
    ├── enrollment_coordinators ── coordinators
    ├── placements ── companies
    │   ├── placement_supervisors ── company_users
    │   ├── attendance_days ── attendance_sessions
    │   ├── journal_days ── journal_revisions
    │   ├── evaluation_requests ── evaluation_submissions ── evaluation_answers
    │   └── certificates
    ├── requirement_assignments ── requirement_submissions
    └── report_assignments ── report_submissions

attachments
├── journal_revision_attachments
├── requirement_submission_attachments
├── report_submission_attachments
├── announcement_attachments
└── certificates
```

## Why the old student table was decomposed

The legacy `students` table mixed permanent identity with OJT-attempt facts such as program, coordinator, company, OJT status, required hours, rendered hours and OJT dates. Those facts can change between academic terms and placements.

The normalized model keeps only student identity in `students`. OJT-specific facts belong to `ojt_enrollments`, coordinator history belongs to `enrollment_coordinators`, and company placement history belongs to `placements`.

This prevents a new OJT term or company transfer from overwriting historical records.

## Why company accounts were separated

The legacy `companies` table assumed one organization had one login/supervisor. The normalized model uses:

- `companies` for the organization.
- `company_users` for supervisor, HR, manager or other authorized accounts.
- `placement_supervisors` for which company user supervises a specific placement.

This supports multiple supervisors without duplicating the company.

## Attendance

Attendance is split into:

- `attendance_days`: one official day/status per placement/date.
- `attendance_sessions`: one or more time-in/time-out sessions for that day.
- `attendance_corrections`: an auditable correction request/review trail.

Official credited time is stored in integer minutes to avoid floating-point/rounding ambiguity. Hours shown in the UI should be derived from minutes.

## Journal history

`journal_days` identifies the placement/date. `journal_revisions` stores every draft/submission/returned/approved version. A returned journal is never overwritten; resubmission creates a new revision.

## Requirement workflow

```text
requirement_definitions
└── requirement_definition_versions
    └── requirement_assignments
        └── requirement_submissions
            └── requirement_submission_attachments
```

The definition, assignment and submitted evidence are separate facts. This allows the coordinator to change future requirements without altering old submissions.

## Report workflow

```text
report_templates
└── report_template_versions
    └── report_assignments
        └── report_submissions
            └── report_submission_attachments
```

A report submission can keep an `evidence_snapshot` for monthly/compiled reports so an approved report remains reproducible even if later journal/attendance corrections occur.

## Evaluation workflow

```text
evaluation_forms
└── evaluation_form_versions
    ├── evaluation_sections
    │   └── evaluation_criteria
    ├── evaluation_rating_rules
    └── evaluation_requests
        └── evaluation_submissions
            └── evaluation_answers
```

An evaluation request points to an immutable published form version and a specific placement/evaluator. Each answer references a stable criterion ID and also keeps the criterion label snapshot for historical rendering.

This replaces the legacy mixture of `evaluations`, `evaluation_assignments`, `eval_submissions` and JSON-only criteria ownership.

## Certificates

Certificate templates belong to a company. An issued certificate belongs to a placement and stores a rendered snapshot so reprinting does not change when the template is edited later.

## Existing 25-table schema → normalized target

| Legacy area | Target |
|---|---|
| `students.program_id/program/department/year_level` | `ojt_enrollments.program_id/year_level` + `programs` |
| `students.coordinator_id` | `enrollment_coordinators` |
| `students.company_id` | `placements` |
| `students.ojt_status/required_hours/dates` | `ojt_enrollments` + `placements` |
| `students.rendered_hours` | derived from approved `attendance_days.credited_minutes` |
| `companies.user_id/supervisor_name` | `company_users` |
| `companies.cert_template` | `certificate_templates` |
| `attendance` | `attendance_days` + `attendance_sessions` |
| legacy `journal_entries` mutable row | `journal_days` + `journal_revisions` |
| `ojt_requirements` | requirement template/version/assignment/submission tables |
| `reports` | report template/version/assignment/submission tables |
| legacy evaluation tables | versioned evaluation pipeline |
| file_path columns | `attachments` + FK link tables |
| `announcement.attachment_file/name` | `announcement_attachments` |
| `activity_log.user_id` | `activity_log.actor_user_id` |

## Migration sequence for the existing OJTrack database

1. Back up the real MySQL database and uploads; restore both into a separate staging copy.
2. Create `schema_migrations` and all new parent tables first: terms, enrollments, company users, placements and attachments.
3. Backfill one OJT enrollment per current student. Existing records whose real term is unknown must be reviewed and assigned to the correct academic term; do not invent a term silently.
4. Backfill company login accounts into `company_users`.
5. Backfill coordinator assignments and company placements, preserving the current IDs in a migration mapping table if needed.
6. Migrate attendance into day/session rows and verify total credited minutes per student before and after.
7. Migrate journals into entry/revision history.
8. Migrate requirements and reports into template/assignment/submission pipelines and copy files into private attachment storage without deleting originals.
9. Migrate evaluations into a preserved legacy form/version, then verify every historical score/comment/date.
10. Create certificate templates/issued certificate records from existing configuration where applicable.
11. Refactor PHP readers/writers role by role to use normalized tables.
12. Run parity checks, UAT and backup/restore rehearsal.
13. Only after acceptance, remove legacy columns/tables and compatibility code in a separate migration.

## Mandatory parity checks before dropping legacy data

- User, student, coordinator and company counts match.
- Every student maps to exactly one intended OJT enrollment for each real term.
- Current coordinator/company assignment is preserved.
- Attendance totals match to the minute.
- Journal count, status, dates and evidence match.
- Requirement/report files remain downloadable only by authorized users.
- Evaluation scores/comments/evaluators/dates match.
- Messages and announcements are unaffected.
- Completed students remain eligible for the same certificate outcome.
- No orphan foreign keys exist.

## What "normalized" means here

This design is 3NF-oriented, but it intentionally keeps a few historical snapshots such as evaluation criterion labels, report evidence snapshots and rendered certificates. Those are deliberate immutable historical records, not accidental duplication.

Enums are retained for small bounded state machines. JSON is used only for audit/snapshot payloads where normal relational querying is not the primary ownership model.

## Implementation boundary

The **design is finalized by this document and `database/schema.normalized.sql`**. The current production-compatible schema remains in `database/schema.sql` until the PHP application has been migrated. Replacing `database/schema.sql` now would make existing pages query columns/tables that no longer exist.

Therefore:

- `database/schema.sql` = current compatibility/fresh-test schema for the existing code.
- `database/schema.normalized.sql` = final target schema.
- Existing databases must migrate; never overwrite them with either file.


### Requirement naming compatibility

The current application already has a legacy `requirement_templates` library with a different shape. The final normalized model therefore uses `requirement_definitions` and `requirement_definition_versions` so migration remains additive and the legacy coordinator library stays usable until application cutover.


### Evaluation naming compatibility

The current application already owns legacy tables named `evaluation_forms`, `eval_sections`, `eval_criteria`, and `eval_rating_rules`. The normalized evaluation model therefore uses `evaluation_definitions`, `evaluation_definition_versions`, `evaluation_version_sections`, `evaluation_version_criteria`, and `evaluation_version_rating_rules`. This keeps migration 006 additive and preserves both legacy fixed evaluations and dynamic form-builder data until application cutover.
