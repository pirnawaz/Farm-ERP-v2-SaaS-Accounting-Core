# Phase 2A — Field Job machinery costing automation (design note)

**Scope:** `apps/api` audit only — no production code in this step.  
**Goal:** Enable **automatic financial machinery cost** when posting a Field Job (normal internal field operations), without requiring a separate manual **MachineryCharge** for those flows.

**Architectural invariants (must hold):**

- One source operational event → **one** `PostingGroup` (Field Job remains a single `FIELD_JOB` posting).
- **No double posting** of the same economic cost.
- **Immutable accounting artifacts** after post (snapshots on lines; reversals via offsetting groups).
- Existing **manual MachineryCharge** and **MachineWorkLog** flows remain valid and unchanged in behaviour.
- **Project** remains the accounting anchor for allocations and GL.

---

## 1. Current machinery usage vs machinery financial charge behaviour

### 1.1 Machine work log — `MachineryPostingService` (`apps/api/app/Services/Machinery/MachineryPostingService.php`)

- **Source:** `PostingGroup.source_type = 'MACHINE_WORK_LOG'`, `source_id = machine_work_log.id`.
- **Ledger:** **None.** Comment in code: usage-only posting.
- **Allocation:** Exactly **one** `allocation_rows` row per post:
  - `allocation_type = 'MACHINERY_USAGE'`
  - `amount = null`
  - `quantity` = work log usage; `unit` = machine `meter_unit`
  - `allocation_scope` from work log `pool_scope` (SHARED vs HARI_ONLY)
- **Purpose:** Capture **operational usage** for allocation / profitability **quantity** tracking, not money.

**Reversal:** Custom logic in `reverseWorkLog()` (not `ReversalService`): creates `PostingGroup` with `source_type = 'REVERSAL'`, copies allocation with **negated quantity** (note: reversal row in this path does not set `machine_id` on the snippet in the file — worth verifying when touching machinery).

### 1.2 Machinery charge (landlord / billing) — `MachineryChargePostingService` (`apps/api/app/Services/Machinery/MachineryChargePostingService.php`)

- **Source:** `PostingGroup.source_type = 'MACHINERY_CHARGE'`, `source_id = machinery_charge.id`.
- **Ledger:** **Balanced pair:** Dr `DUE_FROM_HARI` *or* expense account (`EXP_LANDLORD_ONLY` / `EXP_SHARED` by `pool_scope`) / Cr `MACHINERY_SERVICE_INCOME` — see `postCharge()` lines ~80–160.
- **Allocation:** **One** row:
  - `allocation_type = 'MACHINERY_CHARGE'`
  - `amount` = charge `total_amount` (money)
  - `machine_id` from first line’s work log (for reporting attribution)
- **Purpose:** **Inter-party machinery billing** (revenue recognition + debtor or expense by pool).

**Draft creation:** `MachineryChargeService::generateDraftChargeForProject()` resolves **rate cards** per posted work log, builds `machinery_charge_lines`, links `machine_work_logs.machinery_charge_id` to prevent double charging.

**Reversal:** `reverseCharge()` delegates to **`ReversalService::reversePostingGroup()`**, then updates charge status. Comment: work logs **stay** linked to the charge (reservation intact).

### 1.3 Internal machinery service — `MachineryServicePostingService` (`apps/api/app/Services/Machinery/MachineryServicePostingService.php`)

- **Source:** `MACHINERY_SERVICE`.
- **Ledger:** Dr expense account by **allocation scope** (`EXP_SHARED` / `EXP_HARI_ONLY` / `EXP_LANDLORD_ONLY`) / Cr `MACHINERY_SERVICE_INCOME` — internal profit-centre pattern.
- **Allocation:** `allocation_type = 'MACHINERY_SERVICE'`, `amount` from `rate_card.base_rate × quantity`, `machine_id` set.

### 1.4 Field Job — `FieldJobPostingService` (`apps/api/app/Services/FieldJobPostingService.php`)

