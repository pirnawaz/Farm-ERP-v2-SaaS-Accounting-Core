# Phase 3A.1 — Harvest Share / In-Kind Settlement (codebase-specific design)

**Status:** Design note only — no production code in this step.  
**Domain:** `apps/api`  
**Scope:** Agriculture-native output sharing when machinery, labour, landlord, or contractor are settled in **produce** rather than (or in addition to) cash.

**Related:** Inventory movement and valuation — `phase-3a-2-harvest-share-inventory-valuation.md`. GL / allocation semantics — `phase-3a-3-harvest-share-accounting.md`. Operator workflow / UX — `phase-3a-4-harvest-share-operator-workflow.md`. **Execution plan (3B–3E):** `phase-3-implementation-plan-harvest-share.md`.

---

## 1. Exact current behavior (grounded in code)

### 1.1 Harvest document and API

| Area | Behavior |
|------|----------|
| **Routes** | `crop-ops/harvests` CRUD, `POST …/post`, `POST …/reverse` (`routes/api.php`, crop_ops module). |
| **Controller** | `HarvestController`: validates `project_id` on create; lines are `inventory_item_id`, `store_id`, `quantity`, optional `uom`/`notes`. Post requires `posting_date`; reverse requires `reversal_date` + optional `reason`. |
| **Models** | `Harvest` (`harvests`): `crop_cycle_id`, `project_id`, `production_unit_id`, `land_parcel_id`, status `DRAFT`/`POSTED`/`REVERSED`, `posting_group_id`, `reversal_posting_group_id`. `HarvestLine` (`harvest_lines`): physical receipt lines only — **no share fields, no beneficiary party, no split type**. |
| **Draft rules** | Only `DRAFT` harvests can be updated; lines added/updated/deleted only in `DRAFT`. |

### 1.2 Harvest posting (`HarvestService::post`)

1. **Idempotency:** `PostingIdempotencyService::resolveOrCreate` with type `HARVEST` + harvest id; effective key stored on `posting_groups.idempotency_key`.
2. **Guards:** `OperationalPostingGuard::ensureCropCycleOpenForProject`; posting date within crop cycle bounds.
3. **WIP transfer amount:** `calculateWipCost($tenantId, $crop_cycle_id, $postingDate)` — **net** balance on `CROP_WIP` from `ledger_entries` joined to `posting_groups` for that crop cycle with `posting_date <= posting date`, clamped ≥ 0. This is **not** per-project; it is **crop-cycle-wide** WIP.
4. **Cost allocation:** `allocateCost($totalWipCost, $lines)` — proportional by **line quantity**, last line absorbs rounding; if total qty 0, equal by line count.
5. **Posting group:** single `PostingGroup` with `source_type = 'HARVEST'`, `source_id = harvest.id`, `crop_cycle_id` from harvest.
6. **Per line:**  
   - `AllocationRow` with `allocation_type = 'HARVEST_PRODUCTION'`, `project_id` = harvest’s project, `party_id` = **project’s party**, `amount` = allocated cost, `rule_snapshot` includes `harvest_line_id`, quantities, `total_wip_transferred`.  
   - **Ledger:** Dr `INVENTORY_PRODUCE` (per line, if cost > 0.001), summed into `$totalDebitedToInventory`.  
   - **Stock:** `InventoryStockService::applyMovement` with `movement_type = 'HARVEST'`, positive `qty_delta` and `value_delta`, `unit_cost_snapshot` derived from allocated cost ÷ qty, `source_type = 'harvest'`, `source_id = harvest.id`.
7. **WIP credit:** One **aggregate** Cr to `CROP_WIP` for `round($totalWipCost, 2)` if > 0.001 — **not** matched line-by-line to allocation rows.

**Implications:** The entire posted quantity for each line lands in the **nominated store** as produce inventory with absorbed WIP value. There is **no** split of bags between “owner” vs “machine” vs “landlord” at receipt. Economic sharing is **not modeled** on the harvest document.

### 1.3 Harvest reversal (`HarvestService::reverse`)

