# Running E2E (Playwright)

## Force-all-modules flag (TEMP)

For system completion and Playwright testing we support a **temporary** override that makes all modules appear enabled for all tenants and bypasses module enforcement. This simplifies development and keeps E2E stable (e.g. `modules-ready` reaches `ready` immediately).

**This is TEMP and will be removed later.** To revert, search for `TEMP` and `FORCE_ALL_MODULES_ENABLED` in the codebase.

### How to enable

- **API (Laravel):** set in `.env` or environment:
  - `FORCE_ALL_MODULES_ENABLED=true`
- **Web (Vite):** set when starting the dev server (e.g. in `.env` or when running the E2E dev command):
  - `VITE_FORCE_ALL_MODULES_ENABLED=true`

For local dev and CI you typically set both so that the API returns all modules as enabled and the frontend shows everything as ready without waiting on the modules API.

### When the flag is off

When `FORCE_ALL_MODULES_ENABLED` / `VITE_FORCE_ALL_MODULES_ENABLED` is false or unset, the normal module system applies: only core and explicitly enabled (plus dependency) modules are effective, and `require_module` middleware enforces access.
