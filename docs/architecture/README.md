# OJTrack web architecture

OJTrack remains a server-rendered PHP application. The current role routes are intentionally preserved while the application is reorganized incrementally.

## Boundaries

- `admin/`, `coordinator/`, `company/`, and `student/` are existing web route areas.
- `api/` contains existing API endpoints.
- `config/` contains current configuration and compatibility helpers.
- `includes/` contains current shared templates and document helpers.
- `app/` is the target backend application layer for new controllers, services, repositories, middleware, validation, and support code.
- `frontend/` is the target location for server-rendered layouts and reusable view components.
- `assets/` contains browser CSS, JavaScript, and images.
- `database/` contains schema, installation SQL, migrations, and seed resources.
- `bin/` remains the current maintenance-script location until a later compatibility-preserving migration to `scripts/` is approved and verified.
- `mobile/` is outside the web architecture and must remain untouched.

## Current service layout

The migrated backend services are grouped under `app/`:

- `app/Infrastructure/Database.php` — database connection and shared query helpers.
- `app/Middleware/` — authentication and security middleware.
- `app/Services/` — profile, assignment, upload, storage, communications, training, evaluation, and normalized data workflows.
- `app/Services/Documents/` — generated PDF/document services.
- `app/Support/` — shared names and avatar support.

Reusable server-rendered UI is grouped under `frontend/`:

- `frontend/layouts/` — shared page shell and footer.
- `frontend/components/` — dashboard, messaging, modal, form, progress, and detail components.
- `frontend/components/filters/` — reusable page and management filters.

The original `config/` and `includes/` paths remain as small compatibility entry points. This allows existing web routes, API endpoints, scripts, and tests to continue working while new code uses the organized locations directly.

## Request flow

Existing route → authentication/authorization → current helper or future controller → service → database/storage → PHP view.

New code should prefer the `app/` and `frontend/` boundaries without changing existing URLs, database structures, API formats, or upload permissions.