- Uses `ReversalService::reversePostingGroup` on the original `PostingGroup` → creates `REVERSAL` posting group with negated `LedgerEntry` rows and negated `AllocationRow` amounts (and quantity rules per `ReversalService` for quantity-only rows).
- Re-applies stock movements with negated qty/value against the **same** movement type and source references via `InventoryStockService::applyMovement`.
- Updates harvest to `REVERSED` and sets `reversal_posting_group_id`.

### 1.4 Inventory and valuation

- **`InventoryStockService::applyMovement`** is generic: updates `inv_stock_balances` (qty, value, WAC) for any `movement_type` string. **HARVEST** increases qty and value.
- **Reversals** negate prior deltas; WAC is recomputed from balance state.
- No special case for “party-owned” sub-lots inside one store — **one balance row per (tenant, store, item)**.

### 1.5 Field job operational cost (Phase 2 reference)

- `FieldJobPostingService`: single `PostingGroup` `FIELD_JOB`; issues inputs (ISSUE), labour payables, machinery **financial** pair (e.g. Dr `EXP_SHARED`, Cr `MACHINERY_SERVICE_INCOME`) when rate card/manual amount applies; `MACHINERY_USAGE` + `MACHINERY_SERVICE` allocation rows tied to **project’s `party_id`**.
- **Does not** move produce inventory or harvest quantity.

### 1.6 Machinery charge (cash / accrual settlement of service)

- `MachineryChargePostingService`: `source_type = 'MACHINERY_CHARGE'`; Dr expense or receivable by pool scope, Cr `MACHINERY_SERVICE_INCOME`; allocation `MACHINERY_CHARGE`. **Monetary** settlement document, not produce.

### 1.7 Labour work log

- `LabourPostingService`: `LABOUR_WORK_LOG`; Dr `LABOUR_EXPENSE`, Cr `WAGES_PAYABLE`; allocation to project/party. **Cash/accrual labour cost**, not produce.

### 1.8 Settlement engine (cash profit distribution)

- `SettlementService::OPERATIONAL_SOURCE_TYPES` lists posting group `source_type` values used when attributing **ledger** P&amp;L to a project for **preview/post settlement** (includes `HARVEST`, `MACHINERY_CHARGE`, `LABOUR_WORK_LOG`, `SALE`, etc.).
- **Notable gap:** **`FIELD_JOB` is not in this list** — field job operational costs may be **excluded** from `getProjectProfitFromLedger` / settlement preview until that list is updated (separate backlog; called out in risks).
- Settlement uses **ShareRule** / **ShareRuleLine** (party + **percentage** + `role`) linked to **Settlement** records; basis is **ledger-derived pool profit**, not harvest bags.
- **No** first-class “in-kind settlement” object in current code — settlement postings use clearing / party control accounts (`SettlementService` comments reference `PROFIT_DISTRIBUTION_CLEARING`, `PARTY_CONTROL_*`).

### 1.9 Reporting (harvest-related)

- `ReportController` includes harvest quantity aggregation and cost-per-unit logic joining **posted** `harvest_lines` to `allocation_rows` / `HARVEST` posting groups (`rule_snapshot->harvest_line_id` preferred).
- Project profitability for settlement is **ledger + allocation**, not a separate harvest-share report.

---

## 2. Gaps vs required business scenarios

| Scenario | Current support |
|----------|------------------|
| **1. Machine share in produce (9 owner + 1 machine)** | **None.** Harvest posts **all** qty to configured store(s) as one pool; no machine party, no 1-bag split. Machinery recovery is **separate** (FieldJob / MachineryCharge), monetary. |
| **2. Labour share in produce** | **None** at harvest. Labour is `WAGES_PAYABLE` accrual. |
| **3. Landowner / landlord share in produce** | **None** at receipt. Landlord appears in settlement **cash** shares and scope-specific expenses, not in `HarvestLine`. |
| **4. Contractor share in produce** | **None** — same as above. |
| **5. Pure owner harvest, no shares** | **Supported** — matches current behavior (single economic owner via project party; all qty to store). |
| **6. Mixed cash + in-kind** | **Partial.** Cash side: sales, charges, settlement. **In-kind produce** to third parties: **not** modeled on harvest. |
| **7. Internal machine vs third-party** | Field job machinery income is **internal**; external would be MachineryCharge / party — **not** tied to harvest bags. |