- **Source:** `PostingGroup.source_type = 'FIELD_JOB'`, `source_id = field_job.id`.
- **Inputs:** Stock issue + GL (inputs expense / inventory) + `POOL_SHARE` allocation with **amount**.
- **Labour:** Worker balance + GL (labour expense / wages payable) + `POOL_SHARE` allocation with **amount**.
- **Machines (today):** For each `field_job_machines` line with positive `usage_qty`:
  - Persists `meter_unit_snapshot` from machine or line.
  - Creates **`allocation_rows` only**: `allocation_type = 'MACHINERY_USAGE'`, `amount = null`, `quantity` + `unit` + `machine_id`, `rule_snapshot` includes `field_job_id` / `field_job_machine_id`.
  - **No ledger entries** for machinery (explicit Phase 1 choice: allocation-only usage).

**Reversal:** `reverseFieldJob()` uses **`ReversalService::reversePostingGroup()`** on the field job’s `posting_group_id`, then reverses stock movements and labour balances. `ReversalService` negates **quantity** when `amount` is null on usage rows (see `ReversalService.php` ~107–111).

### 1.5 Schema touchpoints

| Table | Relevance |
|-------|-----------|
| `machine_work_logs` | Operational log; optional `machinery_charge_id` when included in a charge. |
| `machinery_charges` / `machinery_charge_lines` | Financial document; lines tie to `machine_work_log_id`, `rate_card_id`, amounts. |
| `field_job_machines` (`2026_04_10_120000_create_field_jobs_tables.php`) | `usage_qty`, `meter_unit_snapshot`, `rate_snapshot`, `amount` (nullable), optional `source_work_log_id`, `source_charge_id` — prepared for linkage / snapshots. |

---

## 2. What can be reused safely from `MachineryChargePostingService`

| Reuse | Safe? | Notes |
|-------|--------|------|
| **Entire `postCharge()`** | **No** as-is | Creates a **second** `PostingGroup` (`MACHINERY_CHARGE`). That violates “one PG per Field Job” and risks **double counting** if a field job also generated a charge. |
| **Idempotency pattern** (key + source_type/source_id) | **N/A** | Field job already idempotently posts as `FIELD_JOB`. |
| **Operational guards** (`OperationalPostingGuard`, crop cycle dates) | **Yes** | Already used by `FieldJobPostingService`. |
| **Account resolution** (`SystemAccountService` codes like `MACHINERY_SERVICE_INCOME`, `EXP_*`) | **Partially** | Charge uses **landlord party** + **pool_scope** to pick Dr account; internal field job cost should align with **`MachineryServicePostingService`** (project expense vs `MACHINERY_SERVICE_INCOME`) rather than `DUE_FROM_HARI` unless the product decision is to mimic charge billing. |
| **Reversal via `ReversalService`** | **Yes** | Already used for field job reversal; any **new ledger lines** in the same original PG reverse automatically with existing logic. |

**Conclusion:** Reuse **patterns** (accounts, allocation shape with `machine_id` + `amount`, snapshots in `rule_snapshot`), **not** `MachineryChargePostingService::postCharge()` for the field job event.

---

## 3. Field Job machinery cost: GL only vs internal MachineryCharge vs shared helpers

| Option | Description | Verdict |
|--------|-------------|---------|
| **A. GL directly inside `FieldJobPostingService`** | Extend the existing `FIELD_JOB` posting with extra `LedgerEntry` rows + money allocations for machine cost. | **Preferred base:** Keeps **one `PostingGroup`**, satisfies invariants. |
| **B. Create and post a linked `MachineryCharge`** | Would normally create **`MACHINERY_CHARGE` `PostingGroup`** — second group per user action unless charge posting is suppressed. | **Rejected** for normal internal field jobs unless the product explicitly wants **two** accounting documents per operation (breaks “one PG per field job”). |
| **C. Extracted machinery costing helpers** | e.g. resolve rate card + amount (from `MachineryChargeService`’s `resolveRateCard` / `mapMeterUnitToChargeUnit` / amount formula) and a small “internal recharge” GL builder shared with `MachineryServicePostingService`. | **Strongly recommended** to avoid drift and to align **rate × qty** semantics. |

**Chosen approach for Phase 2 implementation:** **A + C** — **single `PostingGroup` (`FIELD_JOB`)**, implement machinery **financial** cost by adding GL + amount-bearing allocations inside `FieldJobPostingService`, with **shared helpers** extracted from or aligned with **`MachineryChargeService`** (rate resolution) and **`MachineryServicePostingService`** (GL pairing for internal machinery).

