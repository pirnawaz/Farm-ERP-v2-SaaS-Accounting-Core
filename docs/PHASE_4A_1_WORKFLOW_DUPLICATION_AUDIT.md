# Phase 4A.1 — Workflow Duplication Audit (Economic Recognition)

**Status:** Design note — documentation only (no production code changes in this phase).  
**Scope:** `apps/api`, `apps/web` (behavior inferred from API services, routes, and navigation).  
**Goal:** List every path where the same real-world economic event could be recorded more than once, classify risk, and state a single recommended **primary** workflow per event.

**Architectural invariants (target state):**

- One economic event → one **primary** operational document/posting path.
- No duplicate GL recognition across modules for the same physical event.
- Additive rollout only (this document recommends; it does not change code).

---

## 1. Real-world events and recording modules

Legend for **Overlap**: whether two modules can represent the *same* physical occurrence.  
Legend for **Double GL**: whether posting both can create duplicate **ledger** expense/income lines (not merely duplicate allocation rows).

| Real-world event | Modules / documents that can record it | Overlap? | Double GL risk? |
|-----------------|----------------------------------------|----------|-----------------|
| Machine used on a project (hours / meter) | `MachineWorkLog` → post (`MachineryPostingService`, `MACHINE_WORK_LOG`) | With Field Job machine lines, Machinery Charge lines | See §2.1 |
| Internal machinery charge (pool cost vs machinery income) | `FieldJob` machine lines → `FieldJobPostingService` (`FIELD_JOB`) | With `MachineryCharge` post, `MachineryService` post | **Yes** — same GL pattern |
| Landlord / billing machinery charge from work logs | `MachineryCharge` → `MachineryChargePostingService` (`MACHINERY_CHARGE`) | With Field Job / Machinery Service | **Yes** if same usage billed twice |
| Standalone internal machinery service | `MachineryService` → `MachineryServicePostingService` (`MACHINERY_SERVICE`) | With Field Job / Charge | **Yes** |
| Labour performed on project | `LabWorkLog` → `LabourPostingService` (`LABOUR_WORK_LOG`) | With `FieldJob` labour lines | **Yes** |
| Field labour (non–work-log capture) | `FieldJob` labour lines → `FieldJobPostingService` | With Lab Work Log, Crop Activity labour | **Yes** |
| Legacy / alternate field operations (inputs + labour) | `CropActivity` → `CropActivityPostingService` (`CROP_ACTIVITY`) | With Field Job, Inventory Issue, Lab Work Log | **Yes** for inputs/labour |
| Inputs consumed on project | `FieldJob` inputs → post (ISSUE + GL) | With `InvIssue` → `InventoryPostingService::postIssue` (`INVENTORY_ISSUE`) | **Yes** |
| Inputs consumed (generic issue) | `InvIssue` post | With Field Job inputs | **Yes** |
| Produce harvested into stock | `Harvest` → `HarvestService::post` (`HARVEST`) | Theoretically with manual GRN of same crop (operational discipline) | Mostly process risk |
| Produce allocated to parties (share rules) | `Harvest` + `HarvestShareLine` buckets → same post | N/A (one posting group) | Controlled inside one post |
| In-kind settlement of harvest to machine/labour/landlord/AP | `HarvestService` in-kind lines (`postInKindSettlementIfApplicable`) | With Field Job, Lab Work Log, Machinery Charge/Service | **Yes** — same nominal accounts |
| Pool profit cash distribution | `SettlementService::postSettlement` (`SETTLEMENT`) | Uses ledger profit; does not *create* operational expense | Downstream of duplicates |
| Crop-cycle cash distribution | `SettlementService::settleCropCycle` (`CROP_CYCLE_SETTLEMENT`) | Same | Downstream |
| Correction / arbitrary GL | `JournalEntryService::postJournal` (`JOURNAL_ENTRY`) | With any module posting to same accounts | **Yes** (manual) |
| Supplier invoice for inputs | `SupplierInvoicePostingService` (domain) | With inventory GRN / issues / field job | **Yes** if same purchase expensed twice |

---

## 2. Focus areas (detailed)

### 2.1 Machinery economics

**Paths**

| Path | PostingGroup `source_type` | GL (typical) | Allocation | Inventory |
|------|----------------------------|--------------|------------|-----------|
| Machine work log post | `MACHINE_WORK_LOG` | **None** | `MACHINERY_USAGE` (qty); optional pool scope | No |
| Machinery charge post | `MACHINERY_CHARGE` | Dr expense or `DUE_FROM_HARI` / Cr `MACHINERY_SERVICE_INCOME` | `MACHINERY_CHARGE` | No |
| Field job post (machine line) | `FIELD_JOB` | Dr `EXP_SHARED` / Cr `MACHINERY_SERVICE_INCOME` when financial amount > 0 | `MACHINERY_USAGE` + `MACHINERY_SERVICE` | No (machine is financial + usage rows) |
| Machinery service post | `MACHINERY_SERVICE` | Dr scoped expense / Cr `MACHINERY_SERVICE_INCOME` | `MACHINERY_SERVICE` | Optional: nested **`InvIssue`** + `postIssue` for in-kind pay |

