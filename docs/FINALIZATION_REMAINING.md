# OJTrack — Phase 1 implementation and remaining finalization

Updated: 4 October 2026  
Repository: Ray19-web/ojtrack  
Working branch: codex/finalization-phase-1  
Baseline audited: b7a4926cc4d886815bc30e81e4267afdb36dbbec  
Status: **private-storage follow-up drafted; runtime verification pending; not final release approval**

This is the continuation checkpoint requested after the full audit. The original audit remains the reference for baseline evidence. Status below describes this branch only, not main or a deployed installation. No normalization migration has been run on real records.

## Implemented in this batch

- Removed shared password bypasses; regenerated sessions at login; rechecked current account role/status and password fingerprint; added a 30-minute inactivity timeout.
- Added CSRF tokens to POST forms and JavaScript requests. APIs return structured authorization errors; onboarding restrictions run before protected mutations.
- Added evaluation ownership checks, read-only published/assigned forms and draft version cloning. Percentage answers are required and saved atomically; completed retries do not duplicate answers.
- Repaired evaluation creation and coordinator announcement SQL bindings, notification enum compatibility and explicit single/all notification operations.
- Added scoped recipient checks and corrected notification destinations. Restricted student announcements to admin/assigned coordinator/company audiences.
- Enabled returned-journal revisions; validated dates/hours and recalculated week on date changes. Added reviewer IDs and blocked approval of unsubmitted requirements/reports.
- Added authorized requirement/report/journal-proof downloads with safe image previews. Added Apache access rules and ignore rules for future runtime uploads. Existing tracked uploads and the populated SQL dump remain pending cleanup.
- Added role-specific sidebar groupings and clearer page names. Replaced hardcoded student progress with live counts and redirected the old progress page. Removed fake health indicators and corrected company evaluation counts.
- Added shared modal keyboard focus behavior, focus outlines and small mobile heading improvements.
- Added schema-only installation, CLI first-admin setup, environment settings, deployment instructions and synthetic regression tests.

## Upload-validation follow-up

- Shared preflight runs after authentication/role/onboarding checks and before page mutations across upload fields.
- Real content is checked instead of trusting the browser-provided MIME or filename alone.
- Images are decoded and re-encoded, stripping metadata/trailing data; animated GIF becomes one frame.
- Files use 5 MB image / 10 MB document limits. Office XML archives have type, expansion, VBA and encryption checks.
- SVG uploads are no longer accepted for certificate logos; existing files remain untouched.
- PHP body-limit failures return 413; invalid individual uploads return 422.
- New filenames use random tokens instead of timestamps. Onboarding no longer falls back to a timestamp if randomness fails.
- Student requirement/report uploads now check assignment ownership and approved status before storing; an initial submission needs a document.
- Enable PHP GD and ZIP and configure upload limits as described in README.md before using these changes.
- This is still partial SEC-08: storage transactions, private storage migration, orphan cleanup, antivirus and inline error recovery remain outstanding. Legacy binary Office macros are not inspected.
- Repository data cleanup remains pending; the populated SQL dump and existing tracked uploads were preserved.

## Private-storage follow-up — verification pending

- New requirements, reports and journal proofs use OJTRACK_PRIVATE_UPLOAD_DIR outside the public web root and application directory.
- A single storage helper validates categories/paths, refuses symbolic links, reserves new destinations exclusively and sets restrictive permissions.
- Downloads retain ownership checks and resolve private files first, then existing legacy files. Existing documents, database paths and repository files are preserved.
- Missing configuration blocks new private uploads with a controlled error; legacy reads remain available. Invalid configured roots fail closed.
- A CLI migration command defaults to dry-run. Copy mode verifies hashes, never overwrites a differing destination and never deletes source files or changes DB references.
- Added isolated filesystem tests and adapted the multipart suite to private storage. Python test-script syntax and git diff whitespace checks passed.
- **PHP/MariaDB were unavailable in the new execution environment. Package installation failed due to unavailable process permissions; no runtime test passes are claimed for this follow-up.** Run PHP lint, tests/storage.php, the 414-check suite, the adapted multipart suite, and copy dry-run/copy/retry/conflict scenarios before merging.
- Required setup and XAMPP/Linux examples are in README.md. Configure the root before deploying, or new student document uploads will return 503.
- Remaining: transactional file/reference lifecycle, orphan cleanup, malware scanning, public announcement-attachment policy, real-host verification and approved legacy/repository cleanup.

## Verified, and what remains unverified

- Previous upload-validation commit (5d6b144): all 68 PHP files passed syntax checks. The private-storage follow-up has not been PHP-linted or runtime-tested in this environment.
- Fresh schema imported successfully into isolated MariaDB 10.11.
- Previous upload-validation commit (5d6b144): **414 regression checks and 67 multipart-upload checks passed**. These results do not certify the later private-storage changes.
- Covered all four role page directories; rendered inline JavaScript syntax; rendered POST tokens; login/bypass rejection; cross-role denial; CSRF; onboarding; session revocation; notification ownership; form creation/ownership/version clone; 0/100 evaluation scores, rollback and retry; message participant/member scope; returned-journal correction; scoped announcements; private document ownership.
- JavaScript syntax and HTTP page responses are not a visual/accessibility sign-off.
- Direct-upload denial was tested through a local router that emulates deny rules. Apache/XAMPP/Nginx behavior is **not verified**.
- Real multipart uploads were tested for synthetic PDFs, DOCX, images, invalid types, size limits, batch preflight, ownership and approved-record preservation. Concurrent requests, full assignment/review/completion cycles, browser rendering, printing, malware scanning and restoration remain unverified.
- Existing data has not been migrated or sanitized in a live installation. Existing tracked uploads and the populated dump remain present. Any later cleanup will not erase Git history.

