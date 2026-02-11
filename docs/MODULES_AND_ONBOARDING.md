# Module Dependencies and Onboarding Checklist

## Module classification and dependencies

### Tiers

Modules are classified into three tiers (see `apps/api/config/modules.php`):

- **CORE** – Cannot be disabled. Includes: `accounting_core`, `projects_crop_cycles`, `treasury_payments`, `reports`.
- **CORE_ADJUNCT** – Core-adjunct (e.g. `land`). Can be disabled only if no enabled module depends on it.
- **OPTIONAL** – All other modules.

### Hard dependencies

The backend enforces a **dependency map** (config-based, no DB schema change):

- Enabling a module automatically enables all its hard dependencies (transitive).
- Disabling a module is **blocked** if any currently enabled module depends on it (directly or transitively). The API returns `422` with `error: "MODULE_DEPENDENCY"`, `message`, and `blockers` (list of module keys that depend on the one you tried to disable).

Example map (see `config/modules.php`):

| Module                 | Hard dependencies        |
|------------------------|---------------------------|
| projects_crop_cycles   | land                      |
| settlements            | projects_crop_cycles      |
| inventory, labour, machinery | projects_crop_cycles |
| crop_ops               | projects_crop_cycles, inventory, labour |
| treasury_advances      | treasury_payments        |
| ar_sales               | (none)                    |

### API behaviour

- **GET /api/tenant/modules** – Returns each module with `tier`, `enabled`, and `required_by` (list of enabled module keys that depend on this module). The UI uses this to disable the toggle and show “Required by: X, Y”.
- **PUT /api/tenant/modules** – In a transaction:
  - **Enabling** a module: computes the transitive closure of dependencies and upserts all required modules to ENABLED. Response can include `auto_enabled: { "module_key": ["dep1", "dep2"] }` for toast messaging.
  - **Disabling** a module: if the module is core, or any enabled module depends on it, returns `422` with the structure above; otherwise sets the module to DISABLED.

### Frontend (Module Toggles UI)

- Toggle is **disabled** if the module is core or has `required_by.length > 0`.
- When disabled due to dependents, a “Required by: X, Y” label is shown.
- On successful save, if the response contains `auto_enabled`, a toast is shown: “Enabled X (also enabled: A, B)”.
- When the backend rejects a disable (422), the server `message` is shown in a toast.

---

## Onboarding checklist

A **streamlined onboarding checklist** is shown to **tenant_admin** on first login (and until dismissed).

### Steps

1. **Farm Profile** (required) – links to `/app/admin/farm`
2. **Add Land Parcel** (required) – links to `/app/land`
3. **Create Crop Cycle** (required) – links to `/app/crop-cycles`
4. **Create First Project** (required) – links to `/app/projects`
5. **Add First Party** (optional) – links to `/app/parties`
6. **Post First Transaction** (optional) – links to `/app/transactions`

### Persistence

- Onboarding state is stored per tenant in the **tenant `settings`** JSON column (`tenant.settings.onboarding`).
- Fields: `dismissed` (boolean), `steps` (object mapping step id to completed boolean).
- **GET /api/tenant/onboarding** – returns current state (tenant_admin only).
- **PUT /api/tenant/onboarding** – update `dismissed` and/or `steps` (tenant_admin only).

### UX

- The checklist is rendered at the top of the main content area when the user is `tenant_admin` and `dismissed` is false.
- **Dismiss** hides the checklist (sets `dismissed: true`). It can be reopened from **Settings** (Localisation): the “Reopen onboarding checklist” button sets `dismissed: false` so the checklist appears again on the next load/refetch.

### UI states (notes)

- **Toggle disabled (core)** – Badge “Core” shown; switch disabled; no “Required by” (core is never a dependency of another in the same sense).
- **Toggle disabled (required by others)** – Badge may be “Core-adjunct” or “Optional”; “Required by: Projects & Crop Cycles” (or other names) shown below the description; switch disabled.
- **After enabling a module with dependencies** – Success toast: “Module settings saved” and, if applicable, “Enabled Crop Operations (also enabled: Projects & Crop Cycles, Land, Inventory, Labour)”.
- **After trying to disable a required module** – Error toast with the server message, e.g. “Cannot disable this module because the following enabled modules depend on it: projects_crop_cycles. Disable those first.”
