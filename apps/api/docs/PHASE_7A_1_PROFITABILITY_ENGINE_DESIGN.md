# Phase 7A.1 — Profitability Engine (Design)

**Status:** Design only (no implementation).  
**Domain:** `apps/api` (reporting / analytics read model).  
**Builds on:** Phases 1–6 — `posting_groups`, `allocation_rows`, `ledger_entries`, operational documents (`FIELD_JOB`, `HARVEST`, sales, inventory issues, labour, machinery), and harvest-share semantics (`phase-3a-3-harvest-share-accounting.md`, `phase-3c-1-harvest-share-posting-checkpoint.md`).

**Non-goals:** New posting services, ledger mutations, account mapping changes, or UI.

---

## Purpose

Define a **single, unambiguous profitability model** that answers: *for a chosen slice of the business (project, cycle, parcel, machine, …), what revenue and cost did posted activity recognize, and what gross margin and owner-retained output value result?*

The engine is a **read-only analytical layer**: it **aggregates existing posted facts**; it does **not** invent journal logic.

---

## 1. Profitability dimensions

All aggregates are computed in **tenant base currency** using `amount_base` on `allocation_rows` and/or `debit_amount_base` / `credit_amount_base` on `ledger_entries` (consistent with multi-currency columns on `posting_groups`).

### 1.1 Primary: `project_id`

- **Source of truth:** `allocation_rows.project_id` for rows that participate in project-scoped operational postings (field jobs, harvests, many inventory/labour paths, sales lines, etc.).
- **Rule:** A profitability row **belongs to exactly one project** when `allocation_rows.project_id` is non-null for the rows selected for that slice. Rows with **null** `project_id` (allowed in some domains) are **excluded from project profitability** unless a future, explicit bridge rule is approved (out of scope for 7A.1).

### 1.2 `crop_cycle_id`

- **Posting-group level:** `posting_groups.crop_cycle_id` (required for most operational groups; authoritative for period/crop filtering).
- **Consistency check:** For project-scoped rows, `projects.crop_cycle_id` **must** equal `posting_groups.crop_cycle_id` when both are present; implementation queries should **not** silently cross cycles. Mismatches, if they ever appear from legacy data, are **data defects** and excluded or flagged — not reconciled by the profitability engine.

### 1.3 Optional: `production_unit_id`

- **Not** stored on `allocation_rows`. Resolve **only from the operational source** tied to the posting group:
  - `FIELD_JOB` → `field_jobs.production_unit_id`
  - `HARVEST` → `harvests.production_unit_id`
  - `LABOUR_WORK_LOG` → `lab_work_logs.production_unit_id`
  - `CROP_ACTIVITY` → `crop_activities.production_unit_id`
  - `INVENTORY_ISSUE` / related → `inv_issues.production_unit_id`
  - `SALE` (and variants) → `sales.production_unit_id`
- **Null:** If the source field is null, the posting group contributes to **project/cycle** profitability but **not** to a production-unit breakdown (unless the report explicitly rolls up “unknown production unit”).

### 1.4 `machine_id`

- **Source of truth:** `allocation_rows.machine_id` when set.
- **Use:** Machine-level revenue (machinery income) and machine-attributed cost (usage, maintenance, operator, fuel, etc.) slice by this column **joined with** `posting_groups` and source-document reversal rules (§6).

### 1.5 Field (land parcel)

- **Concept:** The physical **land parcel** (`land_parcels.id`) for agronomic and parcel-level reporting.

**Resolution order (deterministic):**

1. **Document override** (when the posting group’s source row carries a parcel):  
   - `FIELD_JOB` → `field_jobs.land_parcel_id`  
   - `HARVEST` → `harvests.land_parcel_id`
2. **Project default:** `projects.field_block_id` → `field_blocks.land_parcel_id`.  
3. **Land allocation path:** If the product uses `projects.land_allocation_id` → resolve to parcel via the land-allocation model **as implemented** (single parcel or primary parcel — follow existing reporting joins; do not invent a second parcel resolution).

If **no parcel** resolves, the row is **parcel-unknown** for field-level reports (still included in project totals).

---

## 2. Revenue definition

**Revenue** = **income-account** economic inflows **allocated** to the dimension slice, **after** §5 rules (no double counting). Use `accounts.type = 'income'` on the **ledger line** side when the metric is ledger-based; when using **allocation amounts** for machinery income, tie rows to the same posting group as the credited `MACHINERY_SERVICE_INCOME` line.

### 2.1 Sales (cash / credit sales — “AR side” of revenue)

**Meaning:** Revenue from selling produce or other billable items, **recognized on the sale posting** (not the AR **balance** as a stock measure).