## Before checking out or merging this branch

1. Back up the real database and uploads outside the repository, then rehearse restoration.
2. Repository cleanup is pending: automatic approval review rejected deleting the populated SQL dump and 26 tracked upload files without explicit authorization. Preserve required records and approve cleanup separately.
3. Review in a staging copy; do not import database/schema.sql over an existing DB.
4. Verify Apache deny rules (including dotfiles/.git/.env) or equivalent Nginx configuration. Authenticated downloads do not compensate for direct web access.
5. Expect everyone to sign in again. Existing password hashes must be valid; bypass passwords no longer work.
6. Review legacy raw/rating evaluations. This batch deliberately supports percentage scoring only; do not silently reinterpret old scores.
7. Complete attendance, completion/certification and migration gates before declaring the system finalized.

## Recommended next work order

1. **Document/upload protection and remaining escaping** — SEC-05/06/08. Confirm server-level protection first.
2. **Database foundations** — DB-01/02/03/04/05/07 with ADM-02. Produce reversible migrations and reconcile actual records.
3. **Attendance** — COM-01/02/03 and DB-06. One authoritative hours pipeline with explicit punches and corrections.
4. **Evaluation completion** — CORE-03/04/05/06/07. One pipeline, immutable criterion identity and duplicate-send protection.
5. **Submission review** — COO-01/02/03/04/06, STU-01/02/04. Versioned evidence and review history.
6. **Completion and certificates** — COO-05, COM-05. Centralized eligibility and permanent issued records.
7. **Admin/communication/UI** — remaining ADM, CORE-08/09/10, STU-03/05 and UI items.
8. **Release** — OPS-01/02/03. Browser UAT, production configuration, backup/restore and migration rehearsal.

## Status of all original audit items

“Implemented” means the scoped phase-one fix is present with relevant checks; it does not certify the entire system. “Partial” means do not close the original audit item yet. “Remaining” means no implementation in this batch.

