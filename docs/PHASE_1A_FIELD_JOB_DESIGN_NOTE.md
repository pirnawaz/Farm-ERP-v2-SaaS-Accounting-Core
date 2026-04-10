# Phase 1A — Field Job foundation (design note, apps/api audit)

**Scope:** Audit of current crop ops, labour, and machinery operational documents and their posting paths. **No production code changes in this step.** This note informs the next implementation step for a unified **Field Job** document without breaking existing flows.

**Referenced codebase root:** `apps/api/`

---

## 1. Current source documents and posting side effects

| Source document | Primary models / tables | Posting service | `PostingGroup.source_type` | Ledger (`LedgerEntry`) | Allocation (`AllocationRow`) | Inventory (`InvStockMovement` via `InventoryStockService`) | Labour subledger (`LabWorkerBalance`) | Other |
|-----------------|-------------------------|-----------------|---------------------------|------------------------|------------------------------|-------------------------------------------------------------|----------------------------------------|-------|
| **Crop activity** | `CropActivity` → `crop_activities`; lines: `CropActivityInput` / `crop_activity_inputs`, `CropActivityLabour` / `crop_activity_labour` | `App\Services\CropActivityPostingService` | `CROP_ACTIVITY` | Yes: Dr `INPUTS_EXPENSE` / Cr `INVENTORY_INPUTS` (inputs); Dr `LABOUR_EXPENSE` / Cr `WAGES_PAYABLE` (labour) | Yes: `POOL_SHARE` rows for inputs and labour (`amount`), `project_id` + `party_id` from `Project` | Yes: `ISSUE` per input line (`applyMovement`, negative qty/value) | Yes: `increment` per labour line on post; `decrement` on reverse from line `amount` | Status → `POSTED` / `REVERSED`; lines get `unit_cost_snapshot`, `line_total` / `amount`. **Does not** create `OperationalTransaction`. |
| **Labour work log** | `LabWorkLog` → `lab_work_logs` | `App\Services\LabourPostingService` | `LABOUR_WORK_LOG` | Yes: Dr `LABOUR_EXPENSE` / Cr `WAGES_PAYABLE` | Yes: `POOL_SHARE`, `machine_id` optional on row | No | Yes: `increment` on post; `decrement` on reverse | Creates `OperationalTransaction` (`type` `EXPENSE`, `classification` `SHARED`, `status` `POSTED`); on reverse sets OT to `VOID`. |
| **Machine work log** | `MachineWorkLog` → `machine_work_logs` | `App\Services\Machinery\MachineryPostingService` | `MACHINE_WORK_LOG` | **No** (usage-only allocation) | Yes: single row `allocation_type` `MACHINERY_USAGE`, `quantity` + `unit`, `allocation_scope` from `pool_scope` | No | No | Status, `posted_at`, `posting_group_id`; optional FK `activity_id` → `crop_activities`. Reversal: **custom** reversal `PostingGroup` (`source_type` `REVERSAL`), negated `quantity` on allocation — **not** `ReversalService` for the happy path (uses `ReversalService`-like pattern locally). |
| **Machinery charge** | `MachineryCharge` / `MachineryChargeLine` → `machinery_charges`, `machinery_charge_lines` | `App\Services\Machinery\MachineryChargePostingService` | `MACHINERY_CHARGE` | Yes: Dr `DUE_FROM_HARI` or expense pool account / Cr `MACHINERY_SERVICE_INCOME` (by `pool_scope`) | Yes: `MACHINERY_CHARGE`, `amount`, optional `machine_id` from first line’s work log | No | No | Links posted charge to `posting_group_id`; reversal via `App\Services\ReversalService::reversePostingGroup`. Work logs may keep `machinery_charge_id` after reversal (comment in service: reservation intact). |

**Supporting services (not full “documents” in this list but in scope):**

- **`App\Services\PostingService`** — posts `OperationalTransaction` (`source_type` `OPERATIONAL`); cash-based GL; not the same as crop/labour/machinery activity posting.
- **`App\Services\InventoryPostingService`** — GRN/issue/transfer/adjustment posting; uses `PostingIdempotencyService`, `ReversalService`, `InventoryStockService`; **parallel** inventory pipeline to crop activity issues.
- **`App\Services\ReversalService`** — canonical reversal of a `PostingGroup`: new PG with `source_type` `REVERSAL`, negated `LedgerEntry` and mirrored negated `AllocationRow` amounts; idempotent on `(tenant, reversal_of_posting_group_id, posting_date)` (see migration `2024_01_01_000011_add_reversal_to_posting_groups.php`). Used by crop activity reverse (after GL reversal), labour reverse, machinery charge reverse; **not** used for machine work log reversal (custom path).

