# Phase 8A.1 — Planning & Forecasting (Design)

**Status:** Design only (no implementation).  
**Domain:** `apps/api` (planning / forecast domain layer — **not** accounting).  
**Builds on:** Phase 7 profitability (`PHASE_7A_1_PROFITABILITY_ENGINE_DESIGN.md`, project/machine/harvest economics read models). Planning **consumes** Phase 7 **actuals** for comparison only; it **never** feeds or replaces posting.

---

## Purpose

Define a **planning model** that lets operators and accountants record **expectations** for a field cycle (and optional finer scope): **what we plan to spend** (inputs, labour, machinery), **what we expect to harvest** (quantity and value), and **how that compares to reality** once operations post.

This is a **parallel track** to the ledger: plans are **versioned business assumptions**, not journal lines.

---

## 1. Design principles

| Principle | Meaning |
|-----------|---------|
| **Clean separation from accounting** | Planning tables hold **budgets and forecasts only**. They do **not** reference `posting_group_id`, `ledger_entry_id`, or journal accounts. |
| **No coupling with posting** | No foreign keys from `posting_groups` / `ledger_entries` **into** planning rows. Actuals are **joined at read time** by `tenant_id` + scope keys (`project_id`, `crop_cycle_id`, optional `production_unit_id`, date windows). |
| **Planning never writes to ledger** | No service in the planning domain may call posting services, create allocations, or touch `ledger_entries`. |
| **Extensible** | New budget **lines** (e.g. landlord pool, contractor) or forecast **metrics** should be addable without migrating posted data; use **typed line items** or JSON **extensions** with stable core columns. |
| **Tenant-scoped** | All entities carry `tenant_id`; RLS / tenant context matches the rest of the API. |

---

## 2. Planning entities (conceptual)

### 2.1 `project_plan` (header)

**Meaning:** One **planning container** per **project** (field cycle in product language), optionally refined by crop cycle and production unit. Holds metadata and ties child budgets and yield forecast.

| Concept | Notes |
|---------|--------|
| **Identity** | `id` (UUID), `tenant_id` |
| **Scope** | `project_id` **required** (primary anchor). `crop_cycle_id` **required** and must equal `projects.crop_cycle_id` for integrity (or denormalized from project for query convenience with a check). `production_unit_id` **optional** (nullable) — when set, plan lines default to this sub-scope. |
| **Versioning (recommended)** | `plan_version` (int) or `label` (e.g. “2026 initial”, “Rev B”) so multiple scenarios can coexist without overwriting history. |
| **Period** | `period_start`, `period_end` (dates) — planning horizon for variance vs actuals (aligned with crop season or management choice). |
| **Status** | e.g. `DRAFT`, `ACTIVE`, `ARCHIVED` — does not affect posting. |
| **Audit** | `created_by_user_id`, `updated_at`, etc. |

**Cardinality:** At most one **active** business rule per `(tenant_id, project_id, crop_cycle_id, production_unit_id)` if the product enforces a single “current plan”; alternatively allow multiple named scenarios (see extensibility).

---

### 2.2 `cost_budget` (lines)

**Meaning:** **Expected** cost by **category** for the plan. Stored as **lines** attached to `project_plan_id` (or denormalized scope keys for reporting — prefer FK to `project_plan` for normalization).

| Concept | Notes |
|---------|--------|
| **Identity** | `id`, `tenant_id`, `project_plan_id` |
| **Budget type** | Discriminator: `inputs` \| `labour` \| `machinery` (see §4). |
| **Measure** | Either **quantity-like** (e.g. kg, hours, hectares) with optional **unit**, or **money** (expected cost in base currency), depending on type — see §4. |
| **Granularity** | Optional `line_key` or `category_code` (e.g. fertilizer family, labour pool, machine group) for rollups without new tables. |
| **Notes** | Free text for operator context. |

**Not stored:** Account codes, posting templates, or links to `ledger_entries`.

---

### 2.3 `yield_forecast`

**Meaning:** **Expected** harvest outcome for the same plan scope.

| Field | Required | Description |
|-------|----------|-------------|
| `project_plan_id` | Yes | Parent plan. |
| `expected_quantity` | Yes | Physical output (e.g. tonnes, bags) with `quantity_uom`. |
| `expected_unit_value` | No | Expected **farm gate** or **internal** value per unit of measure (base currency / unit) — optional when only total value matters. |
| `expected_total_value` | Yes* | Total expected output value in **base currency**. *If `expected_unit_value` is set, total may be derived; if not, total can be entered directly. |

**Rules:**

- `expected_total_value` should reconcile with quantity × unit value when both are present (implementation may enforce or warn).
- Multiple products (e.g. grades) can be **multiple `yield_forecast` rows** per plan or a child `yield_forecast_line` table in a later phase — design allows either; start with **one aggregate row per plan** or **one row per inventory item** via optional `inventory_item_id`.

---

## 3. Scope model

| Key | Role |
|-----|------|
| **`project_id`** | **Primary** anchor — all planning rows tie to a project (field cycle). |
| **`crop_cycle_id`** | **Required** on `project_plan`; must be consistent with `projects.crop_cycle_id`. Used for listing and for joining Phase 7 actuals that filter by cycle. |
| **`production_unit_id`** | **Optional** — orchard block, paddock, or other sub-field unit when the tenant uses production units. When null, the plan applies to the **whole project**. |