| Audit ID | Status | Item | Remaining action or verification |
|---|---|---|---|
| SEC-01 | Implemented | Remove authentication bypasses | Repeat acceptance on staging; keep regression coverage. |
| SEC-02 | Implemented | Revalidate sessions and account status | Repeat acceptance on staging; keep regression coverage. |
| SEC-03 | Implemented | Add CSRF protection | Repeat acceptance on staging; keep regression coverage. |
| SEC-04 | Implemented | Enforce evaluation child-record ownership | Repeat acceptance on staging; keep regression coverage. |
| SEC-05 | Partial | Protect documents and sanitize committed data | Authorized requirement/report/proof endpoint and Apache denies added; existing tracked runtime records remain unchanged because deletion approval was blocked. Private storage and a copy-only migration helper are now drafted; validate them in staging and configure the root before deployment. Verify hosting rules, legacy exposure, repository history and any necessary credential rotation. |
| SEC-06 | Partial | Fix HTML/JavaScript output escaping | Coordinator report output escaping repaired. Audit remaining inline handlers, certificate template HTML and uploaded/public content. |
| SEC-07 | Implemented | Apply activation checks before mutations | Repeat acceptance on staging; keep regression coverage. |
| SEC-08 | Partial | Unify upload validation and storage | Shared preflight now validates upload errors, actual size, extension/content agreement, image decode/re-encode and Office packages; new names use random tokens. Centralized private storage for student documents is now drafted with runtime tests pending. Finish malware scanning, public attachment policy, revision/file lifecycle, rollback/orphan cleanup and consistent inline error feedback. |
| SEC-09 | Implemented | Validate profile redirect targets | Repeat acceptance on staging; keep regression coverage. |
| CORE-01 | Implemented | Repair evaluation form creation binding | Repeat acceptance on staging; keep regression coverage. |
| CORE-02 | Implemented | Repair notification schema and helper | Repeat acceptance on staging; keep regression coverage. |
| CORE-03 | Partial | Make multi-record changes atomic and honest | Evaluation answer saves now use a transaction. Account/profile creation, assignments, messages, reviews and attendance still need transactions and failure handling. |
| CORE-04 | Partial | Use one evaluation pipeline everywhere | Company evaluation counts now use eval_submissions. Migrate legacy evaluations/evaluation_assignments; update student results, progress and completion queries to one source. |
| CORE-05 | Partial | Enforce immutable published evaluation versions | Published/assigned legacy forms reject direct edits, and migration 006 now stores immutable normalized definition/version snapshots with section/criterion identities. Application cutover plus transactional publish/edit/send serialization and concurrency tests remain. |
| CORE-06 | Partial | Make score modes real and require deliberate answers | Percentage answers require deliberate 0–100 values; migration 006 preserves scores/equivalents/rating bands and blocks unsupported legacy scoring modes instead of guessing. Final normalized write-path validation and band completeness remain. |
| CORE-07 | Partial | Prevent duplicate evaluation requests and submissions | Completed submission retry is idempotent under row lock. Add assignment uniqueness/idempotency and prevent duplicate coordinator sends. |
| CORE-08 | Partial | Repair announcement delivery and visibility | Migration 007 preserves posts/attachments/recipient snapshots, and Phase 8 now creates and reads normalized announcement posts with materialized recipients. Remaining work is UX cleanup of received/authored presentation and final public-attachment policy. |
| CORE-09 | Partial | Fix message permissions and destination links | Recipient relationships and role-specific notification links repaired. Audit existing thread memberships after reassignment; transactional thread creation and group policies remain. |
| CORE-10 | Remaining | Finish message receiving | Add reliable incoming-message polling with since cursor, unread handling, failed-send recovery and empty/loading states. |
| ADM-01 | Partial | Protect account-role integrity and the last admin | Role mutation and self-deactivation/archive blocked. Add race-safe last-admin invariant; transactional role profile creation and explicit account recovery. |
| ADM-02 | Remaining | Replace name-based coordinator auto-assignment | Use explicit coordinator-to-program assignments; remove free-text department/name matching. |
| ADM-03 | Partial | Separate login status, partner status, and record archiving | Company edit/toggle no longer silently restores archived users. Finish independent login/partner/archive lifecycle rules and assignment filtering. |
| ADM-04 | Partial | Replace fake health and inconsistent counts | Fake System Health removed and company evaluation counts corrected. Reconcile all dashboard counts, archived record filters, and links against destination lists. |
| ADM-05 | Remaining | Add term setup, imports, exports and recovery | Add academic terms, validated import preview, authorized exports and recovery tools after DB foundations. |
| COO-01 | Partial | Make requirement reviews and templates complete | Unsubmitted approval rejected; rejection remarks required; reviewed_by recorded; last requirement approval activates onboarding. Finish required/optional templates, reviewer history and assignment lifecycle. |
| COO-02 | Partial | Add non-monthly report assignment | Migration 005 now provides normalized report templates, versions and scoped assignments with deadlines/statuses. Coordinator UI still needs cutover/create-assignment workflow before this is complete. |
| COO-03 | Partial | Repair report document links and review identity | Authorized links/reviewer identity remain; migration 005 separates student notes from review notes where recoverable and registers report attachments. Application write paths still need normalized revision/review history. |
| COO-04 | Partial | Build an inspectable monthly report | Migration 005 creates versioned JSON evidence snapshots for migrated monthly submissions using normalized attendance and latest journal revisions, explicitly labeled as migration-time evidence. Future monthly submissions still need application cutover to create immutable snapshots at submission time. |
| COO-05 | Remaining | Enforce completion prerequisites | Central eligibility service must check official hours, approved required documents/reports and required evaluations before completion/certification. |
| COO-06 | Remaining | Create one actionable review queue | Create one scoped review queue with Requirements, Journals and Reports tabs; prioritized counts link to the exact filtered list. |
| COM-01 | Remaining | Replace automatic AM/PM punch guessing | Move attendance recording into Attendance. Use explicit AM In/Out and PM In/Out actions, server timestamps and sequence validation. |
| COM-02 | Remaining | Make attendance recording concurrency-safe | Use transactions/locking and DB uniqueness for punches; reject impossible intervals and recompute the authoritative hours total. |
| COM-03 | Remaining | Provide correction, absence and exception handling | Add reasoned correction requests, approval/audit history, absent/excused states and approved exceptions. |
| COM-04 | Remaining | Add placement acceptance and supervisor ownership | After placements are modeled, add accept/decline and explicit supervisor assignment if school policy needs them. |
| COM-05 | Partial | Preserve issued certificate history | Phase 8 now makes new certificate issuance permanent and snapshot-based and reuses the same issued record for viewing/reprint. No legacy issue history was fabricated; explicit revocation/reissue policy still remains before final completion. |
| STU-01 | Partial | Allow returned journals to be corrected | Returned journals can be revised/resubmitted. Add immutable revision/review history; refresh submission timestamps and test rejection cycles. |
| STU-02 | Partial | Validate journal data and week calculation | Date range, future date, hours, duplicate date precheck and week calculation repaired. Enforce uniqueness in DB, handle upload errors consistently, and validate calendar/timezone edge cases. |
| STU-03 | Partial | Make progress reflect actual requirements | Hardcoded progress replaced with live counts and old progress page redirects to dashboard. Unify evaluation pipeline and calculate official attendance/completion eligibility. |
| STU-04 | Partial | Unify submission rules and feedback | Requirements/reports now reject missing/foreign assignments, approved replacements and first submission without a document. Finish concurrency-safe state transitions, consistent feedback, reviewer history and permitted replacements across all submission types. |
| STU-05 | Remaining | Show next action and onboarding support | Add clear next-action cards and onboarding contact/help, not progress-only counters. |
| DB-01 | Remaining | Normalize academic program relationships | Make program_id authoritative; backfill/verify mismatched program and department text before retiring duplicate columns. |
| DB-02 | Partial | Separate student identity from OJT attempts and placements | Phase 8 now routes active role-page assignment/status/progress reads and writes through academic terms, enrollments, coordinator history and placements. Legacy duplicate student columns remain only for Phase 9 retirement/compatibility validation. |
| DB-03 | Remaining | Add missing foreign keys and one-to-one constraints | Add role-profile unique user_id and missing foreign keys only after duplicate/orphan audit; avoid cascading deletion of academic history. |
| DB-04 | Partial | Give evaluation answers stable criterion identity | Migration 006 provides immutable criterion/version IDs and label snapshots, and Phase 8 now routes evaluation form/request/submission/answer workflows through them. Legacy tables remain until Phase 9 retirement validation. |
| DB-05 | Partial | Separate definitions, assignments, submissions and reviews | Migrations 004–006 normalize requirements, reports and evaluations, and Phase 8 now uses the normalized application layer for their active read/write/review workflows. Legacy source tables remain for Phase 9 retirement validation. |
| DB-06 | Remaining | Choose one authoritative source for hours | Choose approved attendance intervals as the official hours source; keep any cached total synchronized/reconcilable. |
| DB-07 | Remaining | Enforce business uniqueness and add measured indexes | Add business uniqueness (attendance, assignment, membership, journal date) and indexes justified by real query plans. |
| DB-08 | Remaining | Keep appropriate JSON and enums; do not over-normalize | Keep bounded enums and appropriate JSON; do not split every scalar into a lookup table without a real relationship. |
| UI-01 | Partial | Give each role a focused navigation structure | Role sidebar groups/page labels and student progress merge implemented. Move attendance actions, unify student detail tabs, complete agreed dashboard order and all remaining labels. |
| UI-02 | Partial | Finish keyboard and mobile interactions | Shared modal focus trap/return and focus outline added. Test keyboard, screen reader, mobile drawer/tables and print in a real browser. |
| UI-03 | Partial | Remove encoding artifacts and developer-facing copy | Known BOM/encoding artifacts and developer-facing evaluation text removed. Inspect all rendered pages for residual issues and validate actual branding assets. |
| UI-04 | Remaining | Standardize feedback, tables and validation | Create shared list/detail/review/form patterns, consistent status labels, validation, empty states, filters and pagination. |
| OPS-01 | Partial | Replace ad hoc web-accessible migrations | Existing migration scripts made CLI-only and Apache-denied. Replace them with ordered versioned migrations, backfill verification and rollback/runbook. |
| OPS-02 | Partial | Centralize environment and time settings | DB/timezone env configuration added. Centralize base path, production secret management and DB/server timezone; remove insecure production defaults. |
| OPS-03 | Partial | Refresh documentation and add release checks | Fresh schema, initial-admin CLI, setup/runbook and synthetic regression suite added. Complete real-browser UAT, Apache checks, backups/restore and migration rehearsal. |
| OPS-04 | Implemented | Fix coordinator notice attachment update binding | Repeat acceptance on staging; keep regression coverage. |