- **Typical accounts:** Income side of the sale posting (e.g. `PROJECT_REVENUE` or tenant-specific income accounts — follow **posted** `ledger_entries` linked to `SALE`-class `posting_groups`).
- **Allocation discriminator:** `allocation_type = 'SALE_REVENUE'` where present; always reconcile to **ledger** `income` lines in the same `posting_group_id`.
- **Dimension:** `project_id` on allocation rows; `crop_cycle_id` from `posting_groups`; `production_unit_id` from `sales.production_unit_id` when applicable.

### 2.2 Machinery income (`MACHINERY_SERVICE_INCOME`)

**Meaning:** Internal or charge-based **machinery service income** credited in operations (field jobs, machinery charges, machinery service documents).

- **Ledger:** Credits to account code `MACHINERY_SERVICE_INCOME` (system account).
- **Allocation types (operational):**  
  - `MACHINERY_SERVICE` — field job / service postings with machine linkage.  
  - `MACHINERY_CHARGE` — machinery charge documents with pool scope (`allocation_scope` on row: `SHARED`, `HARI_ONLY`, `LANDLORD_ONLY`).

### 2.3 In-kind harvest shares (machine, landlord, labour, contractor)

**Meaning:** **P&amp;L settlement** lines posted **inside** a `HARVEST` posting group that **value** output shares to beneficiaries **without** duplicating the **pure capitalization** layer (Dr `INVENTORY_PRODUCE` / Cr `CROP_WIP`), per Phase 3A.3.

- **Allocation types:**  
  - `HARVEST_IN_KIND_MACHINE`  
  - `HARVEST_IN_KIND_LABOUR`  
  - `HARVEST_IN_KIND_LANDLORD`  
  - `HARVEST_IN_KIND_CONTRACTOR`

**Important:** These rows exist to **settle or reverse accruals** (machinery/labour/landlord) or to follow an approved liability pattern. They are **revenue-like or contra-expense-like** only in the **net** sense defined in §5 — they must **never** be summed naïvely with the **same** economic value already counted in `FIELD_JOB` or `LABOUR_WORK_LOG` accruals.

---

## 3. Cost definition

**Cost** = **expense-account** activity **allocated** to the slice, using `accounts.type = 'expense'` for ledger-based totals, subject to §5.

### 3.1 Inputs (inventory consumption)

- **Posting sources:** `INVENTORY_ISSUE`, relevant `CROP_ACTIVITY` postings that capitalize/issue inputs, `FIELD_JOB` input lines, etc.
- **Typical expense account:** `INPUTS_EXPENSE` (and stock variance accounts when applicable).
- **Allocation types (examples):** `POOL_SHARE` on input/issue postings; issue-specific rows as implemented; **exclude** pure **balance-sheet** inventory movements that do not hit expense.

### 3.2 Labour expense

- **Primary path:** `LABOUR_WORK_LOG` posting groups → Dr `LABOUR_EXPENSE` / Cr `WAGES_PAYABLE` (see existing posting services).
- **Allocation:** Typically `POOL_SHARE` with labour amounts; harvest in-kind labour settlement uses `HARVEST_IN_KIND_LABOUR` in conjunction with §5.

### 3.3 Machinery cost

- **Shared project machinery expense:** `EXP_SHARED` (and related) paired with machinery recovery in field jobs — **gross** expense side before harvest netting.
- **Machinery-specific expense types:** Allocations `MACHINERY_FUEL`, `MACHINERY_OPERATOR`, `MACHINERY_MAINTENANCE`, `MACHINERY_OTHER`, `MACHINERY_USAGE` (quantity/economics as defined per posting), `MACHINE_WORK_LOG` postings.
- **Charges:** `MACHINERY_CHARGE` rows debit **expense or receivable** accounts per pool scope; only the **expense** side enters **cost** for profitability unless a **capitalization** rule explicitly moves amounts to balance sheet (follow posted accounts).

### 3.4 Landlord cost

- **Expense accounts:** `EXP_LANDLORD_ONLY`, rent expense where used, lease accruals (`LAND_LEASE_ACCRUAL` posting groups with `LEASE_RENT` allocation type).
- **Harvest in-kind landlord:** `HARVEST_IN_KIND_LANDLORD` — subject to §5 pairing with prior accruals.

---

## 4. Special handling

### 4.1 In-kind shares must NOT double count

**Problem:** The same economic machine service can appear as:

1. **Accrual:** Field job posts **Dr `EXP_SHARED` / Cr `MACHINERY_SERVICE_INCOME`**.  
2. **Settlement:** Harvest posts **Dr `MACHINERY_SERVICE_INCOME` / Cr `EXP_SHARED`** for the in-kind slice (netting the accrual per Phase 3A.3).

**Rule (net economic P&amp;L):** For **project profitability**, the engine must count **at most one** of:

- The **net ledger effect** after both postings, **or**
- A **single-layer** rule: **include field-job machinery service income and expense**, and **exclude** the **settlement-reversal** portion of `HARVEST_IN_KIND_*` that **only** reverses those accruals (identify via `rule_snapshot` / `settles_*` links when present), **or** the inverse convention — **but never both layers** for the same **settled** amount.

**Concrete guidance (aligned with existing machinery reporting):**

- **Machine revenue reporting** already distinguishes **cash/charge** machinery income (`MACHINERY_SERVICE`, `MACHINERY_CHARGE`) from **`HARVEST_IN_KIND_MACHINE`** on `HARVEST` groups. Profitability **must** use the **same** discrimination when attributing “machinery income” so harvest in-kind does not **add** a second full revenue for an already-accrued internal service.
- **Labour:** Parallel logic — `HARVEST_IN_KIND_LABOUR` nets against **`WAGES_PAYABLE` / `LABOUR_EXPENSE`** accruals from work logs when settling the same obligation.
- **Landlord:** `HARVEST_IN_KIND_LANDLORD` nets against **`PAYABLE_LANDLORD` / `DUE_TO_LANDLORD` / rent expense** only when those accruals exist; otherwise follow posted accounts only.

### 4.2 Owner retained output vs shared output

- **Capitalization (not operating profit):** **Dr `INVENTORY_PRODUCE` / Cr `CROP_WIP`** is **balance-sheet reclassification + quantity**; it does **not** increase `income` in the general ledger (see Phase 3A.3 audit).
- **Owner retained quantity/value** for analytics: Taken from **HARVEST** postings via:
  - `allocation_type = 'HARVEST_PRODUCTION'` rows, with **`rule_snapshot.implicit_owner = true`** or explicit **OWNER** recipient in share-line snapshots, and/or  
  - `harvest_share_lines` with `recipient_role = 'OWNER'` and posted `computed_value_snapshot`.

**Distinction:**

| Concept | Where it shows | Profitability role |
|--------|----------------|---------------------|
| **Shared / beneficiary output** | In-kind P&amp;L lines + inventory to beneficiary stores | §2.3 and §5 |
| **Owner retained output** | `HARVEST_PRODUCTION` + owner buckets | §7.4 **retained output value** (not “revenue” unless later sold) |

### 4.3 `FIELD_JOB` + `HARVEST` interaction

| Stage | What happens | Profitability interpretation |
|-------|----------------|-------------------------------|
| Field job posted | Accrues shared costs; internal machinery **income/expense pair** | Includes **operational** cost + machinery recovery in period of field job |
| Harvest posted (capitalization) | Moves WIP to inventory by line/bucket | **No income**; increases **inventory asset** |
| Harvest posted (in-kind settlement) | May **reverse** slices of prior accruals | **Adjusts** net expense/income to avoid duplicate burden (§4.1) |

**Ordering:** Use **`posting_groups.posting_date`** (and document idempotency if needed) for period reports; **do not** re-sort events in a non-deterministic way.

---

## 5. Allocation mapping — cost vs revenue vs excluded

Legend: **R** = revenue-side attribution (income accounts / machinery income allocation), **C** = cost-side (expense), **—** = not used for P&amp;L profitability by default (balance sheet, clearing, memo, or requires special rule).

| `allocation_type` | Typical role | Notes |
|-------------------|----------------|-------|
| `SALE_REVENUE` | **R** | Tie to sale income lines |
| `SALE_COGS` | **C** | COGS; may be excluded from “gross margin” variants that use a different definition |
| `POOL_REVENUE` | **R** | Shared income pools |
| `POOL_SHARE` | **R** or **C** | **Context-dependent** — must use **account** (`ledger_entries.account_id` → `accounts.type`) on the same posting group |
| `HARI_ONLY` | **R** or **C** | Scope to Hari pool; same account-type rule |
| `KAMDARI` | **R** or **C** | Kamdar pool |
| `LANDLORD_ONLY` | **C** | Landlord-only expense attribution |
| `HARVEST_PRODUCTION` | **—** (P&amp;L) | **Inventory / WIP capitalization**; use for **retained output value**, not revenue |
| `HARVEST_IN_KIND_MACHINE` | **R** / **adj** | Machinery in-kind; **net** per §4.1 |
| `HARVEST_IN_KIND_LABOUR` | **C** / **adj** | Labour in-kind settlement |
| `HARVEST_IN_KIND_LANDLORD` | **C** / **adj** | Landlord in-kind settlement |
| `HARVEST_IN_KIND_CONTRACTOR` | **C** / **adj** | Contractor in-kind settlement |
| `MACHINERY_SERVICE` | **R** | Machinery income / recovery (amount on row; machine_id) |
| `MACHINERY_CHARGE` | **R** | Income side of charge posting (see expense on paired lines for **net**) |
| `MACHINERY_USAGE` | **C** | Usage economics (often paired with service) |
| `MACHINERY_FUEL` | **C** | |
| `MACHINERY_OPERATOR` | **C** | |
| `MACHINERY_MAINTENANCE` | **C** | |
| `MACHINERY_OTHER` | **C** | |
| `LEASE_RENT` | **C** | Land lease accrual |
| `ADVANCE` / `ADVANCE_OFFSET` | **—** | Balance sheet / clearing |
| `PAYMENT` | **—** | Treasury |
| `SETTLEMENT_PAYABLE` | **—** | Pool settlement mechanics — **exclude** from operational profitability unless product defines “after settlement” view |
| `SUPPLIER_AP` | **C** (if expense) | Depends on invoice line account |
| `LOAN_DRAWDOWN` / `LOAN_REPAYMENT` | **—** | Financing |
| `FIXED_ASSET_*` | **—** / **C** | Depreciation **C**; activation/disposal per account type |
| `PARTY_CONTROL_CONSOLIDATION` | **—** | Consolidation memo |
| `PERIOD_CLOSE` | **—** | Period close |
| `FX_*` (if allocated) | **R** / **C** | FX gain/loss — **exclude** from **operating** gross margin unless explicitly included |

