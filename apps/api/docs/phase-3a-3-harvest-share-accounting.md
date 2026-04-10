# Phase 3A.3 — Produce-Share Settlement: Accounting Semantics (Terrava)

**Status:** Design only — no production code.  
**Depends on:** `phase-3a-2-harvest-share-inventory-valuation.md` (qty/value splits), `phase-3a-1-harvest-share-in-kind-settlement.md` (scope).  
**Domain:** `apps/api` — GL, `allocation_rows`, reporting assumptions.

---

## 0. Audit: current account usage (codebase)

### 0.1 Harvest posting (`HarvestService::post`)

| Entry | Account (code) | Type | Role |
|-------|------------------|------|------|
| Dr | `INVENTORY_PRODUCE` | asset | Capitalize produce per line |
| Cr | `CROP_WIP` | asset | Release accumulated WIP (aggregate) |

- **No income/expense lines** on harvest post today — **balance sheet only** (asset reclassification + quantity into stock).
- `SettlementService::getProjectProfitFromLedger` filters ledger by **`accounts.type` in (`income`, `expense`)** — **harvest posting does not** change pool P&amp;L in that query.

### 0.2 Field job machinery (`FieldJobPostingService`)

| Entry | Account | Role |
|-------|---------|------|
| Dr | `EXP_SHARED` | Project shared expense |
| Cr | `MACHINERY_SERVICE_INCOME` | Internal machinery recovery / income |

- Allocation: `MACHINERY_USAGE` (qty), `MACHINERY_SERVICE` (amount + machine_id), `POOL_SHARE` for inputs/labour.

### 0.3 Labour work log (`LabourPostingService`)

| Entry | Account | Role |
|-------|---------|------|
| Dr | `LABOUR_EXPENSE` | Expense |
| Cr | `WAGES_PAYABLE` | Accrued wages |

- `LabWorkerBalance` incremented (operational sub-ledger).
- Allocation: `POOL_SHARE` with `amount`.

### 0.4 Machinery charge (`MachineryChargePostingService`)

| Pool scope | Dr | Cr |
|------------|----|----|
| HARI_ONLY | `DUE_FROM_HARI` | `MACHINERY_SERVICE_INCOME` |
| SHARED | `EXP_SHARED` | `MACHINERY_SERVICE_INCOME` |
| LANDLORD_ONLY | `EXP_LANDLORD_ONLY` | `MACHINERY_SERVICE_INCOME` |

- Allocation: `MACHINERY_CHARGE`, `allocation_scope` SHARED / HARI_ONLY / LANDLORD_ONLY.

### 0.5 Cash settlement (`SettlementService::postSettlement`)

| Entry | Account | Role |
|-------|---------|------|
| Dr | `PROFIT_DISTRIBUTION_CLEARING` | equity (clearing) |
| Cr | `PARTY_CONTROL_LANDLORD`, `PARTY_CONTROL_HARI`, … | Obligation to parties |

- **Operational postings** must **not** use `PARTY_CONTROL_*` / `PROFIT_DISTRIBUTION_CLEARING` (comment in code). Harvest share design must **not** casually post those unless explicitly a **settlement-class** event (usually **separate** `SETTLEMENT` posting group).

### 0.6 Seeded accounts relevant to in-kind

- `MACHINERY_INTERNAL_SERVICE_CLEARING` (equity) — **exists in `SystemAccountsSeeder`**; **not referenced** by current posting services (candidate for controlled **pairing** / memo if needed).
- `COGS_PRODUCE`, `WAGES_PAYABLE`, `PAYABLE_LANDLORD`, `DUE_TO_LANDLORD`, `AP` — available for liability / COGS paths if policy requires.

---

## 1. Design decisions (required questions)

### 1.1 Machine produce share — inventory vs internal income/recovery?

**Recommended:** **Both**, in one **`HARVEST` posting group**, **two logical layers**:

1. **Capitalization (physical + WIP):**  
   **Dr `INVENTORY_PRODUCE`** (per bucket / store) **/ Cr `CROP_WIP`** — total Cr equals total WIP released; sum of Dr equals same (see valuation note). **No P&amp;L** here.