## Concrete next implementation package: attendance

- [ ] Move recording controls from My Trainees to Attendance; keep a shortcut from trainee details.
- [ ] Show the selected trainee, date, current four punch values and allowed next action.
- [ ] Use server time for normal punches; corrections require a reason and a designated approver.
- [ ] Lock the daily attendance row while changing punches; enforce one row per placement/date.
- [ ] Validate each start/end pair and non-overlap; define lunch, overtime, weekends, holidays and maximum daily hours with the school.
- [ ] Calculate approved intervals and reconcile cached totals; never trust browser-submitted hours.
- [ ] Test double-clicks, simultaneous punches, midday breaks, out-of-order actions, no placement, inactive trainee and overnight policy.
- [ ] Verify Student Attendance, dashboards, company lists, monthly evidence and certificates read the same official total.

## Decisions needed before larger migrations

These choices need school/project rules, not guessed defaults:

- Can a student have more than one OJT attempt, company or supervisor in the same academic term?
- Are all assigned requirements mandatory for activation? Which requirements/reports/evaluations block completion?
- Who approves corrected attendance, overtime and exceptions?
- What percentage rounding and equivalent-grade scale does the official evaluation form require?
- Who issues certificates, when can they be revoked, and what historical details must remain fixed?
- What document retention and authorized audience rules apply to companies versus academic staff?

Proceed with reversible scaffolding and data audits while these are being decided; do not silently migrate ambiguous records.

## Proposed database structure

This is a target model, not executable DDL. Final columns and keys must follow agreed school rules. Normalization means each business fact has a clear owner and relationships use stable keys; simply creating more tables does not prove third normal form.

