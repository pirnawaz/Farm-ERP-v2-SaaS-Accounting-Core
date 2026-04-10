# Phase 3C.1 — Harvest share posting: pre-implementation checkpoint

**Status:** Implementation-ready checkpoint (docs only).  
**Ground truth:** Phase 3A (`phase-3a-1` … `phase-3a-4`), `phase-3-implementation-plan-harvest-share.md`, and **current** `apps/api` code as of this note.  
**Invariants:** One harvest event → **one** `PostingGroup` (`source_type = HARVEST`); immutable posted artifacts; explicit post/reverse only; additive rollout (no-share path unchanged).

---

## 1. Exact code files to modify in Phase 3C

| Area | Files (primary) |
|------|------------------|
| **Posting / reversal** | `app/Services/HarvestService.php` — extend `post()` and ensure `reverse()` remains consistent with **all** new movements and ledger lines attached to the **same** original `posting_group_id`. |
| **Preview ↔ post alignment** | `app/Services/HarvestSharePreviewService.php` — refactor or extract shared **qty/value bucket math** so posting does not drift from preview (private `allocateCost` is **duplicated** today between `HarvestService` and `HarvestSharePreviewService`). Optionally add `app/Services/HarvestShareBucketBuilder.php` (or similar) **only if** it stays behind `LedgerWriteGuard` / allowlist rules. |
| **Models** | `app/Models/HarvestShareLine.php` — populate `computed_qty`, `computed_unit_cost_snapshot`, `computed_value_snapshot` (and optionally `rule_snapshot`) **at post**; `app/Models/Harvest.php` — only if a posted snapshot/version flag is added. |
| **DB** | New migration(s): extend Postgres `allocation_row_allocation_type` (and any other enums) for new allocation types **before** writing rows (see §4). |
| **Settlement / pool profit** | `app/Services/SettlementService.php` — add `'FIELD_JOB'` to `OPERATIONAL_SOURCE_TYPES` (confirmed gap vs `phase-3a-1` §1.8 and implementation plan §3). |
| **Reporting** | `app/Http/Controllers/ReportController.php` — harvest **quantity** aggregation unchanged; **cost-per-unit** logic (`harvest_line_id` via `allocation_rows.rule_snapshot`, joins to `HARVEST` posting groups) must be extended if capitalization splits **per bucket** (multiple `HARVEST_PRODUCTION`-class rows per line) so reports do not double-count or mis-split line cost. |
| **Config** | `config/ledger_write_allowlist.php` — **only** if a new service class performs ledger writes; plan prefers keeping **`HarvestService`** as the writer. |
| **Tests** | `tests/Feature/HarvestTest.php` (regression, no-share path); new feature tests for share post + reverse (e.g. `HarvestSharePostingTest.php`). |

**Not changed in 3C (per plan):** `POST /harvests/{id}/post` contract — same `posting_date` / `idempotency_key`; **no** `PROFIT_DISTRIBUTION_CLEARING` / `PARTY_CONTROL_*` on harvest (those stay `SETTLEMENT` PG only).

---

## 2. Exact posting order inside `HarvestService::post`

Order is fixed so **WIP release** stays **one** Cr to `CROP_WIP`, and **in-kind P&amp;L** never credits `CROP_WIP` twice (`phase-3a-3` §1.1).

1. **Guards & inputs** (unchanged): idempotency (`PostingIdempotencyService`), DRAFT, lines present, crop cycle open, `posting_date` in range, `calculateWipCost`, `allocateCost` per **harvest line** (index order). Load `shareLines` when present.
2. **Create** single `PostingGroup` (`source_type = HARVEST`, `source_id = harvest.id`).
3. **Per harvest line:** resolve **share buckets** (from `harvest_share_lines` or **implicit single owner bucket** = full line qty to **that line’s `store_id`** — matches `HarvestSharePreviewService` when `shareLines` is empty).
4. **Per bucket — capitalization (Layer 1):**  
   - `AllocationRow` with `allocation_type` **either** existing `HARVEST_PRODUCTION` **or** new types per §4 / `phase-3a-3` §1.5; `project_id` = harvest project; `party_id` = **beneficiary** where applicable (`phase-3a-1` §5); `rule_snapshot` includes `harvest_line_id`, `recipient_role`, `share_basis`, `valuation_basis: WIP_LAYER`, remainder flags, optional `settles_*` links.  
   - **Ledger:** Dr `INVENTORY_PRODUCE` for bucket value **V** (if V &gt; 0.001).  
   - **Stock:** `InventoryStockService::applyMovement` — `movement_type = HARVEST`, positive qty/value, `unit_cost_snapshot` = V/qty (6 dp), `source_type`/`source_id` = `harvest` / `harvest.id`, **`posting_group_id`** = this harvest PG.