**Do not** post a separate `MachineryCharge` for the same internal field job machine usage in the default flow.

---

## 4. Recommended source-of-truth for normal internal field jobs

- **Operational + accounting document:** `field_jobs` + `field_job_machines` remain the **user-facing source of truth**.
- **At post time (immutable snapshots):**
  - Persist resolved **rate**, **rate_card_id** (if resolved), **computed amount**, and **expense/income allocation metadata** on `field_job_machines` (and/or in `rule_snapshot` on the allocation row — duplication is acceptable if snapshots on the line are the audit trail).
- **Linking:**
  - `source_work_log_id` / `source_charge_id` remain for **exceptional** cases (import from work log, link to external charge), not the default internal path.

---

## 5. Schema additions (`field_job_machines` / optionally `field_jobs`)

Grounded in current columns (`usage_qty`, `meter_unit_snapshot`, `rate_snapshot`, `amount`):

| Column | Purpose |
|--------|---------|
| `rate_card_id` | UUID FK to `machine_rate_cards`, nullable — which card was used at post (audit). |
| `posted_amount` or formalise `amount` | Clear **snapshot** of monetary cost at post (if `amount` is used pre-post as “draft estimate”, naming may need clarification). |
| `cost_computed_at_post` / `unit_rate_snapshot` | If needed for reconciliation; `rate_snapshot` may already cover unit rate. |
| `allocation_scope` or derive from project rules | Only if machinery cost must follow **SHARED / HARI_ONLY / LANDLORD_ONLY** like `MachineryService` (else default **SHARED** for internal field work). |

`field_jobs` **optional:** denormalized `machinery_cost_total` for list views — not required for correctness if sums come from lines + PG.

---

## 6. One `PostingGroup` per Field Job + machine income/cost

**Today:** `MachineryServicePostingService` puts **Dr Expense / Cr `MACHINERY_SERVICE_INCOME`** in **one** `PostingGroup` — internal “recharge” within the farm.

**Recommendation for Field Job:** In the **same** `FIELD_JOB` `PostingGroup`, for each machine line with a resolved monetary amount:

1. Add **ledger entries** mirroring internal machinery service economics, e.g. Dr `EXP_*` / Cr `MACHINERY_SERVICE_INCOME` (exact account mapping should match product policy for **internal** work — **not** `DUE_FROM_HARI` unless explicitly billing a debtor).

2. **Allocation rows:**
   - **Keep** existing **`MACHINERY_USAGE`** row with **`amount = null`** if you still need **pure usage** for quantity-based reports **or** merge into a single row — see risks below.
   - **Add** an **amount-bearing** row with `machine_id` for **project / machine profitability**:
     - Either **`POOL_SHARE`** with `machine_id` + `amount` + `rule_snapshot.source = 'field_job'` (fits current `MachineryReportsController` cost query — see §8),
     - Or a **new** `allocation_type` enum value (cleaner semantics, requires migration + report updates).

**Critical:** Avoid **two money allocations** for the same machine line (duplicate amounts). One resolved **money** total per line.

---

## 7. Reversal strategy

- **Continue** using **`ReversalService::reversePostingGroup()`** for the field job’s original `posting_group_id` — it will:
  - Negate **ledger entries** for new machinery GL lines.
  - Negate **amount** on money allocations; for **`MACHINERY_USAGE`** with `amount` null, negate **quantity** (existing behaviour).

**If** machinery rows carry **both** quantity and amount on the same allocation type, confirm `ReversalService` negates **both** (it does when both are non-null per lines 99–111).

- **No separate** machinery charge reversal for auto-cost path (no charge document).

---

## 8. Reporting implications (machine profitability & project P&L)

### 8.1 `MachineryReportsController` profitability (`machinery/reports/profitability` — see ~185–249)

- **Usage:** Summed from **`machine_work_logs`** where `status = POSTED` — **does not** include field job usage unless extended.
- **Revenue:** `allocation_rows` with `MACHINERY_SERVICE` / `MACHINERY_CHARGE`, `amount` not null, joined to source for non-reversed.
- **Costs:** `allocation_rows` with `machine_id`, `amount` not null, `allocation_type` **not** `MACHINERY_SERVICE` or `MACHINERY_CHARGE`.