| Table/group | Main facts and relationships | Migration note |
|---|---|---|
| users | Login identity, email, password hash, fixed role, account status | Retain IDs; enforce email uniqueness. A roles/permissions subsystem is unnecessary unless roles must become configurable. |
| students / coordinators | Role-specific personal fields; UNIQUE user_id → users | Retain existing IDs and school student identifiers. Do not confuse generated system codes with official school IDs. |
| programs / departments | Program code/name; optional program.department_id | Backfill IDs from current strings with a reviewed mapping, not fuzzy automatic merges. |
| academic_terms | School year, semester, start/end dates | Historical rows with unknown term require an explicit legacy mapping or unresolved state. |
| coordinator_program_assignments | coordinator_id, program_id, term_id, valid dates | Establish actual rules for multiple coordinators per program; avoid arbitrary first match. |
| companies / company_users | Organization facts separate from individual supervisor accounts | Preserve company identity; map existing company login as its initial supervisor. |
| ojt_enrollments | student_id, term_id, program_id, required_minutes, academic status | Unique student/term/attempt if repeated attempts are allowed. Required hours may be an intentional enrollment snapshot of program defaults. |
| placements | enrollment_id, company_id, supervisor, coordinator, start/end, placement status | Preserve transfers rather than overwriting company_id. Decide whether multiple simultaneous placements are permitted. |
| placement_status_history | placement_id, old/new status, actor, time, reason | Academic completion and account inactivity are different states. |
| attendance_days / attendance_sessions | placement_id, date, typed in/out timestamps, absence/excused state; sessions belong to day | AM/PM is a UI grouping if flexible shifts are needed. If fixed four-slot DTR is mandatory, a constrained fixed model can remain; resolve redundant summaries either way. |
| attendance_corrections | attendance record, requester/reviewer, before/after, reason, decision | Store credited minutes deterministically; maintain an auditable amendment trail. |
| journal_entries / journal_versions | placement_id, date, content; submission/review history | Unique day per placement if policy requires one journal daily. Official credit still comes from attendance. |
| requirement_templates / versions | Reusable instructions, accepted formats, required flag | Keep historical assigned wording; archive templates used by past assignments. |
| requirement_assignments / submissions / reviews | enrollment or placement, template version, deadline; versioned file; reviewer/time/comment | Define whether pre-OJT requirements belong to enrollment and company-specific ones to placement. |
| report_templates / assignments / submissions | report type, period_start/end, separate due date, immutable submitted version | Monthly compilation includes referenced/snapshotted journal and DTR rows; student note and review note are different facts. |
| evaluation_forms / form_versions / sections / criteria / scales | Stable form family, immutable published version, ordered criteria and scoring rules | Existing evaluation_forms rows can serve as version records if naming and immutable behavior are made explicit. Do not require a rewrite just to rename tables. |
| evaluation_assignments / answers | placement, form_version, period/type, due date, requester/evaluator, completion; answer → criterion | Migrate legacy evaluations into a legacy form version. Rename or reuse current eval_submissions; do not retain two competing assignment systems. |
| attachments | Storage key, original filename, detected MIME, size, uploader, created_at | Use explicit FK linking tables for submission owners rather than unconstrained entity_type/entity_id if DB integrity matters. |
| certificates | placement, unique number, template version/snapshot, issuer/date, status | Reissues should reference previous issuance rather than silently rewriting it. |
| announcements / audience relations | One notice with explicit intended scopes/recipients | Avoid duplicate notice content for “both” audiences. Decide whether membership is snapshotted or dynamically scoped. |
| message_threads / thread_members / messages / message_reads | Keep existing normalized relationships | Enforce contact policy on thread creation; add bounded history and useful indexes. |
| notifications | user, event category, severity, destination, read_at | Can retain is_read or derive it from read_at; keep the source of truth clear. |
| activity_log | Actor, entity identifier, action, time and useful before/after details | Keep audit records immutable through normal UI and avoid storing secrets. |

**Specific 3NF-related improvements:** `program_id → program label` should live in programs; organization identity should not be confused with a supervisor login; placement facts should not be overwritten on the student identity row. Missing FKs, indexes and uniqueness are integrity/performance issues as well as modeling concerns; do not call every one a normalization violation. Cached totals, immutable score summaries and historical text snapshots can be deliberate denormalization if documented and kept consistent.

## Safe migration sequence

1. Back up DB and uploaded files; restore them into a separate test database to prove the backup works. Do not put real data into a public branch.
2. Inventory row counts, duplicate profile user_ids, missing program IDs, invalid notification values, orphan references, evaluation variants and attendance/total mismatches. Report ambiguities instead of guessing.
3. Create additive tables/nullable keys and a migration version ledger. Keep old readers working while backfilling.
4. Map programs and coordinator assignments; create known terms/enrollments/placements. Current company values cannot reconstruct prior company history that was never recorded—flag it for manual confirmation.
5. Migrate legacy evaluation scores into a preserved legacy form/version. Preserve comments, actor, dates and original IDs in a migration mapping.
6. Backfill attachment/submission versions and current review information. Unknown historical reviewers remain unknown; never invent them.
7. Reconcile row counts, per-student credited time, evaluation totals, completed records and accessible attachments. Only then add FK/unique constraints.
8. Switch all role pages and APIs to the shared model, test in a staging copy and keep a rollback plan. A brief controlled cutover is safer than prolonged unsynchronized dual writes.
9. Remove redundant columns/tables and compatibility code only after export/backup, parity checks and acceptance. Archival retention and legacy data migration come before deletion.


## Final UI arrangement, page names, and design plan

This section consolidates the follow-up UI recommendations into the audit. It is the proposed implementation target, not a claim that the changes already exist. It expands **UI-01–UI-04** and the related workflow tasks without adding duplicate backlog IDs. Preserve the current navy/blue/gold branding; refine hierarchy, grouping, spacing and behavior. Validate the finished design in the running app on desktop and mobile.

### Sidebar arrangement by role

Keep common navigation order consistent: Overview → Main work → Communication. Use small, readable section labels, a subtle active-page background and one consistent icon family. Group labels should not look like clickable pages. Keep Profile and Sign Out in the account menu.

**Admin**

| Section | Page order | Main purpose |
|---|---|---|
| Overview | 1. Dashboard | Setup exceptions, unassigned students and reliable counts. |
| People & Partners | 2. Students; 3. Coordinators; 4. Companies | Academic profiles, assignments and partner records. |
| Academic Setup | 5. Programs; 6. Academic Terms | Program configuration, academic year and semester. |
| Administration | 7. User Accounts; 8. Archived Accounts; 9. Activity Log | Login access, controlled restoration and accountability. |
| Communication | 10. Announcements | Official notices and intended audiences. |

