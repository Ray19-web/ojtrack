# OJTrack application layer

This directory is the gradual backend architecture for the web application.

- `Controllers/` coordinates HTTP requests by role.
- `Services/` contains business workflows.
- `Repositories/` contains reusable data-access operations.
- `Middleware/` contains authentication, authorization, and request checks.
- `Validation/` contains reusable input validation.
- `Support/` contains framework-independent support services.

Existing role routes and `config/` helpers remain compatible while functionality is migrated incrementally.

Current migrated areas:

- `Infrastructure/Database.php` — database bootstrap and shared query helpers.
- `Middleware/` — authentication and security checks.
- `Services/` — profile, assignment, communication, conversation, evaluation, training, normalized data, storage, upload, and document workflows.
- `Support/` — person-name and avatar support.

New backend code should use these locations directly. The legacy `config/` files are compatibility entry points only.