**Implication:** Field job machinery **money** cost will appear in **costs** once rows have `amount` + `machine_id` and type is **not** excluded — e.g. **`POOL_SHARE`** with machine_id, or a dedicated cost type.

**Implication:** Field job **quantity-only** `MACHINERY_USAGE` (current) does **not** enter the **costs** bucket (requires `amount`). It also does **not** add to the **usage** bucket (usage is work-log-driven). **Gap:** machine **hours/km** from field jobs are invisible to this report until the query joins **`allocation_rows`** for `FIELD_JOB` / `MACHINERY_USAGE` or `field_job_machines`.

### 8.2 Project P&L / GL

- New **expense** debits in the same PG increase **project-attributed expense** via allocation + GL detail reports, consistent with inputs/labour already posted on the field job.

---

## 9. Duplicate-posting and compatibility risks

| Risk | Mitigation |
|------|------------|
| User creates **MachineryCharge** from work logs **and** records the same usage on a Field Job | Process/policy: internal field jobs use **Field Job** only; charges remain for **landlord billing** cycles. Optionally detect overlapping `source_work_log_id` / same machine+project+date. |
| **Two PGs** if implementing charge posting inside field job | **Do not** call `MachineryChargePostingService::postCharge()` from field job post. |
| **Double GL** if both usage allocation gets an amount and a second row also carries full amount | Single **money** total per machine line; clear rules for one vs two allocation rows. |
| **Reversal** leaves orphan charge links | N/A for auto-cost path; manual charge flow unchanged. |
| **Work log generator** still expects uncharged logs | Field job lines without `source_work_log_id` do not consume work log charge slots. |

**Compatibility:** All existing **`MACHINERY_CHARGE`** API routes, **generate charge**, **post/reverse charge**, and **MachineWorkLog** posting remain as today. Phase 2A only **adds** automated costing **inside** `FieldJobPostingService`.

---

## 10. Exact file plan (implementation phase — not in this step)

1. **`app/Services/FieldJobPostingService.php`** — After resolving cost per `field_job_machines` line: create GL + amount allocation rows; optionally keep quantity-only `MACHINERY_USAGE`; snapshot amounts on lines.
2. **`app/Services/Machinery/MachineryFieldJobCostResolver.php`** (new) — Wrap `resolveRateCard`-equivalent for **machine + date** (and optional activity), `mapMeterUnitToChargeUnit`, amount = qty × rate; or extract shared traits from `MachineryChargeService`.
3. **`app/Services/Machinery/MachineryChargeService.php`** — Refactor private helpers to shared class **only if** duplication is material (optional follow-up).
4. **Migration** — `field_job_machines`: `rate_card_id` + clarify snapshot columns if needed.
5. **`MachineryReportsController`** (or SQL in profitability) — Include field job usage and/or costs consistently (usage from `allocation_rows` with `FIELD_JOB` rule_snapshot or from `field_job_machines` for posted jobs).
6. **Tests** — Extend `FieldJobPostingTest` / `FieldJobFoundationTest`: assert machinery GL lines + allocation amounts + no second PG; reversal nets to zero; profitability query optional assertions.
7. **`config/ledger_write_allowlist.php`** — Already lists `FieldJobPostingService`; no change if logic stays in-class.
8. **Docs** — Update Phase 1 design note cross-reference if one exists.

---

## 11. Summary decision

**Implement automatic machinery financial cost for Field Jobs by extending the existing `FIELD_JOB` `PostingGroup` with ledger entries and amount-bearing, machine-attributed allocation rows**, using **rate card resolution and pricing rules aligned with `MachineryChargeService` / `MachineryServicePostingService`**, **without** creating a separate posted `MachineryCharge`. Preserve **one posting group per field job**, use **`ReversalService`** for full reversal, and **extend machinery profitability reporting** so field job usage and cost are visible alongside work logs and charges.

This design note is grounded in: `FieldJobPostingService`, `MachineryPostingService`, `MachineryChargePostingService`, `MachineryServicePostingService`, `MachineryChargeService`, `ReversalService`, `MachineryReportsController`, and migrations for `field_job_machines`, `machinery_charges`, `machinery_charge_lines`, `machine_work_logs`.
