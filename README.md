# OJTRACK

Web-based OJT monitoring for USTP Jasaan. The application supports administrators, coordinators, partner companies, and students.

## Features

- Academic terms, programs, accounts, student enrollments, coordinator assignments, and company placements.
- Coordinator-assigned requirements and reports, multiple-file submissions, review remarks, and protected approved records.
- Company-recorded AM/PM attendance and attendance-derived training progress.
- Daily journals with revision history, multiple activity images, and coordinator review.
- Configurable company evaluations with percentage or point scoring, student result dialogs, and printable paper-style PDFs.
- Monthly DTR/journal compilation, PDF download, and PDF submission to the assigned coordinator.
- Issued certificates, announcements, notifications, direct/group conversations, and conversation archiving.
- Responsive role interfaces, accessible modal controls, and student page/context guides.

## Install

1. Use PHP 8.1+ with `mysqli`, `mbstring`, `fileinfo`, `gd`, `zip`, `iconv`, and `dom`; MariaDB/MySQL; and Apache 2.4 with `mod_rewrite` and `.htaccess` overrides enabled.
2. Extract the release's `ojtrack` folder under the web document root. Routes and session cookies use `/ojtrack/`.
3. Create a **new empty database**, then import `database/install.sql`. It contains schema and migration markers only, with no accounts or business records. Never import this file over an existing installation.
4. Configure the database and private storage environment described in [Deployment](docs/DEPLOYMENT.md). Use a dedicated database account and a private upload directory outside the web root.
5. Create the first administrator: `php bin/create-admin.php "Administrator Name" admin@example.edu`. Supply a password of at least 12 characters through standard input in a private terminal. No default administrator or demo account is included.
6. Run `php bin/preflight.php`, then open `/ojtrack/login`. Complete academic terms, programs, coordinator/company accounts, and student assignments before OJT activity begins.
7. Follow the deployment acceptance and backup/restore steps in [Deployment](docs/DEPLOYMENT.md).

Students sign in with their assigned student ID; other roles use email. Newly created student accounts initially use their student ID as their password. Communicate credentials privately and change this initial password through the profile menu.

## Documentation

- [System documentation](docs/SYSTEM_DOCUMENTATION.md): roles, workflows, architecture, and data ownership.
- [Deployment](docs/DEPLOYMENT.md): configuration, first setup, storage, and operational checks.
- [Verification](docs/VERIFICATION.md): executed checks and their scope.
- [Release notes](docs/RELEASE_NOTES.md): finalization changes and package contents.
- `RELEASE_MANIFEST.json` in the ZIP: SHA-256 hashes and sizes of the packaged files.

## Source and deployment contents

The deployment ZIP includes the web runtime, protective server rules, an empty installation schema, reference/catalog definitions, documentation, and essential setup commands. It excludes source-control metadata, tests, development fixtures, historical notes, personal roster imports, local configuration, database data dumps, runtime uploads, and unrelated applications.

The maintained source checkout retains tests and migration tools for regression coverage and existing-installation maintenance. Do not deploy the full working directory or run legacy migrations on a fresh release installation. Existing installations require a verified backup and a deliberate upgrade plan; `install.sql` is exclusively for an empty database.

## Build the web release

From the maintained source checkout, run `php bin/build-web-release.php <new-absolute-zip-path>`. The destination directory must exist outside the project. The allowlist builder refuses to overwrite an existing archive and creates the per-file release manifest. Verify the archive and repeat deployment acceptance on the intended host.