5. **One** Cr `CROP_WIP` for `round($totalWipCost, 2)` if &gt; 0.001 (unchanged aggregate; sum of Dr `INVENTORY_PRODUCE` must match total WIP released per 3A.2).
6. **Per bucket — in-kind settlement (Layer 2), only where `settlement_mode = IN_KIND` and role warrants P&amp;L:** emit **balanced** income/expense lines per §4 (no second `CROP_WIP` credit). **Cash** buckets (`SETTLEMENT_CASH`): **no** in-kind P&amp;L pair at harvest — capitalization only unless a later phase defines cash-specific posting (out of scope for 3A.3 core).
7. **Harvest header update:** `status`, `posting_date`, `posted_at`, `posting_group_id` (unchanged).

**Idempotency:** If idempotency returns an existing PG, behavior stays as today (early return when harvest already linked).

---

## 3. Exact inventory movement strategy

Aligned with **`HarvestSharePreviewService`** and `phase-3a-2`:

- **Split inbound:** **Multiple** `HARVEST` movements per **harvest line** when multiple buckets — each with **positive** `qty_delta` / `value_delta`, **same** `posting_group_id`, **same** `source_type`/`source_id`, **`store_id`** from **share line** (or harvest line for implicit owner-only).
- **Valuation:** Per line, unit cost from line’s `allocatedCost ÷ lineQty`; per bucket, **value** = round(unit × bucketQty, 2) for non-last buckets; **last bucket on line** absorbs **money** remainder; qty remainder to **remainder** / OWNER bucket (`phase-3a-2` §2–3).
- **Optional same-PG outbound:** `ISSUE`/`TRANSFER` **after** inbounds only if product implements “store then issue” in 3C; if not in 3C, **omit** — reversal order in `HarvestService::reverse` today replays **all** movements on original PG; any ISSUE added later must reverse cleanly (3A.2 §4).

---

## 4. Exact ledger strategy by share type (in-kind)

All **capitalization** uses **Dr `INVENTORY_PRODUCE` / Cr `CROP_WIP`** (total Cr once). **In-kind** rows below are **additional** P&amp;L lines **only for `IN_KIND`** — **same `PostingGroup`**.

| Role | Capitalization | In-kind P&amp;L (when accrual / policy supports netting) |
|------|----------------|--------------------------------------------------------|
| **OWNER (retained)** | Dr `INVENTORY_PRODUCE` bucket V / part of aggregate Cr `CROP_WIP` | **None** (asset-only; `phase-3a-3` §2.1). |
| **MACHINE** | Same | **Internal Field Job path:** Dr `MACHINERY_SERVICE_INCOME`, Cr `EXP_SHARED` for **V** (nets prior Dr/Cr from Field Job; `phase-3a-3` §2.2). **Third-party / no Field Job:** no income/expense pair unless policy adds accrual — **inventory only** or explicit liability path (`phase-3a-3` §2.3, §6). |
| **LABOUR** | Same | **Dr `WAGES_PAYABLE`, Cr `LABOUR_EXPENSE`** for **V** when prior work log accrual exists (`phase-3a-3` §2.4). |
| **LANDLORD** | Same | **Dr liability** (`PAYABLE_LANDLORD` / `DUE_TO_LANDLORD` per tenant) **/ Cr expense reversal** matching **how rent was accrued** (`phase-3a-3` §2.5, §4.3). |
| **CONTRACTOR** | Same | **Liability + expense** discharge per contract / accrual (`phase-3a-3` §2.6). |

