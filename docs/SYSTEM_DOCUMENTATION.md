# OJTRACK system documentation

## Architecture

OJTRACK is a server-rendered PHP web application backed by MariaDB/MySQL. Shared PHP helpers enforce authentication, role authorization, CSRF validation, file validation, and document access. Browser JavaScript provides table search/filtering, attachment previews, notifications, messaging updates, modal interactions, page guides, inline form validation, and fast accessible tooltips. The application is installed at `/ojtrack/`.

Runtime areas are `admin/`, `coordinator/`, `company/`, `student/`, and authenticated endpoints under `api/`. Shared layouts and reusable UI components are in `frontend/`; backend services, middleware, infrastructure, and support helpers are in `app/`. The legacy `includes/` and `config/` paths remain compatibility entry points. Browser resources are in `assets/`, and the release installation schema is `database/install.sql`.

## Reference diagrams

The [large web use case diagram](diagrams/use-case-diagram-large.svg) summarizes the supported Administrator, Coordinator, Company Supervisor, and Student workflows. The [detailed web use case diagram](diagrams/use-case-diagram.svg) provides the corresponding individual use cases. Both diagrams cover the web application only; the `mobile/` directory is outside this system documentation and remains out of scope.

## Shared web interaction behavior

Controls with explicit `title`, `data-tooltip`, or `data-tooltip-text` text use the shared fast tooltip popover in `assets/js/main.js`. It appears on pointer hover, keyboard focus, and touch interaction, is positioned above sticky panels and modal overlays, and closes on pointer exit, click, Escape, scrolling, or resizing. Multi-select checkboxes also receive a generated hint from their accessible label or nearby option text, covering select-all controls and row selections without changing unrelated form checkboxes. The popover uses `role="tooltip"` and temporary `aria-describedby` wiring; screen-reader-only `aria-label` values are not displayed as tooltips. Dynamically created table actions and modal controls are covered automatically. Modal backdrops close only on a direct backdrop click; dragging from a form into or outside the backdrop does not discard the form.

Profile settings actions remain pinned to the bottom edge of the profile modal while its longer content scrolls, so Close, Save Changes, and related actions remain available without requiring the user to reach the end of the form.

The company certificate template editor uses the same separated scroll body and fixed action footer, keeping Cancel and Save Template visible while editing longer templates.

Long student create/edit forms, coordinator requirement and evaluation assignment forms, and company bulk-attendance forms use the same layout: only the form body scrolls, while the action footer remains visible and usable.

The company certificate editor presents automatic student and training fields in plain language. Its body text still supports the documented placeholders, which are replaced with the selected student’s details when the certificate is issued.

## Roles and ownership

Administrators manage accounts, academic terms, programs, coordinator assignments, and company placements. Activating an academic term changes the enrollment context used by current OJT workflows; prior records remain attached to their own terms.

Coordinators define and assign requirements and narrative reports, review submissions and journals, manage evaluation forms, and oversee training progress. Students upload against their assigned requirements/reports. Approved records remain protected and historical submissions/revisions are retained.

Companies manage attendance and evaluations for assigned trainees. Attendance stores AM/PM sessions and credited minutes; dashboards derive official training hours from attendance. Journal hours are claimed work-log hours and are not substituted for credited attendance.

Students read assigned requirements, deadlines, remarks, official attendance, evaluations, certificates, and authorized announcements. They submit journals and required files, revise eligible records, download PDFs, and communicate with their assigned contacts. Selecting one contact uses a direct conversation; selecting both coordinator and supervisor creates a group discussion visible to its members.

## Self-service profiles

Every authenticated role uses the shared profile modal on its pages. It displays the account ID, role, status, and creation date without loading a password into the profile view. Students also see their student ID, current term/enrollment, program/year, training status, placement dates, coordinator/company contacts, and official attendance-derived hours. Missing assignments are labeled explicitly. Coordinators see their coordinator ID and assigned department/program; companies see their partnership status and supervisor record.

Names, applicable name extensions, contact information, company/supervisor details, photos, and passwords retain their dedicated editing controls. Student contact email is optional and does not replace student-ID sign-in. Coordinator department, IDs, roles, account/partnership status, academic assignments, placements, and training hours cannot be changed through a self-profile update. The shared `config/profile.php` helper accepts only permitted fields for the authenticated account and saves account/role records transactionally. Protected fields appear as read-only text, not editable inputs. Administration and coordinator assignment workflows remain separate.

## Reports and PDFs

Narrative reports follow coordinator assignments and instructions. Midterm/final labels are available milestones, not an assertion that every program requires those reports. The assigned coordinator determines the actual requirements.

Monthly compilation is an explicit self-service workflow: after a month ends, students can download the saved attendance/journal compilation. Submission requires an assigned coordinator. Where necessary, a monthly assignment is created for that enrollment and period. The generated PDF, attachment links, report submission, and frozen evidence snapshot are saved together; storage/save failures roll back the assignment/template writes and remove a newly written report file.

Evaluation PDFs use the saved form questions, scores, maxima, classifications, and supervisor feedback. The recognized BSIT reference uses its stored document-control metadata and the supplied paper-form layout. Other forms retain their own rating rules. Signature and signing-date fields remain blank. Ordinary BSIT reference content fits one A4 page; long content continues with repeated headers. Student downloads repeat ownership checks and do not submit another report.

## Data and storage

Core normalized relationships include academic terms, programs, student enrollments, coordinator/company assignments, placements, attendance days/sessions, journal revisions, requirement/report assignment and submission versions, evaluation definitions/versions/answers, certificates, messaging membership, and notifications. Compatibility views support shared legacy entry points. The fresh schema includes the required version markers and multiple-file/name-extension structures.

Requirements, reports, and journal proofs are stored outside the public document root, accessed through authorized `download.php` requests. Existing authorized legacy documents may still be resolved from their old upload locations. Avatars, announcement attachments, and certificate logos use public upload categories. Image/file validation, random storage names, and server access rules are required together.

## Security and operations

Protected routes check the active account, role, session expiry, password fingerprint, onboarding state, and CSRF token for mutations. The login page supports an optional 30-day Remember Me cookie backed by rotated, hashed server tokens. Password recovery creates a one-time reset token that expires after 30 minutes; reset tokens are invalidated after use and whenever a password changes. Document downloads validate record relationships. Client-side visibility does not replace server authorization. Database calls use bound parameters where user values are accepted. The server must enforce internal-directory and executable-upload denial rules.

Back up the database, private storage, and retained public uploads together. Preserve historical records when changing assignments or academic terms. Deploy over HTTPS with a dedicated database user, protected service credentials, writable upload directories only where needed, and production error logging. See `docs/DEPLOYMENT.md` and the explicitly scoped verification results.