Academic Terms is a proposed addition linked to ADM-05/DB-02. Programs, people and accounts remain separate views because their purposes differ. Share underlying account-creation and validation services so the views cannot apply conflicting rules. For example, Students manages academic/placement facts, while User Accounts manages access and passwords.

**OJT Coordinator**

| Section | Page order | Main purpose |
|---|---|---|
| Overview | 1. Dashboard | Review queue, overdue work and students needing attention. |
| OJT Management | 2. My Students; 3. Attendance | Supervised students, placements, DTR and correction review. |
| Submissions & Reviews | 4. Requirements; 5. Daily Journals; 6. Reports; 7. Evaluations | Assign, review, return, approve and monitor assigned work. |
| Communication | 8. Messages; 9. Announcements | Conversations plus received and authored notices. |

**Company**

| Section | Page order | Main purpose |
|---|---|---|
| Overview | 1. Dashboard | Today's training actions, missing punches and evaluations due. |
| Training | 2. My Trainees; 3. Attendance; 4. Evaluations; 5. Certificates | Trainee details, time recording, performance reviews and eligible certificate issuance. |
| Communication | 6. Messages; 7. Announcements | Conversations and targeted notices. |

**Student**

| Section | Page order | Main purpose |
|---|---|---|
| Overview | 1. Dashboard | Next action, official hours, deadlines and milestones. |
| My OJT | 2. Requirements; 3. Attendance; 4. Daily Journal; 5. Reports; 6. Evaluations; 7. Certificate | Student's own training evidence and outcomes. |
| Communication | 8. Messages; 9. Announcements | Authorized support contacts, feedback and notices. |

Merge the existing student Progress page into the Dashboard. Keep milestones in a dedicated dashboard section and preserve an old-route redirect/bookmark destination. Onboarding remains a focused first-login flow, not an additional permanent sidebar item. Before activation, any support access must be explicitly permitted by the server guard.

### Page names and terminology

Sidebar names should be short; page headings can add context. Do not rename files or URLs simply because a visible label changes. If routes do change, update notifications, links and redirects together.

| Current name or concept | Recommended sidebar label | Page heading or clarification |
|---|---|---|
| Users | User Accounts | User Accounts — manage login access and status. |
| Coordinator's Students | My Students | My Students — students assigned to this coordinator. |
| Company's Students | My Trainees | My Trainees — trainees assigned to this company. |
| Monitoring | Daily Journals | Daily Journals — review submitted training entries. |
| Attendance DTR / Daily Time Record | Attendance | Daily Time Record for students; Attendance for company/coordinator management. |
| Trainee Performance Evaluation | Evaluations | Evaluations — requests and results appropriate to the role. |
| Coordinator Evaluation Forms | Evaluations | Forms, Requests and Results as internal tabs. |
| Certificate Generator | Certificates | Certificates — eligible trainees and issued records. |
| Archived Users | Archived Accounts | Archived Accounts — restore access through a controlled process. |
| Student Progress | Merge into Dashboard | OJT Progress section inside the dashboard. |
| Rejected, when correction is allowed | Returned for Revision | Action: Return for Revision; student action: Resubmit. |

Use one user-facing term for each concept throughout the app. Keep account status, placement status and submission status distinct. For example, an Active account can have an On Hold placement and a Returned submission. Backend status values can remain during a staged migration if they map consistently to the new labels.

### Correct placement of actions and internal tabs

Each workflow should have one primary home. Shortcuts may open that home with the relevant student or record selected; avoid implementing another version of the same action elsewhere.

| Action or record | Primary location | Internal arrangement |
|---|---|---|
| Record trainee time-in/time-out | Company → Attendance | Today; Records; Corrections. Use explicit actions and show selected trainee count for bulk actions. |
| View trainee identity/placement | Company → My Trainees | Open authorized trainee detail; shortcut to Attendance. |
| Create/edit evaluation forms | Coordinator → Evaluations | Forms tab; draft builder opens as a full page. |
| Send and track evaluations | Coordinator → Evaluations | Requests tab; choose form version, students, period and due date. |
| Review completed evaluations | Coordinator → Evaluations | Results tab with student/company/term filters. |
| Complete an evaluation | Company → Evaluations | Pending and Submitted tabs; full form and final review step. |
| View own evaluation results | Student → Evaluations | Status, released results and feedback, according to school policy. |
| Create requirement definitions | Coordinator → Requirements | Templates tab; edit/archive reusable definitions. |
| Assign requirements | Coordinator → Requirements | Assignments tab; choose students, requirement versions and due dates. |
| Review requirements | Coordinator → Requirements | Submissions tab; separate Not Submitted, Under Review, Returned and Approved filters. |
| Assign/review narrative reports | Coordinator → Reports | Assignments and Submissions; templates where needed. |
| Review monthly compilation | Coordinator → Reports | Submission detail with DTR/journal evidence and review history. |
| Change account access/password | Admin → User Accounts | Account edit/access actions. |
| Change OJT assignment/dates | Student detail → Placement | Explicit company/coordinator assignment and history. |
| Preview/edit certificate template | Company → Certificates | Templates or clearly labeled template action. |
| Issue/reprint official certificate | Company → Certificates | Eligible and Issued views, subject to completion policy. |
| Read received notices | Announcements | Received tab for coordinator/company. |
| Manage own notices | Announcements | My Announcements tab, separate from received notices. |