**Structural gap:** Harvest is **physical receipt + WIP capitalization** only. Share-of-output is a **distribution** problem on top of inventory and party equity, which the current harvest post does not represent.

---

## 3. Recommended architecture (single coherent approach)

**Principle:** Treat **harvest posting** as the **one source event** that brings produce into inventory at absorbed cost, and treat **output shares** as **explicit distribution rules** applied **at post time** (immutable snapshot), producing:

1. **Split stock movements** (or split lines) so quantity and **carrying value** align with each beneficiary **party** (and optional internal “machinery income” store/lot).
2. **Allocation rows** that keep **project** as the accounting anchor but encode **share type** and **beneficiary** in `rule_snapshot` / dedicated allocation types.
3. **Ledger entries** that recognize **in-kind settlement** at a defined valuation basis (see §5) without double-counting operational cash accruals already posted elsewhere.

**Share definition placement (design question 1):**

- **Recommended:** **Versioned share template** at **project or crop-cycle agreement** level (reusing/aligning with `ShareRule` concepts where possible), **copied into an immutable snapshot** on the harvest (or harvest posting run) so posted documents stay auditable if templates change later.
- **Not** “harvest only” long-term — harvest lines should **reference** the resolved snapshot, not live template rows.

**Source of truth for posted shares (design question 2):**

- **Posted `PostingGroup` (HARVEST)** + its **allocation rows** + **stock movements** + **ledger lines** — same invariant as today: immutable after post.

---

## 4. Exact source-of-truth model

| Layer | Truth |
|-------|--------|
| **Operational document** | `harvests` + child lines (extended for share lines or parallel `harvest_share_lines` with snapshot ids). |
| **Accounting** | `posting_groups` (`source_type = HARVEST` for the primary event; avoid a second posting group for the **same** harvest post — extend one group with additional movements/rows unless a hard invariant forces split — see §6). |
| **Inventory** | `inv_stock_movements` net per store/item/party-lot strategy. |
| **Party / project** | `allocation_rows.project_id` remains primary; **beneficiary `party_id`** may differ from project party for contractor/landlord/machine recipient lines — requires schema clarity (see §5). |

---

## 5. Proposed additive schema (illustrative — not implemented here)

**A. Harvest share snapshot (draft → frozen at post)**

- `harvest_share_snapshots` or columns on `harvests`: `share_snapshot_json` / FK to `share_rule_id` + `snapshot_version`, `posted_share_hash`.

**B. Per-share or per-line split**

- Option **preferred for traceability:** **`harvest_distribution_lines`** (tenant, harvest_id, line_index, beneficiary_party_id, share_role enum, quantity, optional store_id override, link to source `harvest_line_id`, valuation_basis enum).
- Alternatively: **multiple `harvest_lines`** per physical line with **split qty** — weaker for “10 bags one document” UX.

**C. Link to operational accruals (avoid duplicate recognition)**

- Nullable FKs: `settles_field_job_machine_line_id`, `settles_labour_work_log_id`, `settles_machinery_charge_id` — **only** when this harvest run is explicitly **quitting** an accrual in favour of in-kind (policy-controlled).

**D. `allocation_rows` extensions**

- New `allocation_type` values e.g. `HARVEST_SHARE_IN_KIND`, `HARVEST_OWNER_RETAINED`.
- `rule_snapshot`: `{ harvest_id, harvest_line_id, share_role, beneficiary_party_id, valuation_method, counterparty_accrual_pg_id? }`.

---

## 6. Posting group / allocation / ledger design

**Invariant:** **One harvest post → one `PostingGroup`** (`HARVEST`) unless product explicitly splits “receipt” vs “distribution” — **recommended to keep one group** for simplicity and reversal symmetry.

