# OJTrack Lean Database Audit

## Final recommendation

The live OJTrack database should use **40 base tables**.

The previous 48-table normalized schema was safe but still over-normalized for the actual application behavior. A full source audit of the live Admin, Coordinator, Company, Student, API, shared-helper, upload, download, messaging, certificate, evaluation, requirement, report, journal and attendance code found eight relations that can be safely consolidated without removing current features.

Migration `010_lean_schema` performs the consolidation only when the real data satisfies strict preflight assumptions. Six compatibility views remain for read compatibility; views do not duplicate or store rows.

## Eight base tables removed

| Removed table | Why it is redundant | Replacement |
| --- | --- | --- |
| `attendance_corrections` | No live application code reads or writes it. | None. |
| `placement_supervisors` | Current UI has one company account/supervisor and no supervisor-assignment history workflow. | Company account attached directly to `companies.user_id`. |
| `company_users` | Current company model is one company = one login/contact. Admin/company pages already use `companies.user_id`. | `companies.user_id` + compatibility view. |
| `enrollment_coordinators` | Current app assigns one active coordinator per OJT enrollment; no UI exposes coordinator history. | Direct coordinator fields on `ojt_enrollments` + compatibility view. |
| `journal_revision_attachments` | UI supports one proof image per journal revision. | `journal_revisions.attachment_id` + compatibility view. |
| `requirement_submission_attachments` | UI accepts one document per requirement submission. | `requirement_submissions.attachment_id` + compatibility view. |
| `report_submission_attachments` | UI accepts one file per report submission. | `report_submissions.attachment_id` + compatibility view. |
| `announcement_attachments` | Announcement UI allows one attachment at a time. | `announcement_posts.attachment_id` + compatibility view. |

## Compatibility views

These names remain available as SQL views so existing read queries do not need duplicate storage:

- `company_users`
- `enrollment_coordinators`
- `journal_revision_attachments`
- `requirement_submission_attachments`
- `report_submission_attachments`
- `announcement_attachments`

## 40 base tables kept

### System and identity — 8
- `schema_migrations` — migration state; prevents accidental re-application.
- `users` — authentication and common account identity.
- `programs` — BSIT/BSCS/etc. master data.
- `academic_terms` — semester/year scoping.
- `students` — student identity/profile data.
- `coordinators` — coordinator profile data.
- `companies` — company profile and its single company login/contact.
- `activity_log` — audit/security history used by Admin.

### OJT core — 2
- `ojt_enrollments` — term-scoped OJT status, program, required hours and coordinator.
- `placements` — company placement history, dates and status.

### Attendance — 2
- `attendance_days` — official daily credited time/status.
- `attendance_sessions` — time-in/time-out sessions needed to calculate daily totals.

### Journal and file metadata — 3
- `journal_days` — one journal identity per placement/date.
- `journal_revisions` — revision history and approval state.
- `attachments` — centralized file metadata, hash, MIME, size and uploader.

### Requirements — 4
- `requirement_definitions`
- `requirement_definition_versions`
- `requirement_assignments`
- `requirement_submissions`

Definitions/versions are retained because Coordinator CRUD can change future requirements while older assigned/submitted records must keep their historical meaning.

### Reports — 4
- `report_templates`
- `report_template_versions`
- `report_assignments`
- `report_submissions`

The separation preserves deadlines, monthly periods, assignment state and submission/review history.

### Evaluations — 8
- `evaluation_definitions`
- `evaluation_definition_versions`
- `evaluation_version_sections`
- `evaluation_version_criteria`
- `evaluation_version_rating_rules`
- `evaluation_requests`
- `evaluation_submissions`
- `evaluation_answers`

These are actively used by the dynamic evaluation builder. Collapsing them would make versioning, sections, criteria CRUD, rating bands and historical answers fragile.

### Certificates — 2
- `certificate_templates`
- `certificates`

Templates can change; issued certificates need immutable snapshots/history.

### Announcements — 2
- `announcement_posts`
- `announcement_recipients`

Recipients are materialized because coordinator/company/student visibility is scoped and must not silently change when assignments change.

### Messaging and notifications — 5
- `message_threads`
- `thread_members`
- `messages`
- `message_reads`
- `notifications`

All are used by the messaging/unread/notification workflows.

## Why not reduce below 40?

A lower count is possible only by giving up useful behavior or by stuffing unrelated data into JSON/text columns. The remaining separation represents real one-to-many relationships, revision history, assignments, submissions, dynamic evaluation structure, scoped recipients, messaging membership/read state, or security/audit data.

For the current OJTrack requirements, **40 is the lean point**: fewer tables than the original normalized design, without sacrificing active features, data history or maintainability.

## Migration safety

`bin/migrate-normalized-phase10-lean-schema.php` blocks rather than guesses if any of these assumptions are false:

- the database is not at the expected 48-table post-cleanup state;
- `attendance_corrections` contains data;
- a company has zero/multiple company-user mappings;
- a coordinator enrollment has multiple history rows;
- placement/evaluator company mappings disagree;
- any journal/requirement/report/announcement parent has multiple attachments.

CI validates:

- migration dry-run → READY;
- migration apply → PASS;
- reapply → ALREADY_APPLIED;
- exactly 40 base tables remain;
- six compatibility views exist;
- direct relationship/attachment counts match;
- coordinator assignment writes still work;
- requirement/report resubmission attachment copying still works;
- announcement attachment creation still works;
- all Admin/Coordinator/Company/Student page smoke tests still pass.