**Comparison joins:** Actuals from Phase 7 (e.g. project profitability, machine profitability, harvest economics) are aggregated using the **same** `(tenant_id, project_id, crop_cycle_id)` and optionally filtered by `production_unit_id` when the operational source exposes it (`PHASE_7A_1` resolution rules).

---

## 4. Budget types

All types are **expectations**, not accruals.

### 4.1 Inputs (`inputs`)

- **Intent:** Expected **consumption** of crop inputs (seed, fertilizer, crop protection) and/or **purchase cost** committed for the season.
- **Suggested measures:**  
  - **Quantity + UOM** (e.g. 200 kg N) with optional **expected unit cost** → implied money; or  
  - **Money only** (lump-sum input budget).
- **Actual (Phase 7):** Inventory issues / input expense buckets from profitability or inventory analytics — compared in the **comparison layer** (§5), not stored on the budget row.

### 4.2 Labour (`labour`)

- **Intent:** Expected **person-days**, **hours**, or **straight cost** (base currency) for hired and/or pool labour.
- **Suggested measures:** `expected_units` + `unit` (e.g. DAYS, HOURS) **or** `expected_cost` only.
- **Actual:** Labour postings / allocation-backed labour cost for the scope and period.

### 4.3 Machinery (`machinery`)

- **Intent:** Expected **machine hours** by machine or category **and/or** expected **machinery cost** (fuel, hire, internal charge).
- **Suggested measures:** Hours + optional rate → cost, or total **expected_cost**.
- **Actual:** Machine profitability / work-log aggregates for the same scope and date range (Phase 7).

---

## 5. Comparison model (planned vs actual)

Planning does **not** duplicate ledger math. **Variance** is computed **at read time** (reporting service or API):

1. **Resolve plan** — latest `ACTIVE` `project_plan` for scope (or explicit version).
2. **Resolve actuals** — call existing **read-only** Phase 7 aggregates (or equivalent SQL) for the same `tenant_id`, `project_id`, `crop_cycle_id`, optional `production_unit_id`, and **posting_date** (or harvest date) range overlapping `period_start`–`period_end`.
3. **Map buckets** — align `cost_budget` lines to profitability **buckets** (inputs / labour / machinery / landlord as applicable) using **stable category codes** agreed in implementation; unmapped lines appear as “plan only” until mapping exists.
4. **Variance** — for each aligned pair:  
   - `variance_amount = actual − planned` (or `planned − actual` — pick one sign convention and document it in API).  
   - `variance_pct = variance_amount / planned` when `planned ≠ 0`; null or “N/A” when planned is zero.

**Yield:**

- **Planned:** `yield_forecast.expected_quantity` / `expected_total_value`.
- **Actual:** Posted harvests / inventory movements for the project (and optional production unit) in range — quantity and value from **operational** or **Phase 7 harvest economics** read models, not from planning tables.

**Output shape (illustrative):** `{ planned, actual, variance_abs, variance_pct }` per metric; no new persisted rows required for variance (optional `variance_snapshot` table only if the product needs **point-in-time** frozen reports).

---

## 6. Invariants

1. **Planning never writes to ledger** — no inserts/updates to `ledger_entries`, `posting_groups`, `allocation_rows` from planning services.
2. **No coupling with posting** — planning migrations must not add FKs to posting tables; posting migrations must not reference planning tables **as dependencies** for core posting paths.
3. **Actuals remain authoritative** — if plan and actual disagree, **posted truth** wins for financial statements; plans are **expectations** only.
4. **Tenant isolation** — all planning entities are `tenant_id`-scoped; cross-tenant reads forbidden.
5. **Crop / project consistency** — `project_plan.crop_cycle_id` must match `projects.crop_cycle_id` for the given `project_id` (enforced in app or DB check).

---

## 7. Extensibility

| Direction | Approach |
|-----------|----------|
| **New budget dimensions** | Add optional columns or a `cost_budget_extensions` JSON with validated schema per tenant. |
| **Multiple scenarios** | `scenario_key` or `plan_version` on `project_plan`; comparison API selects plan A vs actual or plan A vs plan B. |
| **Finer yield** | Child table `yield_forecast_line` with `inventory_item_id` / grade. |
| **Non-financial KPIs** | Separate `plan_kpi` entity (rainfall, degree days) with no ledger tie. |
| **Landlord / revenue budgets** | New budget type or line `category_code` — still no ledger write. |

---

## 8. Out of scope (8A.1)

- UI wireframes, API routes, or migrations.
- Automatic plan creation from prior season (could be a later **copy plan** feature).
- Integration with external ERP or weather APIs.

---

## 9. Relation to Phase 7 (summary)

| Phase 7 | Phase 8A planning |
|---------|-------------------|
| Read-only **actual** profitability | Read/write **planned** budgets and forecasts |
| Ledger / allocation backed | **No** ledger writes |
| `project_id`, `crop_cycle_id`, optional `production_unit_id` | Same scope keys for **comparison** |

This design keeps **accounting** (truth) and **planning** (intent) in separate bounded contexts, with a thin **comparison** layer that joins them only for analytics.