2. **Settlement of prior operational accrual (internal machinery only):**  
   For value **V** that **settles** machinery service **already recognized** by **Field Job** (Dr `EXP_SHARED` / Cr `MACHINERY_SERVICE_INCOME`), post a **paired reversal of that slice**:  
   **Dr `MACHINERY_SERVICE_INCOME` V / Cr `EXP_SHARED` V**  
   - **Effect:** Net **zero** to pool profit for that slice (removes duplicate economic burden: project no longer “owes” that portion as expense/income pair), while **inventory** already reflects bags in the machine bucket.  
   - **Interpretation:** Economically equivalent to **paying** the internal machinery service **in kind** instead of leaving the accrual open.

**Do **not** Cr `CROP_WIP` again** for a second time — WIP is credited **once** in the capitalization layer.

**Third-party machine** (no Field Job pair): use **charge** document (`MACHINERY_CHARGE`) or **standalone** Dr expense / Cr payable — if **only** harvest in-kind: **Dr** appropriate expense or **`DUE_TO_*`**, **Cr** a **clearing** account that ties to inventory — **or** **Dr `MACHINERY_SERVICE_INCOME`** / **Cr `EXP_*`** only if contractor is treated as machinery income recipient (policy). Prefer **explicit** `AP` / `DUE_TO_CONTRACTOR` if added later.

### 1.2 Labour produce share — clear payable or “payable-in-kind”?

**Recommended:** **Reduce the existing accrual** where labour was posted via **`LABOUR_WORK_LOG`**:  
**Dr `WAGES_PAYABLE` V / Cr `LABOUR_EXPENSE` V**  
- Mirrors **partial reversal** of the original Dr/Cr for the **settled** amount (same pattern as machinery).  
- **No new “payable in kind” liability account** is required if **WAGES_PAYABLE** already holds the obligation; in-kind **discharges** it.

If **no** prior work log (pure harvest labour share): **only** **Dr `INVENTORY` / Cr `CROP_WIP`** plus optional **Dr `LABOUR_EXPENSE` / Cr `WAGES_PAYABLE`** if accrual must be **created** first — **policy** (prefer accrue-then-settle in two events vs single harvest event).

### 1.3 Landlord produce share — party settlement liability vs allocation only?

**Recommended:** **Do not** use **`SETTLEMENT` posting group** machinery (`PROFIT_DISTRIBUTION_CLEARING` + `PARTY_CONTROL_*`) **inside** harvest — keep **one harvest event → one `HARVEST` posting group**.

- For **in-kind discharge** of **lease/rent accruals** (if tracked): **Dr `PAYABLE_LANDLORD` or `DUE_TO_LANDLORD`** (reduce liability) **/ Cr `EXP_LANDLORD_ONLY`** or **`RENT_EXPENSE`** reversal — **exact pair** depends on **which account was debited** when rent was accrued (if any).  
- If **no** prior accrual: **inventory split only** + optional **Dr `EXP_LANDLORD_ONLY` / Cr clearing** — **policy**.

**Direct `POOL_SHARE` allocation row without ledger** is **insufficient** for statutory GL — **amount** must hit **real** accounts if recognizing settlement.

### 1.4 Allocations: quantity vs amount vs both?

| Concept | Quantity | Amount | Notes |
|---------|----------|--------|-------|
| Owner retained | ✓ | ✓ | Full trace for reports |
| Machine / labour / landlord share | ✓ | ✓ | `machine_id` / `party_id` as today |
| **Pure WIP capitalization** | ✓ | ✓ | `HARVEST_PRODUCTION` (existing) |
| **In-kind settlement slice** | Optional | ✓ | Link to `settles_posting_group_id` / `settles_source` in `rule_snapshot` |

**Both** recommended where produce is measured in **kg/bags** and valued in **money** for P&amp;L pairing.

### 1.5 New allocation types / scopes

**New types (recommended names):**