**Overlap analysis**

- **`MACHINE_WORK_LOG` vs `FIELD_JOB` machine lines:** Both can create **`MACHINERY_USAGE`** allocation rows for the same project/machine/time period. Work log posting **does not** hit income/expense GL; field job **does** when rate card / amount resolves. **Risk:** double **allocation** quantity if both are used for the same hours; **double GL** if field job **also** posts financial machinery while a **machinery charge** was posted for the same underlying work logs (see below).
- **Work log → charge:** `MachineryChargeService` links lines to `machine_work_log_id` and sets `machinery_charge_id` on the work log, reducing double charging **for that chain**. This does **not** stop a separate **field job** from posting another machinery financial entry for the same physical use.
- **`FIELD_JOB` vs `MACHINERY_CHARGE` vs `MACHINERY_SERVICE`:** All three can post **Dr expense (or receivable) / Cr `MACHINERY_SERVICE_INCOME`** (exact debit account varies by scope). **HIGH RISK** if operators record the same service in more than one document and post both.
- **Harvest in-kind (`RECIPIENT_MACHINE`):** On `HARVEST` post, creates GL **Dr `MACHINERY_SERVICE_INCOME` / Cr `EXP_SHARED`** (see `HarvestService::ledgerDrIncomeCrExpense`). **Same income/expense names as internal machinery recovery.** **HIGH RISK** if operational machinery cost was **already** recognized via field job / charge / machinery service for the same economic event.

**Risk classification**

| Scenario | Classification |
|----------|------------------|
| Posted `MACHINE_WORK_LOG` + posted `FIELD_JOB` with machine lines (no charge) | **DUPLICATE RISK** on **usage** allocations; GL only from field job unless charge also posted |
| Posted work logs + posted `MACHINERY_CHARGE` + posted `FIELD_JOB` for same usage | **HIGH RISK** — double machinery GL |
| `MACHINERY_SERVICE` + `FIELD_JOB` / `MACHINERY_CHARGE` for same event | **HIGH RISK** |
| Harvest in-kind machine + any operational machinery GL for same settlement story | **HIGH RISK** |

---

### 2.2 Labour economics

**Paths**

| Path | `source_type` | GL | Sub-ledger | Allocation |
|------|---------------|-----|------------|------------|
| Lab work log post | `LABOUR_WORK_LOG` | Dr `LABOUR_EXPENSE` / Cr `WAGES_PAYABLE` | `LabWorkerBalance` + | `POOL_SHARE` |
| Field job labour lines | `FIELD_JOB` | Same GL pair | `LabWorkerBalance` + | `POOL_SHARE` (`cost_type` labour) |
| Crop activity labour | `CROP_ACTIVITY` | Same GL pair | `LabWorkerBalance` + | `POOL_SHARE` |

**Overlap:** Any two of **Lab Work Log**, **Field Job**, **Crop Activity** can accrue **the same** wages expense and payable for the same person/day/project.

**Harvest in-kind labour (`RECIPIENT_LABOUR`):** Dr `WAGES_PAYABLE` / Cr `LABOUR_EXPENSE` on the **`HARVEST`** posting group — same liability/expense pair as operational labour postings.

**Risk:** **HIGH RISK** double GL and **double `LabWorkerBalance`** if two documents are posted for the same work.

---

### 2.3 Inventory

**Paths**

| Path | Stock movement | GL |
|------|----------------|-----|
| Field job inputs | `ISSUE` via `InventoryStockService` (`field_job`) | Dr `INPUTS_EXPENSE` / Cr `INVENTORY_INPUTS` |
| Inventory issue post | `ISSUE` (`inv_issue`) | Same GL pair |
| Crop activity inputs | `ISSUE` (`crop_activity`) | Same GL pair |
| Harvest lines | `HARVEST` movement; capitalize produce | Dr `INVENTORY_PRODUCE` / Cr `CROP_WIP` (balance-sheet reclass + WIP release) |
| Harvest share buckets | Same post as harvest; per-bucket qty/value | Production allocation rows + WIP credit; in-kind adds P&amp;L per role |
| Machinery service in-kind | Nested `InvIssue` + `postIssue` | Full issue GL **in addition** to machinery service GL |

**Overlap:** **Field job vs inventory issue vs crop activity** — same physical draw-down of inputs can be posted three ways. **HIGH RISK** double stock reduction and double `INPUTS_EXPENSE`.

