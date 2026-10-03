# Synthetic integration checks

Requires PHP CLI with mysqli/fileinfo/GD/ZIP, MariaDB client/server, Python 3, and Node.js. These scripts are development-only and must not be deployed.

1. Create an isolated database named **ojtrack_test** and import database/schema.sql. It must be empty before seeding.
2. Set OJTRACK_DB_NAME=ojtrack_test and the connection environment variables, then run php tests/seed.php. For a Unix socket, pass php -d mysqli.default_socket=/path/to/socket.
3. Serve the project at /ojtrack/ through Apache with the documented deny rules, or a local PHP test server with an explicit router denying protected directories. PHP's built-in server does not enforce .htaccess.
4. Set OJTRACK_TEST_CONFIRM=synthetic, OJTRACK_TEST_URL=http://127.0.0.1:8087/ojtrack/, and OJTRACK_TEST_SOCKET=/path/to/socket, then run python tests/integration.py.
5. The suite uses the MariaDB client with the root user over the specified **isolated** socket and the hardcoded ojtrack_test database. Adapt credentials for your disposable environment if needed.
6. Recreate the disposable database and seed it before rerunning. Tests change a password, notifications, journals and evaluation records. Synthetic private files are created beneath uploads and ignored by git.

The direct-file denial assertion verifies the configured test host/router, not deployment correctness. Repeat it on Apache/XAMPP. The tests do not perform visual or concurrent multi-worker testing.

After integration.py passes, run tests/uploads.py against the same disposable database with OJTRACK_TEST_CONFIRM=synthetic. This second suite expects the changed synthetic password and uses real multipart uploads. Configure the test PHP server with upload_max_filesize=10M and post_max_size=32M. It creates synthetic documents/images, updates fixtures and checks invalid-file rejection, no partial batch preflight mutations, image normalization, ownership and size limits.


Private-storage follow-up: create a separate empty directory outside the test server's document root. Pass its absolute path as OJTRACK_PRIVATE_UPLOAD_DIR to both PHP and tests/uploads.py. The legacy seed documents remain beneath uploads so the original integration suite still exercises fallback. New uploaded requirements/reports/proofs are expected in the private root; avatars stay in uploads.

Run php tests/storage.php for isolated path/configuration/symlink checks. It does not use a database and cleans up only its own temporary fixtures. Run the copy command first in dry-run mode against the synthetic database, then copy mode, then repeat copy mode to check that matching files are recognized. The command must report conflicts without replacing destination content. Also verify missing configuration rejects new private uploads with 503, while old authorized downloads continue working.

These new private-storage checks were authored but could not be executed in the latest assistant environment because PHP/MariaDB were unavailable. Earlier reported 414+67 passes apply to the previous upload-validation commit, not this follow-up.