| Type | Meaning |
|------|---------|
| `HARVEST_CAPITALIZATION` | Optional rename/split from today’s `HARVEST_PRODUCTION` per bucket |
| `HARVEST_IN_KIND_MACHINE` | Machine recipient; links to Field Job PG if internal |
| `HARVEST_IN_KIND_LABOUR` | Labour recipient |
| `HARVEST_IN_KIND_LANDLORD` | Landlord recipient |
| `HARVEST_IN_KIND_CONTRACTOR` | Third-party contractor |
| `HARVEST_OWNER_RETAINED` | Explicit owner residual |

**Scopes:** Reuse `SHARED` / `HARI_ONLY` / `LANDLORD_ONLY` where parallel to machinery charge semantics; add **`IN_KIND`** in `rule_snapshot` if needed.

### 1.6 Reports: distinguish owner / in-kind / internal machine / third-party

- **Allocation rows:** `allocation_type` + `party_id` + `machine_id` + `rule_snapshot` keys: `share_role`, `counterparty_accrual_pg_id`, `internal_machinery: true/false`.
- **Ledger:** P&amp;L impact only on **settlement pairs** (income/expense accounts); **inventory** on **asset** reports.
- **Project profitability (ledger-based):** unchanged formula; **ensure** `FIELD_JOB` in operational source types when implementing (known gap).

---

## 2. Recommended ledger treatment by share type

### 2.1 Owner residual

- **GL:** **Dr `INVENTORY_PRODUCE`**, **Cr `CROP_WIP`** only (per valuation note).  
- **No** income/expense settlement lines **unless** selling later (COGS on **sale** issue).

### 2.2 Machine (internal — Field Job already posted)

- **Inventory:** Dr machine bucket(s), Cr `CROP_WIP` (part of total capitalization).
- **P&amp;L settlement:** **Dr `MACHINERY_SERVICE_INCOME`**, **Cr `EXP_SHARED`** for **V** = in-kind value settled (same currency as Field Job).

### 2.3 Machine (third-party contractor)

- **Inventory:** Dr contractor-destination inventory (if modelled).
- **P&amp;L / liability:** Prefer **Dr `EXP_SHARED` or contractor expense**, **Cr `AP`/`DUE_TO_*`** when accrual exists; **in-kind payment:** **Dr liability**, **Cr** … **not** second inventory if inventory already capitalized to contractor — use **one** coherent path (policy: **accrue charge** then **harvest pays** vs **only harvest**).

### 2.4 Labour

- **Inventory:** Dr labour recipient store / bucket.
- **P&amp;L:** **Dr `WAGES_PAYABLE`**, **Cr `LABOUR_EXPENSE`** for **V** settled (when work log accrual exists).

### 2.5 Landlord

- **Inventory:** Dr landlord-designated store or project-controlled store per agreement.
- **P&amp;L / liability:** **Dr liability** (e.g. `PAYABLE_LANDLORD`) **/ Cr expense reversal** for the slice that **discharges** rent/landlord share — account pair must match **how rent was accrued** in tenant config.

### 2.6 Contractor (generic)

- Same pattern as third-party machine: **liability + expense** accounts per contract; **inventory** split first; settlement lines **discharge** accrual.

---

## 3. Allocation row semantics

- **`project_id`:** Always the **harvest’s project** (accounting anchor).
- **`party_id`:** **Beneficiary** party for that row (owner project party vs landlord vs worker vs machine internal entity — **machine** may use **`machine_id`** without party if internal).
- **`amount`:** Monetary value **V** for P&amp;L settlement lines; **also** on capitalization rows for produce reporting.
- **`rule_snapshot` (minimum):**  
  `{ "harvest_id", "harvest_line_id", "share_role", "valuation_basis": "WIP_LAYER", "settles_source_type": "FIELD_JOB", "settles_posting_group_id": "…", "settles_amount": "…" }`  
  when netting Field Job machinery.

---

## 4. Examples (journal logic — single `HARVEST` posting group)

### 4.1 Ten bags, one bag machine share (internal), total WIP $500

**Capitalization (inventory):**

| Account | Dr | Cr |
|---------|-----|-----|
| `INVENTORY_PRODUCE` (owner store) | 450 | |
| `INVENTORY_PRODUCE` (machine store) | 50 | |
| `CROP_WIP` | | 500 |

