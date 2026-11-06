# OJTrack frontend layer

This directory is reserved for the gradual separation of server-rendered views from request handling.

- `layouts/` shared page shells
- `components/` reusable UI components
- `forms/` reusable form fragments
- `modals/` reusable modal fragments
- `views/` role-specific page views

The current application continues to use the compatible `includes/` directory until each view is migrated and verified.

Current migrated areas include shared layouts, dashboard components, messaging components, role-specific detail/modal components, form/progress components, and reusable filters. The legacy `includes/` files are compatibility entry points that load these organized components.