**Harvest produce vs manual GRN:** Not automatically deduped in code; **DUPLICATE RISK** if users both post a harvest and manually receive the same physical crop into stock.

**MachineryService in-kind:** Creates an **inventory issue** with its **own** `INVENTORY_ISSUE` posting — overlaps with any separate issue of the same items for the “same” payment.

---

### 2.4 Settlement

**Paths**

| Mechanism | `source_type` | GL purpose |
|-----------|---------------|------------|
| Project settlement | `SETTLEMENT` | Dr `PROFIT_DISTRIBUTION_CLEARING` / Cr party **control** accounts |
| Crop cycle settlement | `CROP_CYCLE_SETTLEMENT` | Same pattern, aggregated |
| Harvest in-kind (machine/labour/landlord/contractor) | `HARVEST` (same posting group as production) | Recognize in-kind settlement via **income/expense/liability** lines (not party clearing) |

**Behavior:** `SettlementService::getProjectProfitFromLedger` only includes posting groups whose `source_type` is in `OPERATIONAL_SOURCE_TYPES` (see `SettlementService.php`) and whose ledger lines use **income/expense** account types. **`JOURNAL_ENTRY` is not** in that list — manual journals do **not** move **settlement preview** profit, but they **do** hit the GL.

**Overlap:** Cash/settlement does not duplicate operational **expense** postings by itself; the **risk** is **sequencing**: distributing profit that already includes **duplicate** operational costs, or using **harvest in-kind** to settle labour/machinery that was **already** accrued in operations.

---

## 3. Current behavior summary (by path)

| Module / service | Creates GL? | Creates `allocation_rows`? | Inventory movements? |
|------------------|-------------|----------------------------|------------------------|
| `MachineryPostingService` (work log) | No | Yes (`MACHINERY_USAGE`) | No |
| `MachineryChargePostingService` | Yes | Yes | No |
| `FieldJobPostingService` | Yes (inputs, labour, machinery financial) | Yes (inputs/labour pool + machinery usage/service) | Yes (issues on `FIELD_JOB`) |
| `MachineryServicePostingService` | Yes | Yes | Optional nested issue |
| `LabourPostingService` | Yes | Yes | No (balance sub-ledger) |
| `CropActivityPostingService` | Yes (inputs + labour) | Yes | Yes (issues on `crop_activity`) |
| `InventoryPostingService::postIssue` | Yes | Yes | Yes |
| `HarvestService::post` | Yes (WIP + produce + in-kind P&amp;L when applicable) | Yes | Yes (`HARVEST`) |
| `SettlementService` (project / cycle) | Yes (clearing vs party control) | Yes | No |
| `JournalEntryService` | Yes | Typically no operational allocations | No |

---

## 4. Risk classification (summary table)

| ID | Duplication path | Risk |
|----|------------------|------|
| D1 | `FIELD_JOB` machinery GL + `MACHINERY_CHARGE` and/or `MACHINERY_SERVICE` for same service | **HIGH RISK** |
| D2 | `FIELD_JOB` machinery GL + `HARVEST` in-kind machine settlement (`MACHINERY_SERVICE_INCOME` / `EXP_SHARED`) | **HIGH RISK** |
| D3 | `MACHINE_WORK_LOG` allocations + `FIELD_JOB` `MACHINERY_USAGE` for same hours | **DUPLICATE RISK** (allocation; GL depends on other posts) |
| D4 | `LABOUR_WORK_LOG` + `FIELD_JOB` labour + `CROP_ACTIVITY` labour | **HIGH RISK** |
| D5 | `LABOUR_WORK_LOG` / `FIELD_JOB` + `HARVEST` in-kind labour | **HIGH RISK** |
| D6 | `FIELD_JOB` inputs + `INVENTORY_ISSUE` + `CROP_ACTIVITY` inputs | **HIGH RISK** |
| D7 | `Harvest` production + duplicate manual receipt of same produce (e.g. GRN) | **DUPLICATE RISK** (process) |
| D8 | `MachineryService` in-kind nested `INVENTORY_ISSUE` + separate issue of same stock | **DUPLICATE RISK** / **HIGH RISK** depending on intent |
| D9 | `JournalEntryService` to operational accounts + operational modules | **HIGH RISK** (uncontrolled); **settlement preview** ignores `JOURNAL_ENTRY` |
| D10 | `SupplierInvoicePostingService` + operational input consumption for same purchase | **DUPLICATE RISK** / **HIGH RISK** |

---

## 5. Recommended source of truth (per event)