**In-kind machinery settlement (Field Job accrual exists for ≥ $50):**

| Account | Dr | Cr |
|---------|-----|-----|
| `MACHINERY_SERVICE_INCOME` | 50 | |
| `EXP_SHARED` | | 50 |

**Net P&amp;L:** Same as if $50 cash had netted the internal machinery pair; **inventory** shows 1 bag with machine.

### 4.2 Labour share settled in produce (prior work log $300; in-kind value $300)

**Capitalization:** Dr `INVENTORY_PRODUCE` (worker/labour bucket) $300, … Cr `CROP_WIP` as part of total.

**Settlement:**

| Account | Dr | Cr |
|---------|-----|-----|
| `WAGES_PAYABLE` | 300 | |
| `LABOUR_EXPENSE` | | 300 |

### 4.3 Landlord share in produce (liability $200; in-kind value $200)

**Capitalization:** Dr `INVENTORY_PRODUCE` (landlord path) $200, … Cr `CROP_WIP`.

**Settlement (if rent was expensed to `EXP_LANDLORD_ONLY`):**

| Account | Dr | Cr |
|---------|-----|-----|
| `PAYABLE_LANDLORD` or `DUE_TO_LANDLORD` | 200 | |
| `EXP_LANDLORD_ONLY` | | 200 |

*(Exact credit account must match **prior** debit pattern in production tenant.)*

---

## 5. Reporting consequences

| Report / query | Effect |
|----------------|--------|
| **Settlement pool profit** (`getProjectProfitFromLedger`) | Only **income/expense** movements count; **in-kind pairs** above **net to zero** for settled slice — **no double-count** with Field Job if reversal amounts match. |
| **Harvest quantity / cost reports** | Use **inventory movements** + **HARVEST** allocations; **per-bucket** rows needed. |
| **Machine profitability** | **Risk:** `MACHINERY_SERVICE_INCOME` **reduced** by in-kind settlement — reports must show **cash vs in-kind** via `rule_snapshot` / new allocation types. |
| **Party statements** | **WAGES_PAYABLE** / landlord liabilities **reduce** — align with **operational** sub-ledgers (`LabWorkerBalance` may need **decrement** for in-kind — **separate operational design**). |

---

## 6. Edge cases

| Case | Handling |
|------|----------|
| **In-kind value &gt; prior accrual** | Cap settlement at accrual balance; **remainder** as **pure inventory** + **manual** adjustment or policy exception. |
| **No Field Job but machine gets bags** | **No** `MACHINERY_SERVICE_INCOME` / `EXP_SHARED` pair — **inventory only** or **accrue** machinery via other doc first. |
| **Double counting Field Job + harvest** | **Prevent** by **linking** `settles_posting_group_id` and **blocking** duplicate settlement for same service window. |
| **Using `PROFIT_DISTRIBUTION_CLEARING` on harvest** | **Rejected** — keep harvest **operational**; cash settlement remains **`SETTLEMENT`** PG. |
| **New account codes** | **None mandatory** if reusing `EXP_SHARED`, `MACHINERY_SERVICE_INCOME`, `WAGES_PAYABLE`, `LABOUR_EXPENSE`, `PAYABLE_LANDLORD`, `INVENTORY_PRODUCE`, `CROP_WIP`. Optional: **`MACHINERY_INTERNAL_SERVICE_CLEARING`** for **memo** splits if product wants equity-layer visibility without touching income/expense. |

---

## 7. Explicit alignment with invariants

| Invariant | How |
|-----------|-----|
| Immutable accounting | Posted **`HARVEST`** group immutable; reversal = **`ReversalService`** full negate. |
| Explicit posting only | All lines created at post; no silent accrual. |
| Project anchor | **`project_id`** on every allocation row. |
| One harvest → one posting group | All capitalization + in-kind **GL** in **same** `PostingGroup` (`source_type = HARVEST`). |

---

## 8. Related docs

- `phase-3a-1-harvest-share-in-kind-settlement.md`  
- `phase-3a-2-harvest-share-inventory-valuation.md`

---

*End of note.*
