# Web release notes - 1 November 2026

## Shared web UI refinements - 18 November 2026

- Replaced delayed native browser title bubbles with a shared fast tooltip popover for explicit control hints across administrator, coordinator, company, and student pages.
- Added hover, keyboard-focus, and touch support, viewport-aware positioning, Escape/scroll/resize dismissal, and accessible `role="tooltip"`/`aria-describedby` behavior.
- Tooltips now dismiss immediately when a control or table row is clicked, even when the clicked element remains focused.
- Profile modal action buttons now remain visible at the bottom while the profile content is scrolled.
- Applied the same fixed-footer modal layout to the company certificate template editor.
- Standardized fixed action footers for long student, requirement-sending, evaluation-sending, and bulk-attendance forms so their content scrolls independently without hiding the form actions.
- Clarified the certificate template editor with plain-language automatic-field labels while preserving placeholder substitution during certificate generation.
- Covered controls inserted after page load, including generated table tools, filters, and modal actions, without exposing screen-reader-only `aria-label` text as visible tooltips.
- Added the same fast tooltip treatment to multi-select checkboxes, using their accessible labels or nearby option text for select-all and row-selection guidance across role pages.
- Protected open forms from accidental dismissal when a pointer drag starts inside the modal and ends on the backdrop.
- Added native year suggestions to the academic-term school-year fields while keeping manual entry available and preserving consecutive-year validation.
- Removed the account-creation button and modal from the User Accounts page; existing account status, archive, restore, and bulk-management actions remain available.

## Authentication recovery - 18 November 2026

- Added secure 30-day Remember Me sessions backed by rotated, hashed server tokens.
- Added one-time, 30-minute password-reset links with token invalidation after use and after password changes.
- Added local-development reset-link visibility for XAMPP environments without configured outbound email; production responses remain account-enumeration-safe.

## Web cleanup - 18 November 2026

- Removed the project-local BSIT company demonstration seed and the staged synthetic database seed scripts. The maintained web source no longer includes those demo-data generators.
- Removed generated user-testing PDF screenshots from `tmp/`; temporary fixture output is not part of the web application.
- Reset the local demonstration database's operational records and uploaded files while retaining registered account identities, basic role profiles, and reference catalog definitions. Students now have pending OJT status, no placement or assignments, no onboarding completion, and zero rendered hours.
- This cleanup applies to the web project only. The `mobile/` directory remains outside the project scope and was not modified.

- Prepared an allowlisted web deployment archive with a clean installation schema and per-file SHA-256 manifest.
- Moved unrelated application code, temporary fixtures/screenshots, historical audit documents, populated SQL exports, demo seed/repair scripts, and personal roster sources to a dated backup outside the web project. Existing live database records and runtime uploads were preserved.
- Excluded source control, tests, legacy migration tools, runtime uploads, machine-local configuration, and development-only material from the deployment archive. Essential first-admin, catalog, and preflight commands are included.
- Replaced mixed historical setup instructions with current installation, architecture, operation, and verification documentation.
- Added centralized denial of internal directories and repository metadata before route rewriting.
- Added cleanup for failed journal image uploads and validated required journal text before storing files.
- Made generated monthly report assignment/submission writes transactional, required an assigned coordinator, added storage-failure rollback, and provided friendly unexpected-error feedback.
- Corrected the coordinator report query's submission identifier so generated and multiple report attachments load correctly.
- Extracted shared person-name helpers without opening a database connection and updated stale account/filter/evaluation regression fixtures to the current schema and forms.
- Retained the completed student UI, multiple attachments, paper-format evaluation PDFs, monthly PDF submissions, messaging picker, announcement feed, and page/context guides.
- Reorganized the web application into `app/`, `frontend/`, and `app/Infrastructure/` service boundaries while preserving existing role routes, API URLs, configuration imports, and include paths through compatibility entry points.
- Updated the release allowlist to package the migrated application/frontend services and architecture documentation while continuing to exclude `mobile/` and runtime data.

See `docs/VERIFICATION.md` for executed checks and deployment boundaries. A prepared release archive does not mean that a production server has been configured or deployed.

## Profile information update

- Expanded the shared profile for all four roles with account records and role-specific information. Student assignments and credited hours use the current normalized enrollment context.
- Clearly separated read-only administrative records from editable personal/company contact details. Coordinator department is now read-only; coordinator phone is editable; student contact email remains optional.
- Unified self-profile updates behind a transactional field allowlist; forged identifiers, access roles, status, assignments, and hours are ignored.
- Updated the student profile guide and guarded a deferred tour spotlight against closing/removing its target before the next frame.