**Default when ambiguous:** For `POOL_SHARE` and similar, **never** infer R vs C from the enum alone — **always** join `ledger_entries` and classify by **`accounts.type`**.

---

## 6. Core invariants

1. **Posted data only:** Include only posting groups whose **source document** is in a **POSTED** (or equivalent) status and **not** logically reversed:
   - Exclude **`reversal_of_posting_group_id`** chains per existing reversal semantics.
   - For documents with `reversal_posting_group_id` on the source row, follow established report patterns (e.g. machinery reports join to `machinery_services`, `machinery_charges`, `harvests` with `reversal_posting_group_id IS NULL` on the **original** post).

2. **`posting_groups` as anchor:** Every line ties to **one** `posting_group_id`. Aggregations **group by** `posting_group_id` first when mixing `ledger_entries` and `allocation_rows` to preserve **balance** and traceability.

3. **Deterministic:**
   - Fixed **currency**: `amount_base` / `*_amount_base`.
   - Stable **ordering** when splitting remainder buckets (e.g. order by `allocation_rows.created_at`, then `id`).
   - **Explicit** include/exclude lists for `posting_group_source_type` per report (operational vs `CROP_CYCLE_SETTLEMENT` vs `PERIOD_CLOSE`).

4. **Read-only:** SELECT-only queries; no inserts/updates to ledger or allocations.

5. **No new accounting logic:** The engine **observes** account balances and allocation semantics already implemented by posting services; it does **not** post **new** netting journals.

---

## 7. Output metrics

All metrics are **optional filters** on the same core fact set (§1 dimensions).

| Metric | Definition |
|--------|------------|
| **Total revenue** | Sum of **income-account** effects (`credit − debit` on income accounts) for included `posting_group_id`s, scoped to the slice, **after** §4.1 de-duplication rules for in-kind machinery/labour/landlord. |
| **Total cost** | Sum of **expense-account** effects (`debit − credit` on expense accounts) for the same slice. Optionally **exclude COGS** (`SALE_COGS` / `COGS_PRODUCE`) for a “throughput” variant — must be **labeled**. |
| **Gross margin** | `total revenue − total cost` (using the **same** inclusion rules for both legs). |
| **Retained output value** | **Not** general-ledger revenue: **sum of valued owner-retained buckets** at harvest post — from `HARVEST_PRODUCTION` allocation amounts and/or `harvest_share_lines` **OWNER** `computed_value_snapshot` for **POSTED** harvests, in base currency. This measures **inventory value retained by the owner pool**, distinct from **sales revenue** when sold later. |

**Reporting clarity:** Always state whether **retained output value** is **included in** or **separate from** “revenue” to avoid implying IFRS/GAAP top-line revenue from unsold inventory.

---

## 8. Relationship to existing helpers

- **`SettlementService::getProjectProfitFromLedger*`** already restricts to **income/expense** accounts and **project allocation** joins. Phase 7A.1 **generalizes** dimensions (machine, parcel, production unit) and **adds** the **in-kind netting** and **retained output** rules explicit here.
- **`MachineryReportsController`** machine revenue query illustrates **excluding double counts** between machinery postings and `HARVEST_IN_KIND_MACHINE`; profitability **must** reuse that **discrimination pattern** for machine-level revenue.

---

## 9. Acceptance checklist

- [ ] Every metric names **exactly** which **accounts**, **allocation_types**, and **source_types** are in or out.
- [ ] **FIELD_JOB + HARVEST** interaction cannot inflate machinery or labour **revenue or cost** for the same settled obligation.
- [ ] **Owner retained** value is **not** mislabeled as IFRS revenue.
- [ ] Queries are **read-only** and **deterministic** for the same database state.

---

*End of Phase 7A.1 design.*
