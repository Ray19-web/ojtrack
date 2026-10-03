# Synthetic integration checks

Requires PHP CLI with mysqli/fileinfo, MariaDB client/server, Python 3, and Node.js. These scripts are development-only and must not be deployed.

1. Create an isolated database named **ojtrack_test** and import database/schema.sql. It must be empty before seeding.
2. Set OJTRACK_DB_NAME=ojtrack_test and the connection environment variables, then run php tests/seed.php. For a Unix socket, pass php -d mysqli.default_socket=/path/to/socket.
3. Serve the project at /ojtrack/ through Apache with the documented deny rules, or a local PHP test server with an explicit router denying protected directories. PHP's built-in server does not enforce .htaccess.
4. Set OJTRACK_TEST_CONFIRM=synthetic, OJTRACK_TEST_URL=http://127.0.0.1:8087/ojtrack/, and OJTRACK_TEST_SOCKET=/path/to/socket, then run python tests/integration.py.
5. The suite uses the MariaDB client with the root user over the specified **isolated** socket and the hardcoded ojtrack_test database. Adapt credentials for your disposable environment if needed.
6. Recreate the disposable database and seed it before rerunning. Tests change a password, notifications, journals and evaluation records. Synthetic private files are created beneath uploads and ignored by git.

The direct-file denial assertion verifies the configured test host/router, not deployment correctness. Repeat it on Apache/XAMPP. The tests do not perform visual or concurrent multi-worker testing.