Move attendance recording out of company/students.php into the Attendance workflow while retaining a shortcut. Move legacy company-evaluation views out of coordinator Reports into Evaluations after data migration. Do not remove the source data while reorganizing navigation.

### Shared student or trainee detail page

Use a full detail page for a complex student record. Its header shows name, school/system identifier with clear labels, program, term, placement status and one appropriate primary action. Provide a back link that preserves list filters.

| Tab | Contents |
|---|---|
| Overview | Student information, official credited hours, next action and completion checklist. |
| Placement | Company, supervisor, coordinator, dates, required hours and assignment history. |
| Requirements | Assigned documents, submission versions and review status. |
| Attendance | DTR, official totals and authorized correction actions. |
| Journal & Reports | Daily evidence, submitted compilations and feedback. |
| Evaluations | Requested forms, submission status and permitted results. |
| History | Status changes, assignments and relevant academic decisions. |

Admin and coordinator views may share this structure, but permissions still differ. Company access should expose only its authorized placement and appropriate training records; it must not reveal unrelated company history or private academic documents. Students see their own records. Server-side authorization must protect every tab and endpoint, not just hide tab buttons.

### Dashboard item order

Put work requiring action above historical summaries. Use a small number of useful summary cards; every count should link to a filtered list with the same scope.

| Role | First/main content | Supporting content | Lower-priority content |
|---|---|---|---|
| Admin | Unassigned students and setup/account exceptions | Active term and consistent student/partner counts | Recent activity and verified operational information. |
| Coordinator | Work Awaiting Review queue | Students Needing Attention; overdue evaluations; deadlines | Progress summaries and recent decisions. |
| Company | Today's Attendance actions or a prominent Attendance shortcut | Missing Punches; Evaluations Due | Trainee totals, upcoming dates and notices. |
| Student | Your Next Step, with one direct action | Official Hours; Upcoming Deadlines; Returned Work | OJT Progress milestones, placement summary and recent notices. |

Student next-action example: “Your September 30 journal was returned for revision. Read your coordinator's feedback and resubmit.” Action: **View Feedback**. When no action is pending, show a clear up-to-date state rather than an empty warning box.

Red or warning badges should indicate outstanding action, not total historical records. A permanent red certificate count should become a contextual availability label or disappear after acknowledgement. Unread-message badges represent unread messages; review badges represent submitted work awaiting the user's decision.

### Reusable page layouts

| Page type | Arrangement from top to bottom | Appropriate use |
|---|---|---|
| List page | Breadcrumb if useful → title/subtitle and primary action → filters/search → table → pagination and total | Students, accounts, requirements, attendance and submissions. |
| Detail page | Back link → identity/status header → tabs → record content → contextual actions/history | Student, report, evaluation or placement detail. |
| Form page | Title/instructions → related field groups → field errors → save/submit actions | Evaluation builder, long evaluation, report assignment and complex placement edits. |
| Review page | Submission summary/status → evidence/file preview → prior feedback → review decision | Documents, journals and reports. |
| Dashboard | Action queue/next step → small summary group → supporting lists/progress | Role landing pages. |
| Short modal | Clear title → a small number of fields or confirmation → cancel/confirm | Short edits, return reason and simple confirmations. |

Keep the main page action near the title. Put search and filters directly above the content they affect. Show the frequent row action (View, Review, Fill Out, Resubmit) directly; place rare secondary actions in an accessible menu. Destructive actions need a clear consequence statement and confirmation when appropriate. Do not use a modal for a multi-section evaluation builder or an entire student record.

### Visual design rules

These are starting implementation values, not measurements of the current rendered app. Reuse existing CSS variables where possible and verify contrast/readability in the actual UI.

| Element | Design direction |
|---|---|
| Branding | Keep current navy, blue and gold palette, logos and school identity. |
| Page background | Quiet neutral background; white or existing light surfaces for content. |
| Primary color | Blue for main actions and active navigation. Gold for restrained brand emphasis, not every button. |
| Status colors | Consistent success/warning/error colors plus a readable text label; never communicate state through color alone. |
| Typography | Consistent existing heading/body families; start with 14–16px body text, 24–28px page headings and a clear section hierarchy. Avoid tiny labels for important information. |
| Spacing | Use an 8/16/24/32px scale for related controls, card padding and page sections. |
| Surfaces | Consistent moderate corner radius, subtle borders and limited shadows. Avoid decorative gradients on every action/card. |
| Icons | One visual family and consistent sizing; labeled controls for unfamiliar actions. |
| Tables | Readable row spacing; names/details left aligned, numbers consistently aligned, status and actions in predictable columns. |
| Buttons | One primary emphasis per task area; secondary actions quieter; saving/disabled states visible. |
| Forms | Visible labels above inputs, brief help text where useful, required fields identified and field-level errors next to the input. |
| Empty states | Explain what is missing and who should act: “No reports assigned yet” is different from “No reports match these filters.” |
| Print views | Remove navigation and action buttons; include identifying information, dates, totals and page breaks suitable for the document. |

Do not replace all tables with cards. Tables make records comparable; cards work well for summaries and notices. Keep success/error wording accurate and preserve entered values after validation failure. Remove developer implementation notes, mojibake, placeholder dates and fake metrics.