**Suggested posting pattern inside that group**

1. **Capitalize produce** (current behavior): Dr `INVENTORY_PRODUCE`, Cr `CROP_WIP` for total WIP released (possibly split by line first).
2. **Distribute in-kind:**  
   - **Inventory:** Either **ISSUE** from owner store to `EXTERNAL` or **TRANSFER** to a **party-specific store** (if multi-store model) **or** split into **separate virtual lots** (if later added).  
   - **Ledger:** At minimum, **recognize settlement value** at chosen basis:  
     - **WAC of this receipt** (simplest; ties to bags moved), or  
     - **Agreed price** from snapshot (requires price field on snapshot line).  
   - **Pairing:** Cr `INVENTORY_PRODUCE` (or COGS if selling from stock), Dr **beneficiary clearing** — e.g. reduce `WAGES_PAYABLE`, `DUE_TO_CONTRACTOR`, or **credit `MACHINERY_SERVICE_INCOME`** with **matching link** to original FieldJob/MachineryCharge accrual **only if** business rule says produce replaces cash (otherwise **double-count** risk — see §11).

**Machine share (design question 5):**

- **Recommended:** **Both** economically:  
  - **Owner output** reduced (fewer bags / lower value in owner pool).  
  - **Machinery** recognizes **income or receivable reduction** via **linked** ledger lines, not a second full machinery charge.  
- Express via **two allocation rows** (owner retained vs machine beneficiary) + **narrative link** in `rule_snapshot` to the internal machine party or cost center.

**Project-based accounting (design question 6):**

- Keep **`project_id`** on all allocation rows for pool reporting.  
- Where beneficiary ≠ project party, store **both** `project_id` (context) and `party_id` (beneficiary) — already consistent with multi-party reporting needs; may require reporting joins to be updated.

---

## 7. Inventory movement design

- **Receipt:** Retain `HARVEST` movement for total inbound (or per split line if implementation uses line-per-beneficiary).  
- **Outward in-kind:** Use **`ISSUE`** or **`TRANSFER`** with **negative** qty from owner balance or direct split at receipt (preferred: **split at receipt** to avoid double-handling — three lines: 9 qty store A owner, 1 qty store B machine — if store B models “machine’s bin”).  
- **Valuation:** Unit cost from **same WIP allocation** as the line’s share of `$totalWipCost` (consistent with current `allocateCost`).

---

## 8. Reversal design

- **Single `ReversalService::reversePostingGroup`** on the harvest `PostingGroup` remains the **primary** reversal path.  
- **Requirement:** All in-kind movements and ledger lines for that post must be **fully reversed** — if multiple stores/items involved, reversal must replay **negated** movements for **each** `inv_stock_movement` row created.  
- **Accrual links:** If a harvest post **netted** against `WAGES_PAYABLE`, reversal must **restore** payable balance (mirror `FieldJobPostingService` / `LabourPostingService` reversal patterns).

---

## 9. Reporting impact

| Area | Change |
|------|--------|
| **Project P&amp;L / settlement** | Include `FIELD_JOB` in operational source types; add harvest share allocation types to breakdown queries. |
| **Harvest cost / qty reports** | Extend joins to distribution lines; cost-per-party if split. |
| **Machine “profitability”** | Risk of **double counting** if machinery charge **and** in-kind credit post — reports must filter by **settlement mode** or link to canonical accrual. |
| **Inventory** | Stock-by-store reports already movement-based; may need **party** dimension if introduced. |

---

## 10. Compatibility and rollout plan

1. **Phase A (schema + draft UI):** Add optional share snapshot on harvest **without** changing posted behavior — default = 100% owner retained (current).  
2. **Phase B (posting):** When snapshot has shares, generate extra movements/allocations/ledger inside **same** `HARVEST` posting group.  
3. **Phase C (accrual integration):** Optional toggles to **net** FieldJob/MachineryCharge/Labour accruals — **only** with explicit user confirmation and idempotent links.  
4. **No breaking change:** Existing harvests without snapshots post as today.

---

## 11. Exact file plan (future implementation)

