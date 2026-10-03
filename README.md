# OJTrack

PHP/MySQL OJT monitoring system for administrators, OJT coordinators, companies and students.

This branch is the first finalization batch. It is **not a production readiness sign-off**. See [the remaining work](docs/FINALIZATION_REMAINING.md), including database normalization and UI arrangement. SYSTEM_DOCUMENTATION.md is a historical overview and has outdated sections.

## Fresh local setup

1. Use PHP 8.1+ with mysqli, fileinfo, GD and ZIP, MariaDB/MySQL, and Apache 2.4. This batch was tested with PHP 8.3 and MariaDB 10.11.
2. Put the project at /ojtrack/ under the web root. Existing links and session cookie paths still assume that path.
3. Create an empty UTF-8 database and import database/schema.sql. It contains schema only, no students, passwords, documents or demo accounts. **Do not import it over an existing database.**
4. Configure OJTRACK_DB_HOST, OJTRACK_DB_USER, OJTRACK_DB_PASS, OJTRACK_DB_NAME and optionally OJTRACK_TIMEZONE in your server environment. Defaults remain localhost/root/blank/ojtrack for local compatibility; production requires a dedicated database account and password. An .env file is ignored by git but is not automatically loaded.
5. Create the first administrator from the command line:
   php bin/create-admin.php "System Administrator" admin@example.edu
   The command reads one password line from standard input. Use a private terminal or protected input file; provide at least 12 characters. Do not put the password in source code, command arguments, or a shared recording. The command refuses to run over HTTP or when an administrator already exists.
6. Make only the required upload directories writable by the web server. Keep application source read-only. Use synthetic documents while testing.
7. Open /ojtrack/login.php, sign in, and create programs, coordinators, companies and students.

## Required web server rules

The included Apache .htaccess files require overrides that support Require and Options. Confirm these rules actually work on your host; PHP's development server does not apply .htaccess.

- Deny direct requests to config/, scratch/, database/, bin/, tests/, and all dotfiles/directories (especially .git and .env).
- Deny direct requests to uploads/requirements/, uploads/reports/ and uploads/journal_proofs/. Authorized users access documents through download.php.
- Disable directory listings and PHP/script execution throughout uploads.
- Deny download of SQL dumps, logs, Markdown documentation and backups.
- On Nginx, configure equivalent deny locations and prevent upload paths from reaching PHP-FPM. Do not rely on .htaccess.
- Use HTTPS, secure session storage, production error logging (display_errors=Off), and backups outside the web root.
- Do not deploy test fixtures or development scripts.

Document authorization in PHP does not protect a host that serves the same upload file directly. Test both the authorized endpoint and direct path before releasing.

## Upload validation

All supplied files pass a shared preflight before page mutations. It checks PHP upload errors, actual size, extension/content agreement, and supported document containers. Images are decoded and re-encoded; metadata and trailing payloads are removed, and animated GIF uploads become a static first frame.

- Avatars, logos and journal proofs: 5 MB; images at most 6000 pixels per side and 12 megapixels.
- Requirements, reports and announcement attachments: 10 MB.
- Office XML documents: matching DOCX/XLSX/PPTX package content, no VBA payload, no encrypted entries, maximum 2000 archive entries and 50 MB expanded size.
- SVG uploads are rejected. Existing stored SVG files are not retroactively converted.
- Legacy DOC/XLS/PPT checks validate the container; they do not inspect macros. PDF/container checks are not malware scanning. Open documents only in suitable viewers; external scanning and private storage remain follow-up work.
- Enable PHP GD and ZIP before deploying this batch. Missing extensions produce a controlled rejection for the relevant upload type.
- Set upload_max_filesize=10M and post_max_size=32M (or document another deliberate limit). Keep max_file_uploads at least 20 for onboarding batches. Requests larger than the body limit receive HTTP 413; other invalid uploads receive 422.
- A bad supplied file rejects the entire preflight before page handlers run. The subsequent multi-file storage/database writes are not yet one transaction.
- New filenames use random tokens. Existing files and names remain unchanged.

## Updating an existing installation

1. Back up the database **and** all runtime uploads outside this repository; rehearse restoration.
2. Review the draft changes in a staging copy. The populated dump and tracked runtime uploads remain unchanged: automatic approval review blocked their remote deletion because explicit deletion authorization is missing. Do not treat the branch as sanitized; approve cleanup separately after preserving needed records.
3. Do not run the fresh-install schema against existing records. This batch makes no normalization migration.
4. Existing sessions must sign in again because they lack the new password fingerprint.
5. Confirm stored passwords are real PHP password hashes. Shared bypass passwords no longer work; recover accounts through an authorized reset.
6. Existing percentage evaluations are supported. Raw/rating forms are not supported; create percentage versions and review legacy assignments rather than silently converting scores.
7. Verify the web-server denies above, role workflows, and document previews/downloads in your actual Apache/XAMPP setup.
8. Resolve the release blockers in the remaining-work document before final deployment.

Removing files from the branch does not remove them from Git history. Credential rotation and any repository-history cleanup must be handled separately.

## Regression checks

See [tests/README.md](tests/README.md). The suite is for a disposable ojtrack_test database, uses only synthetic data, and modifies it. Never point it at real records.

The first batch includes role-page HTTP smoke tests, rendered inline-JavaScript syntax checks, CSRF checks, session revocation, evaluation ownership and atomic submission, scoped messages, returned journals, announcements, and document access. Browser layout, print, malware scanning, concurrency races, and live Apache behavior still need separate verification.
