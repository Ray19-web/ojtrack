# OJTrack application storage

Runtime uploads should remain outside the web root whenever possible, using `OJTRACK_PRIVATE_UPLOAD_DIR` and the storage service in `app/Services/StorageService.php`.

The existing `config/storage.php` file remains as a compatibility entry point for scripts and older routes.

Expected logical categories are `requirements/`, `reports/`, and `journal_proofs/`. Public compatibility uploads may remain under `uploads/` until the storage migration is completed and verified.

Do not place credentials, source code, or executable files in this directory.