**Allocation types:** New enum values per `phase-3a-3` §1.5 (e.g. `HARVEST_IN_KIND_MACHINE`, …) — require **migration** before insert. **`HARVEST_PRODUCTION`** may remain for capitalization rows or be split per bucket per product choice; naming must be consistent in reports.

**Cash buckets:** Posting produces **inventory + WIP** only; no `phase-3a-3` P&amp;L pairing at harvest.

---

## 5. Exact reversal expectations

- **`ReversalService::reversePostingGroup`** negates **all** `allocation_rows` and **all** `ledger_entries` on the original `PostingGroup` (swap debits/credits; quantity-only allocation rows negated).
- **`HarvestService::reverse`** loads **every** `inv_stock_movement` with `posting_group_id = harvest.posting_group_id` and replays `applyMovement` with negated qty/value — **so every movement created at post** (all HARVEST rows, and any future ISSUE/TRANSFER) must use **that** `posting_group_id`.
- **Harvest:** `status = REVERSED`, `reversal_posting_group_id` set — unchanged.
- **No** partial reversal of shares without full harvest reverse (`phase-3a-4` §8).

---

## 6. Exact legacy no-share behavior to preserve

- **`harvest_share_lines` empty** (or equivalent “implicit owner only”): **One** `HARVEST` movement per **harvest line**, **one** `HARVEST_PRODUCTION` allocation per line, **same** `allocateCost` / WIP logic as current `HarvestService::post` (lines 612–714). No in-kind P&amp;L lines.
- **Preview:** `HarvestSharePreviewService` already returns implicit owner-only when `shareLines` is empty — posting must match those quantities/values.
- **Existing tests** (`HarvestTest` and related) **must stay green** for this path.

---

## 7. Exact risks to test first

| Risk | Why | First tests |
|------|-----|-------------|
| **Preview vs post drift** | `allocateCost` duplicated between `HarvestService` and `HarvestSharePreviewService`. | Unit/integration: same inputs → same bucket qty/value as preview API. |
| **Double economic recognition** | In-kind machinery **and** Field Job / MachineryCharge for same window (`phase-3a-1` §13). | Post with `settles_*` links + caps; assert `rule_snapshot` traceability; manual policy: no duplicate full charge. |
| **`FIELD_JOB` missing from settlement profit** | `SettlementService::OPERATIONAL_SOURCE_TYPES` omits `FIELD_JOB` today. | After adding `FIELD_JOB`, regression on `getProjectProfitFromLedger` / breakdown for projects with field jobs. |
| **WIP not project-scoped** | `calculateWipCost` is **crop-cycle** net (`HarvestService` / `HarvestSharePreviewService`). | Documented limitation; tests for multi-project cycles: ensure no silent wrong split beyond current behavior. |
| **Reversal completeness** | Every new ledger + movement must attach to harvest PG. | Post with 2+ buckets → reverse → **sum** ledger + stock net zero for affected accounts/stores. |
| **Reporting double-count** | Multiple allocation rows per `harvest_line_id` after bucket split. | `ReportController` harvest cost report: line-level cost still sums correctly. |
| **Enum / migration order** | New `allocation_type` values. | Migration up/down; insert rows in post. |

---

## Appendix: Current anchors in code

- **`HarvestService::post`:** single `PostingGroup`, per-line `HARVEST_PRODUCTION`, Dr `INVENTORY_PRODUCE`, one Cr `CROP_WIP`, one `HARVEST` movement per line (`app/Services/HarvestService.php`).
- **`HarvestShareLine`:** roles, `settlement_mode` (`IN_KIND` / `CASH`), `share_basis`, `source_*` FKs (`app/Models/HarvestShareLine.php`).
- **`ReversalService`:** negates allocations + ledger; harvest stock negated separately in `HarvestService::reverse`.
- **`SettlementService::OPERATIONAL_SOURCE_TYPES`:** includes `HARVEST`, **not** `FIELD_JOB` (`app/Services/SettlementService.php` lines 28–31).

---

*End of Phase 3C.1 checkpoint.*