| Layer | Files / areas |
|-------|----------------|
| **Migrations** | New tables/columns for share snapshots and distribution lines; optional `party_id` extensions on allocation rows if not nullable today for secondary parties. |
| **Models** | `Harvest`, `HarvestLine`, new `HarvestShareLine` or similar; `AllocationRow` validation for new types. |
| **Service** | `HarvestService::post` / `reverse` — major extension; possibly extract `HarvestShareCalculator` / `HarvestInventoryDistributor`. |
| **Guards** | `OperationalPostingGuard` unchanged contract; crop cycle lock preserved. |
| **Settlement** | `SettlementService::OPERATIONAL_SOURCE_TYPES` — add `FIELD_JOB`; review whether harvest in-kind postings use income/expense accounts that feed **same** pool profit logic. |
| **Ledger guard** | `config/ledger_write_allowlist.php` — only if new service classes introduced. |
| **Tests** | `tests/Feature/HarvestTest.php`, new feature tests for share splits, reversal, double-count prevention. |
| **API** | `HarvestController` + Form Requests for snapshot payload. |
| **Reports** | `ReportController` harvest sections; any project profitability queries. |

---

## 12. Design Q&amp;A (concise answers)

| # | Question | Answer |
|---|----------|--------|
| 1 | Where should share definition live? | **Template** (project/cycle/share rule) + **immutable snapshot** on harvest at post. |
| 2 | Source of truth for posted shares? | **PostingGroup + allocation_rows + movements** for that harvest id. |
| 3 | Produce-share inventory? | **Qty and value** per beneficiary path (store split or issue), driven from snapshot. |
| 4 | In-kind accounting value? | **WAC at harvest** (default) or **contract price** from snapshot; must be explicit. |
| 5 | Machine share: income vs owner reduction? | **Both**, via linked allocations; avoid duplicate **MachineryCharge** if in-kind **replaces** cash. |
| 6 | Project anchor? | **`project_id` on rows**; beneficiary `party_id` for non-owner shares. |
| 7 | Reversal? | **Single reversal posting group** reversing **all** lines/movements from harvest post. |
| 8 | Reporting changes? | **Settlement source list**, harvest reports, machine profitability **dedupe** rules. |
| 9 | Duplicate recognition? | **Rules + optional accrual settlement links**; block second full charge for same service window. |
| 10 | User workflow? | **Draft harvest → define/confirm share snapshot → post once**; accrual adjustments optional and explicit. |

---

## 13. Explicit risk list

| Risk | Description | Mitigation |
|------|-------------|------------|
| **Double posting** | User posts **MachineryCharge** (cash) **and** harvest gives machine bags for **same** service period. | Policy: mutually exclusive or **netting** link; UI warnings; idempotent accrual keys. |
| **Double inventory recognition** | **HARVEST** plus manual **ISSUE**/sale for same physical bags. | Traceability: `source_type`/`source_id`; optional serial/lot later. |
| **Valuation ambiguity** | WAC vs agreed in-kind price vs market. | Snapshot field `valuation_basis`; document in `rule_snapshot`. |
| **Project vs party confusion** | `allocation_rows.party_id` today often equals **project’s party**; beneficiary may differ. | Explicit `beneficiary_party_id` in snapshot or allocation snapshot. |
| **Reporting gaps** | `FIELD_JOB` omitted from `SettlementService::OPERATIONAL_SOURCE_TYPES`. | Add + regression tests — **existing gap** for Phase 2 field costs. |
| **WIP pool not project-scoped** | `calculateWipCost` is **crop-cycle** net, not project — may mis-attribute cost if multiple projects share a cycle. | Document; future: project-tagged WIP or allocation of WIP before harvest (larger change). |
| **Reversal incomplete** | Partial reversal if new movements added and reversal path misses one. | Integration test: every movement type created in post is negated. |

---

## 14. Non-goals (this note)

- No deprecation of harvest, field job, machinery charge, or settlement flows.  
- No mandate on UI/operator mode.  
- No implementation in this step.

---

*End of design note.*