**Guards:**

- **`App\Services\OperationalPostingGuard`** — `ensureCropCycleOpen` / `ensureCropCycleOpenForProject` (project not `CLOSED` + cycle `OPEN`); used by crop activity, labour, machinery charge; machine work log uses `ensureCropCycleOpen` on `crop_cycle_id`.
- **`App\Services\LedgerWriteGuard`** — allowlisted classes only may create `LedgerEntry` (`config/ledger_write_allowlist.php`). `MachineryPostingService` is **not** allowlisted (no GL lines).

---

## 2. Tables/columns reusable for Field Job compatibility

**Core accounting anchor (reuse as-is):**

- **`posting_groups`**: `tenant_id`, `crop_cycle_id`, `source_type`, `source_id`, `posting_date`, `idempotency_key`, reversal metadata. DB uniqueness: `(tenant_id, idempotency_key)` and `(tenant_id, source_type, source_id)` (see `2024_01_01_000024_update_posting_groups_add_crop_cycle_idempotency.php`).
- **`ledger_entries`**, **`allocation_rows`**: unchanged contract; new Field Job posting should introduce **new** `source_type` values (e.g. `FIELD_JOB`) to avoid colliding with existing `CROP_ACTIVITY` / `LABOUR_WORK_LOG` keys.
- **`projects`**: remains the settlement/accounting anchor (`party_id`, cycle linkage via `crop_cycle_id` on document).
- **`crop_cycles`**: locking/status remains authoritative (`OPEN` vs closed).

**Operational subledgers / stock:**

- **`inv_stock_movements`**, **`InventoryStockService`**: reuse for Field Job **inputs** (same pattern as `CropActivityPostingService`).
- **`lab_worker_balances`**: reuse for labour lines (same increment/decrement semantics as existing services).

**Reference dimensions already on legacy documents (mirror on `field_jobs` for parity):**

- `tenant_id`, `crop_cycle_id`, `project_id`, optional `production_unit_id`, `land_parcel_id` (from `crop_activities` / `lab_work_logs`).
- Doc identity: `doc_no` pattern exists on crop activities and lab work logs; machine logs use `work_log_no`.

**Cross-links already in schema:**

- `machine_work_logs.activity_id` → optional `crop_activities.id` (migration `2026_01_28_044446_add_pool_scope_to_machine_work_logs.php`).
- `lab_work_logs.activity_id` → optional crop activity.
- `machine_work_logs.machinery_charge_id` → billing reservation against `machinery_charges`.

**Idempotency helper:**

- **`App\Services\PostingIdempotencyService`**: documented standard (`effectiveKey`, `findExistingPostingGroup` with cross-source key validation). Used heavily in `InventoryPostingService`, `HarvestService`, etc.; **crop/labour/machinery posting services mostly hand-roll** similar logic (see risks below).

---

## 3. Invariants that must be preserved

1. **Accounting immutability** — posted `LedgerEntry` / `AllocationRow` are not edited in place; corrections go through **explicit** reversal (`REVERSAL` / negating movements) or controlled services.
2. **Financial impact only through posting groups** — operational tables move to `POSTED` only after `PostingGroup` creation and related side effects in the same transaction (as today).
3. **Explicit post/reverse** — no silent auto-post of field operations outside approved services.
4. **Project as anchor** — allocations require valid `project_id` / `party_id` where existing flows require them; crop cycle must align with project.
5. **Crop cycle locking** — `OperationalPostingGuard` rules remain mandatory before new PG creation.
6. **Phase 1A** — **no breaking changes** to existing HTTP routes under `crop-ops`, `labour`, `machinery` (`routes/api.php` groups ~lines 411–509).

---

## 4. Old flows that must keep working during Phase 1

- **Crop ops:** `CropActivityController` + `crop_activities` CRUD, `POST /api/v1/crop-ops/activities/{id}/post`, `.../reverse`; harvest routes unchanged.
- **Labour:** `LabWorkLogController` + work log CRUD and post/reverse under `/api/v1/labour/...`.
- **Machinery:** machine work logs, charges (`generate`, post/reverse), machinery services, maintenance jobs, reports — unchanged contracts.
- **Inventory:** issues/GRN/transfers driven by `InventoryPostingService` remain independent; Field Job must not intercept those routes.

New Field Job behavior should be **additive**: feature-flagged or **new** route prefix only, until a later phase cuts over UX.

---

