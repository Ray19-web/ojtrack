# Web release notes - 1 November 2026

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