### Mobile and accessibility behavior

- [ ] Verify key flows at narrow phone, tablet and desktop widths, including approximately 360px, 768px and 1280px as starting test sizes.
- [ ] Sidebar opens as a dismissible drawer on small screens; active page and account actions remain discoverable.
- [ ] Search/filter controls wrap without covering content; complex filters can use a clearly labeled expandable area.
- [ ] Wide tables scroll inside their container rather than forcing the whole page sideways; important identity/action columns remain understandable.
- [ ] Student-detail tabs wrap or scroll with a visible overflow cue; do not squeeze seven labels into unreadable text.
- [ ] Dialogs have an accessible name, appropriate dialog semantics, focus trapping, Escape-to-close where safe, and focus restoration.
- [ ] All actions work with keyboard input and visible focus. Upload selection uses a real labeled input/button, not a clickable card alone.
- [ ] Controls remain usable at zoom; touch targets are comfortably sized and destructive actions are not crowded together.
- [ ] Status, loading, validation and confirmation messages are understandable without relying on color or animation.
- [ ] Report/certificate print output has no clipped rows, orphaned headings or missing identity fields.

### UI implementation sequence and acceptance

These steps are a sub-checklist for the existing UI backlog, not another competing project plan. Navigation planning can happen early; new actions and status displays should ship with their required backend rules. Security fixes retain overall priority.

| Step | Related tasks | Deliverable | Done when |
|---|---|---|---|
| 1. Finalize labels and navigation | UI-01 | Shared role navigation map and terminology | Every role follows the agreed order; labels are consistent; links and permissions remain correct. |
| 2. Move actions to their primary home | UI-01, COM-01, CORE-04, CORE-08 | Attendance in Attendance; evaluation results in Evaluations; received/authored notices separated | No duplicate mutation implementation; shortcuts open the correct scoped page. |
| 3. Build reusable page patterns | UI-04 | One list, detail, form and review layout | Filters, headings, actions, errors and pagination behave consistently. |
| 4. Consolidate student detail | UI-01, DB-02 | Permission-scoped tabbed detail | Users see related evidence without losing context; unauthorized tabs/endpoints are denied. |
| 5. Reorder dashboards and merge progress | ADM-04, COO-06, STU-03, STU-05 | Action-first dashboards and student progress section | Counts match destination lists; milestones use real data; old progress links still resolve. |
| 6. Apply visual consistency | UI-03–04 | Shared spacing/type/button/status rules | Existing branding is preserved; placeholders and encoding issues are removed. |
| 7. Validate mobile, keyboard and print | UI-02 | Reviewed role flows across screen sizes | Each role completes its key flow with mouse/touch and keyboard; print output remains readable. |

Record UI implementation status against UI-01–UI-04 in the main backlog. A page is complete only when its data, permissions, loading/error states, keyboard/mobile behavior and destination links work together—not merely when the screen looks finished.


## Final acceptance scenario

Use synthetic accounts: two coordinators, two companies and at least three students, including one outside each reviewer’s scope.

1. Admin sets a term/program, creates profiles and makes explicit assignments. Attempt duplicate email/profile and last-admin deactivation.
2. Student uploads required files; no-file and invalid uploads fail. Coordinator cannot review another coordinator’s student. Return → revise → approve preserves prior evidence.
3. Before activation, protected direct POST/API requests fail. After required approvals, allowed training actions work.
4. Company records 08:00–12:00 and 13:00–17:00; total is eight hours everywhere. Double submit, missing punch, backdating, overnight policy and non-ongoing placement behavior are tested.
5. Student submits journal; rejected entry can be edited and resubmitted. Weeks and DTR comparisons are correct.
6. Coordinator assigns a report. Student uploads it; coordinator opens it. Monthly report displays underlying evidence and an approved snapshot survives later edits.
7. Coordinator publishes a form, sends one request, company fills required criteria and submits once. All role screens show the same result; a new version cannot change old assignments. Cross-form child IDs fail authorization.
8. Company/admin announcements reach intended audiences; message notification links open the recipient’s role page; unauthorized contacts are rejected.
9. Attempt early completion and certificate issue; both fail with reasons. Fulfill the checklist, complete, issue and reprint the same certificate. Transfer a student without changing historical company attribution.
10. Archive a logged-in account; its next request is denied. Restore from backup; compare totals and document availability. Verify narrow-screen, keyboard and printable output behavior.

**First implementation task:** SEC-01, then SEC-02/03. The first user-visible feature repair after the security package should be CORE-01/02 (form creation and notifications). Database changes should then proceed additively before broader workflow refactors.

## Resume instruction

Start from codex/finalization-phase-1. Read this checkpoint and the original audit; inspect the current branch before editing. Preserve the existing branding and the four roles. Work through one package at a time, add meaningful regression checks, and update this checklist with exact implemented/partial/remaining statuses. Do not run the fresh-install schema against existing data. Do not merge or deploy until the staging and final acceptance gates are met.


### Phase 8 application cutover checkpoint

The active application data layer is now normalized for training assignment/progress, attendance, journals, requirements, reports, evaluations, announcements and certificate templates/issuance. `008_application_cutover` is a non-destructive checkpoint that verifies legacy mapping parity and normalized referential integrity before Phase 9. It does not delete legacy tables.