## 5. Proposed schema (new tables)

Design goal: one **header** with typed child collections, without migrating historical rows in Phase 1A.

### `field_jobs`

| Column | Type / notes |
|--------|----------------|
| `id` | UUID PK |
| `tenant_id` | UUID FK |
| `doc_no` | string, unique per tenant (align with `crop_activities` / `lab_work_logs`) |
| `status` | enum: `DRAFT`, `POSTED`, `REVERSED` (match existing operational patterns) |
| `crop_cycle_id`, `project_id` | UUID FK, required for posting (mirror guards) |
| `activity_type_id` | optional FK → `crop_activity_types` (reuse taxonomy) or nullable if Field Job types diverge later |
| `job_date` | date (operational date; analogue to `activity_date` / `work_date`) |
| `production_unit_id`, `land_parcel_id` | optional FKs |
| `notes` | text |
| `posting_date`, `posted_at`, `reversed_at` | same semantics as `crop_activities` |
| `posting_group_id` | FK → `posting_groups` (single PG per posted job if orchestration matches crop activity “one PG” model) |
| `created_by` | UUID nullable |
| timestamps | tz |

Indexes: `(tenant_id, status)`, `(tenant_id, crop_cycle_id, project_id)`, `(tenant_id, job_date)`.

### `field_job_inputs`

| Column | Type / notes |
|--------|----------------|
| `id` | UUID PK |
| `tenant_id` | UUID |
| `field_job_id` | UUID FK → `field_jobs` |
| `store_id`, `item_id` | FKs (same as `crop_activity_inputs`) |
| `qty` | decimal |
| `unit_cost_snapshot`, `line_total` | populated on post (same as crop activity inputs) |

### `field_job_labour`

| Column | Type / notes |
|--------|----------------|
| `id` | UUID PK |
| `tenant_id` | UUID |
| `field_job_id` | UUID FK |
| `worker_id` | FK → `lab_workers` |
| `rate_basis`, `units`, `rate`, `amount` | align with `crop_activity_labour` / `lab_work_logs` |

### `field_job_machines`

| Column | Type / notes |
|--------|----------------|
| `id` | UUID PK |
| `tenant_id` | UUID |
| `field_job_id` | UUID FK |
| `machine_id` | FK → `machines` |
| `pool_scope` | reuse enum semantics from `machine_work_logs` (`SHARED`, `HARI_ONLY`, `LANDLORD_ONLY`) |
| `work_date` | date (or inherit from header only — prefer single `job_date` on header to avoid drift) |
| `meter_start`, `meter_end`, `usage_qty` | align with `machine_work_logs` |
| `notes` | optional |

**Open design choice (next step):** whether machine rows represent **usage allocation only** (like current `MachineryPostingService`) or also drive **machinery charges**. Charges today are a **separate** document (`machinery_charges`) generated from posted work logs; Field Job should not duplicate charge posting without an explicit rule.

---

## 6. Service orchestration approach

**Reuse existing posting services directly?**

- **Option A — Thin orchestrator calling existing services:** Risky: each service expects **its own** document ID and creates **its own** `PostingGroup`. A single Field Job would either create **multiple** PGs (violates “one operational event / one PG” pattern used by `CropActivityPostingService`) or double-apply labour/inventory if pointed at shadow rows.
- **Option B — New `FieldJobPostingService` (recommended):** One service owns **one** `PostingGroup` with a new `source_type` (e.g. `FIELD_JOB`), composing **the same atomic side effects** as today:
  - Inputs: same `InventoryStockService::applyMovement` + GL lines as crop activity inputs.
  - Labour: same GL + `LabWorkerBalance` + **decide whether** to create `OperationalTransaction` (today only `LabourPostingService` does — product choice; default for parity with crop activity embedded labour is **no** OT unless product requires it).
  - Machines: same `AllocationRow` `MACHINERY_USAGE` semantics as `MachineryPostingService` **or** only child rows without separate `MachineWorkLog` records in Phase 1 — must be explicit in implementation.

**Shared helpers to extract (incremental, not big-bang refactor):**

- Normalize **idempotency** on `PostingIdempotencyService` for any new service (and optionally align labour posting later).
- Shared **crop cycle date validation** (duplicated `Carbon` range checks across services).
- Optional: small **“post labour GL + allocation + balance”** private collaborator if both `CropActivityPostingService` and `FieldJobPostingService` need identical lines (keep extraction minimal per user rules).

**Where idempotency should live:**

