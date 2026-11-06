# Deployment and operation

## Requirements and configuration

Tested locally with PHP 8.2.12 and MariaDB 10.4.32. Use Apache 2.4 with `mod_rewrite` and overrides that permit `FileInfo`, `Options`, and `Require`. The package expects the URL mount `/ojtrack/`.

Configure these environment variables for the PHP web service and, separately, the CLI when running setup:

| Variable | Meaning |
| --- | --- |
| OJTRACK_DB_HOST | Database host; local default `localhost` |
| OJTRACK_DB_NAME | Application database; local default `ojtrack` |
| OJTRACK_DB_USER | Dedicated application database user |
| OJTRACK_DB_PASS | Database password |
| OJTRACK_TIMEZONE | Optional; default `Asia/Manila` |
| OJTRACK_PRIVATE_UPLOAD_DIR | Existing writable private storage directory outside the application and document root |
| OJTRACK_PUBLIC_ROOT | Actual document root; required for CLI storage checks |

The local compatibility defaults use root with an empty password. Override them for deployment. An `.env` file is not automatically loaded. Do not place service credentials in publicly served files or a release archive.

For XAMPP, an example document root is `C:/xampp/htdocs` and private storage can be `C:/ojtrack-private`. Create the private root and grant the PHP service account access. The storage implementation is in `app/Services/StorageService.php`; `config/storage.php` remains a compatibility entry point. `config/storage.local.php` may return `['private_upload_dir' => 'C:/ojtrack-private']` for local installations; create it locally, not in distributed source. Environment settings take precedence.

Enable PHP `mysqli`, `mbstring`, `fileinfo`, `gd`, `zip`, `iconv`, and `dom`. Configure `upload_max_filesize=10M`, `post_max_size=32M`, and `max_file_uploads` at least 20, or deliberately document another supported aggregate limit. Individual limits are 5 MB for images/proofs and 10 MB for documents. Set `display_errors=Off` and `log_errors=On` on the deployed service.

## Fresh installation

1. Extract `ojtrack/` beneath the web root. Keep application source read-only to the web service.
2. Create an empty UTF-8 database with a dedicated user, then import `database/install.sql`. Do not use a populated export or import over existing tables.
3. Configure the environment and private storage. Make `uploads/` and its needed public categories writable; new private files go to the private root.
4. From a private CLI, run `php bin/create-admin.php "Administrator Name" admin@example.edu` and supply a password through standard input. The command refuses an additional initial admin if one already exists. It never creates a default password.
5. Run `php bin/preflight.php` with the same environment. Resolve reported failures. The credential check intentionally rejects the local root/blank defaults for production readiness.
6. Sign in and create/activate the academic term, programs, coordinators, and company accounts. The optional program catalog command `php bin/set-default-programs.php` previews the supplied catalog; applying it requires an explicitly named new backup path outside the web root.
7. Create students with unique IDs and the intended program, enroll them in the current term, and assign coordinators/companies. Create and assign the actual requirements, narrative reports, and evaluation forms required by the program. New installations contain no operational or demonstration records.

## Server verification

Verify extensionless routes and method-preserving redirects from old `.php` URLs. Confirm requests to `.git/`, `config/`, `database/`, `bin/`, `tests/`, `docs/`, SQL/Markdown/log files, and private upload paths are denied. Verify that no uploaded script can execute. PHP's development server does not enforce Apache rules; Nginx requires equivalent configuration.

Use HTTPS and confirm secure cookies on the intended host. Check that authenticated, authorized document access works while unrelated roles/records remain inaccessible. Confirm the private directory has no public alias.

Complete acceptance using synthetic accounts for all four roles: academic assignments; student onboarding and resubmission; company attendance; journal review; requirement/report approval; evaluation submission and PDF download; monthly PDF submission and coordinator attachment review; messages/archives; announcements; certificate issue/download; and guides on desktop/phone-sized screens.

## Existing installations and recovery

Never import `install.sql` over an existing system. Back up the database and every referenced private/public upload together before an upgrade, use the maintained source's migration tools only after reviewing the target ledger/schema, and test the upgrade on a restored copy. Existing local records and runtime files were preserved during this finalization.

Backups must be outside the web root and access-restricted. Rehearse restoring both data and files, then verify that document references and role access remain correct. New-schema import checks and synthetic tests are recorded in `VERIFICATION.md`; they do not certify a separate deployment host or restore procedure.