| Event | Primary workflow (single economic truth) | Notes |
|-------|------------------------------------------|--------|
| Machine hours for **allocation / charging** | Either **(A)** `MachineWorkLog` → post → optional `MachineryCharge` → post, **or** **(B)** `FieldJob` with machine lines → post — **not both** for the same hours | If using charges, work logs must be the upstream; field job lines may **reference** `source_work_log_id` for traceability only — **do not** post a second financial machinery layer. |
| Internal machinery cost recovery (no landlord invoice) | **`FieldJob`** *or* **`MachineryService`** — pick one product pattern per tenant | Avoid parallel `MACHINERY_SERVICE` documents for the same job as a posted field job. |
| Labour on project | **`LabWorkLog`** *or* **`FieldJob`** labour — **one** | Prefer work logs when daily time capture is the norm; field job when the job ticket is the only document. Do not use **`CropActivity`** for the same work if **`FieldJob`** is standard. |
| Inputs to the field | **`FieldJob` inputs** *or* **`InvIssue`** — **one** | Prefer field job when tying to agronomy jobs; otherwise issue — not both. |
| Legacy crop activities | **Secondary** | Migrate to field jobs; avoid new parallel postings. |
| Harvest production &amp; sharing | **`Harvest` post** owns production, WIP, stock, and in-kind settlement lines | Operational labour/machinery should be **excluded** from double-counting before in-kind (process: either accrue in ops **or** settle in harvest, not both for the same cost). |
| Cash profit distribution | **`SettlementService`** after operational truth is clean | Fix duplicates **before** relying on pool profit. |
| Corrections | **`JournalEntryService`** only with controlled mapping | Never duplicate operational postings; optional: restrict journals from operational account codes in policy. |

---

## 6. Module tiers (policy recommendation)

### 6.1 Keep primary (choose one stream per domain)

- **`FieldJob`** — primary for **integrated** field operations (inputs + labour + machinery financials) in **`crop_ops`**.
- **`MachineWorkLog` + `MachineryCharge`** — primary for **machinery-module–centric** billing from metered work logs ( **`machinery`** ).
- **`LabWorkLog`** — primary for **labour-module** time &amp; payroll accrual when not using field job labour lines (**`labour`**).
- **`Harvest`** — primary for **produce**, WIP, inventory, and **in-kind** settlement lines.
- **`SettlementService`** — primary for **cash / party clearing** distribution.

### 6.2 Downgrade to secondary / manual

- **`CropActivity`** — legacy overlap with field job; use only where field jobs are not adopted, or for migration.
- **`MachineryService`** — use for exceptional standalone internal services; avoid when the same event is in **`FieldJob`**.
- **`InvIssue`** (standalone) — use when inputs are **not** tied to a field job; avoid duplicating field job issues.

### 6.3 Restrict (governance / rules)

- **`JournalEntryService`** — restrict who can post to **operational** income/expense accounts; require reference to source document or reversal of operational posting.
- **Pairing `HARVEST` in-kind** with **`FIELD_JOB` / `LABOUR_WORK_LOG` / machinery charge** for the **same** cost — restrict via policy until automated netting (`source_field_job_id` / `source_lab_work_log_id` on `HarvestShareLine` is advisory today; see `AddHarvestShareLineRequest` comment on machine shares).

---

## 7. Web application surfacing (duplicate UX paths)

Navigation (`apps/web/src/config/nav.ts`) exposes **separate** entry points without mutual exclusion:

- **Machine usage:** `/app/machinery/work-logs`
- **Field jobs:** `/app/crop-ops/field-jobs`
- **Labour work logs:** `/app/labour/work-logs`
- **Harvests:** `/app/harvests`
- **Field work logs (activities):** `/app/crop-ops/activities` (legacy crop activities)

Operators with multiple modules enabled can therefore initiate overlapping workflows from different menus. **Training and module-gating** are part of the control story until the API enforces exclusivity.

---

## 8. Gaps explicitly **not** solved in code (this audit)

- No hard database constraint prevents posting **`FIELD_JOB`** and **`LABOUR_WORK_LOG`** for the same labour amount.
- `HarvestShareLine.source_field_job_id` / `source_lab_work_log_id` exist for traceability but **do not** auto-reverse or block operational duplicates.
- `SettlementService::OPERATIONAL_SOURCE_TYPES` **includes** `FIELD_JOB` (so field costs **do** affect pool profit in ledger settlement); **`JOURNAL_ENTRY` is excluded** from that profit query.

---

## 9. Acceptance mapping

| Criterion | Addressed |
|-----------|-----------|
| Every duplication path listed | §2, §4 (D1–D10) |
| Clear recommendation per event | §5 |
| No ambiguity on which module to use | §5–6 (tiers + primary picks) |
| GL vs allocation vs inventory | §3, §2 tables |

---

*End of Phase 4A.1 duplication audit.*