- **Primary:** `posting_groups.idempotency_key` + unique `(tenant_id, source_type, source_id)` for `FIELD_JOB` + job id.
- **Application layer:** `PostingIdempotencyService::findExistingPostingGroup` for client-supplied keys and collision detection.
- **Reversal:** continue `ReversalService::reversePostingGroup` for GL + allocation; combine with **inventory and labour subledger** reversals exactly as `CropActivityPostingService::reverseActivity` does (stock movements + worker balance), not only `ReversalService`.

---

## 7. Migration safety plan

- **No historical data migration** in Phase 1A/1B foundation: new tables only; existing `crop_activities`, `lab_work_logs`, `machine_work_logs`, `machinery_charges` remain authoritative for old rows.
- **Old records stay valid** — posting groups and statuses unchanged.
- **New records** use Field Job only when **feature flag** is on and/or **new routes** (e.g. `/api/v1/crop-ops/field-jobs`) are deployed; default production paths keep current controllers.
- **No duplicate posting:** a Field Job must not also call legacy `post` endpoints for the same economic event; UI/API contract should enforce “one document type per event” per phase.

---

## 8. Compatibility and duplicate-posting risks

| Risk | Detail |
|------|--------|
| **Double GL / double balance** | Calling both legacy post and Field Job post for the same physical work. Mitigation: feature flag, distinct routes, and never linking one `field_job` row to legacy post endpoints in the same workflow. |
| **Labour idempotency inconsistency** | `LabourPostingService` checks `idempotency_key` first but does **not** short-circuit via `source_type`/`source_id` before insert; duplicate client keys are handled; conflicting keys rely on **DB unique** on `(tenant_id, LABOUR_WORK_LOG, source_id)` — may **error** instead of returning existing PG. Align Field Job with `PostingIdempotencyService` to avoid UX mismatch. |
| **Machine log reversal paths** | `MachineryPostingService::reverseWorkLog` differs from `ReversalService` (allocation-only). Field Job machine reversal must mirror the chosen semantics (usage reversal) consistently. |
| **`OperationalTransaction` only on standalone lab logs** | Embedded labour in crop activity does not create OT; standalone lab does. Field Job product decision must document which behaviour applies. |
| **Machinery charges** | Charges aggregate **posted** machine work logs; Field Job machine rows are not `machine_work_logs` unless synced. Avoid generating charges twice for the same usage. |

---

## 9. Safe implementation order (next coding step)

1. **Migrations** — `field_jobs`, `field_job_inputs`, `field_job_labour`, `field_job_machines` (no backfill).
2. **Enum / `posting_group` source type** — register `FIELD_JOB` in the same mechanism as other `source_type` values (follow `add_*_to_posting_group_source_type` migrations pattern).
3. **Model layer** — Eloquent models + factories for tests.
4. **`FieldJobPostingService`** — single PG, reuse stock/labour/allocation patterns from `CropActivityPostingService` + machine allocation pattern from `MachineryPostingService`; wrap in `LedgerWriteGuard`; use `OperationalPostingGuard`.
5. **Reversal** — `ReversalService` + stock + labour adjustments; machine reversal aligned with machinery usage reversal rules.
6. **API** — new controller + form requests under **new** routes; config/feature flag; **do not** alter existing `CropActivityController` / `LabWorkLogController` / `MachineWorkLogController` routes.
7. **Tests** — feature tests mirroring `ActivityPostIdempotencyTest`, `WorkLogPostIdempotencyTest`, `MachineryChargePostingTest` patterns for the new document.

---

## 10. Exact file plan (next implementation step)

| Action | Path(s) |
|--------|---------|
| New migration | `apps/api/database/migrations/*_create_field_jobs_tables.php` (split if preferred) |
| Register source type | `apps/api/database/migrations/*_add_field_job_to_posting_group_source_type.php` (match existing enum migration style) |
| Models | `apps/api/app/Models/FieldJob.php`, `FieldJobInput.php`, `FieldJobLabour.php`, `FieldJobMachine.php` |
| Posting | `apps/api/app/Services/FieldJobPostingService.php` (name may match product naming) |
| Guard allowlist | `apps/api/config/ledger_write_allowlist.php` — add new posting service class |
| HTTP | `apps/api/app/Http/Controllers/FieldJobController.php`, `Store/Update/Post/Reverse` requests |
| Routes | `apps/api/routes/api.php` — new group under `crop-ops` or dedicated prefix + middleware |
| Tests | `apps/api/tests/Feature/FieldJobPostingTest.php` (and idempotency/reversal variants) |

---

*This note is specific to the current `apps/api` implementation as audited; re-verify line references if controllers or services move.*
