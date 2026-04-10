# Phase 4B.1 — Enforcement Rules Design (Duplicate Economic Recognition)

**Status:** Design only — `apps/api` behavior described; **no implementation** in this phase.  
**Depends on:** [`PHASE_4A_1_WORKFLOW_DUPLICATION_AUDIT.md`](./PHASE_4A_1_WORKFLOW_DUPLICATION_AUDIT.md)

**Invariants**

- No **silent** duplicate posting: every blocked path returns a **clear** HTTP **422** (or equivalent) with a stable `code` + human message; warnings return **200/422** per policy with `warnings[]` (product decision).
- Rules are **consistent** across modules: the same economic event cannot satisfy two **primary** sources in **strict** mode.
- **Flexible** mode relaxes selected rules to **SOFT WARNING** only (see §7).

**Notation**

- **HARD BLOCK** → reject action (422).
- **SOFT WARNING** → allow with warnings (or require `confirm: true` — product choice).
- **ALLOWED** → no enforcement beyond normal validation.

---

## 1. Rule categories (summary)

| Category | API behavior (recommended) |
|----------|----------------------------|
| **HARD BLOCK** | Request fails; body includes `message`, `code`, optional `conflicts[]` with entity ids. |
| **SOFT WARNING** | Request succeeds only if `?force=true` / body `acknowledge_warnings: true` **or** returns 422 until acknowledged (pick one product-wide). |
| **ALLOWED** | No duplicate-economy check. |

---

## 2. Tenant-level configuration (optional)

| Mode | Meaning |
|------|---------|
| **`strict`** | All **HARD BLOCK** rules in this document apply as written; **SOFT** rules may still return warnings. |
| **`flexible`** | Selected **HARD BLOCK** rows downgrade to **SOFT WARNING** (see §7.1); use for migration / training tenants. |

**Suggested config key (future):** `tenant.operational_posting_policy` = `strict` | `flexible`.

### 2.1 Flexible mode: default downgrades (strict → soft)

| Strict rule | In `flexible` mode |
|-------------|-------------------|
| Machinery: work log on both charge and field job | **SOFT WARNING** only |
| Machinery usage allocation duplicate (`MACHINE_WORK_LOG` + `FIELD_JOB`) | **SOFT WARNING** |
| Harvest in-kind machine vs posted field job (no `source_field_job_id`) | **SOFT WARNING** |
| Harvest in-kind labour vs posted lab work log (no `source_lab_work_log_id`) | **SOFT WARNING** |
| Labour double accrual (field job vs lab log) | **SOFT WARNING** (not recommended long-term) |
| Inputs double issue | **SOFT WARNING** |
| Crop activity vs field job overlap | **SOFT WARNING** |

**Never auto-downgrade in flexible mode (remain HARD BLOCK):**

- Posting **second financial** `MACHINERY_CHARGE` line for a work log already on a **posted** charge (existing charge pipeline rules).
- Idempotent **duplicate posting group** / accounting period close violations (when implemented).

---

## 3. Cross-module linking fields (enforcement inputs)

These fields are **declarations of intent** for netting, reporting, and (future) automated reversal. Enforcement **must** read them when present.

| Field | Model | Use in rules |
|-------|--------|----------------|
| `source_field_job_id` | `HarvestShareLine` | Declares which **Field Job** (if any) the share line is meant to pair with for **machine** (and context for labour) in-kind vs operational postings. |
| `source_lab_work_log_id` | `HarvestShareLine` | Declares pairing with **Lab Work Log** for labour in-kind. |
| `source_machinery_charge_id` | `HarvestShareLine` | Declares pairing with **Machinery Charge** for harvest share vs billed machinery. |
| `source_work_log_id` | `FieldJobMachine` | Links machine line to **Machine Work Log** (traceability; charge pipeline uses work logs). |
| `source_charge_id` | `FieldJobMachine` | Optional link to **Machinery Charge** document. |

**Rule LK-0:** If a **HARD BLOCK** would fire but the user sets the correct **`source_*`** link that **explains** co-existence (e.g. harvest in-kind machine share explicitly references the field job that already recognized operational machinery), enforcement may **downgrade** to **SOFT WARNING** only if a documented **netting** implementation exists; **until netting is implemented**, treat unexplained pairs as **HARD BLOCK** in **strict** mode (see §4.1.3).

**Rule LK-1 (`source_machinery_charge_id`):** Use when the share line is tied to a **specific** posted or draft **Machinery Charge** (e.g. clarifying that in-kind settlement relates to billed machinery for the same crop). Enforcement: **HARD BLOCK** if `source_machinery_charge_id` points to a charge whose work logs are **also** fully covered by a **different** posted machinery path (field job vs charge) unless `flexible` or charge is reversed.

---

## 4. Machinery rules

### 4.1 Field Job machine usage vs Machinery Charge

**Principle:** For a given **machine work log** row, at most one **financial** recognition path: **either** inclusion in a **posted Machinery Charge** **or** financial recognition via a **posted Field Job** machine line that references that work log — not both.

| Event | Allowed source | Blocked sources | Conditions | Enforcement | Error `code` / message (example) |
|-------|----------------|-----------------|------------|-------------|----------------------------------|
| Bill metered work to landlord / pool via charge | `MachineryCharge` ← draft from **posted** `MachineWorkLog`s | Posting **Field Job** whose machine line has `source_work_log_id` ∈ charge’s work logs **and** field job would post **machinery financial GL** | **strict:** Work log not already **financially** covered by a **posted** `FIELD_JOB` line pointing at it | **HARD BLOCK** on `FieldJobPostingService::postFieldJob` | `MACHINERY_DOUBLE_FINANCIAL`: "Machine work log {id} is already billed on machinery charge {id}. Remove the field job machine line or reverse the charge posting." |
| Same | Same | Generating **draft** charge including work logs | Work log already linked to **posted** `FIELD_JOB` machine line with `source_work_log_id` = that log | **HARD BLOCK** on charge generation | `WORK_LOG_ALREADY_IN_FIELD_JOB`: "Work log {id} is tied to posted field job {id}. Exclude it from the charge or reverse the field job machinery line." |
| Internal machinery via field ops | `FieldJob` machine lines (posted) | **Posting** `MachineryCharge` whose lines reference work logs already tied to **posted** field job machine lines (`source_work_log_id`) | Same project/cycle; **strict** | **HARD BLOCK** on `MachineryChargePostingService::postCharge` | `CHARGE_CONFLICTS_FIELD_JOB`: "Charge includes work logs already recognized on field job {id}." |
| Charge-first workflow | `MachineWorkLog` post → charge → post | `FieldJob` machine line **without** `source_work_log_id` duplicating same meter reading | **flexible** only | **SOFT WARNING** on field job post | `MACHINERY_POSSIBLE_DUPLICATE`: "Machine usage may overlap with posted machinery charge {id}." |
| Usage-only allocation (no double GL) | `MachineWorkLog` post (`MACHINE_WORK_LOG`, no GL) | `FieldJob` with machine lines on same project/date | **strict** | **SOFT WARNING** (allocation double-count risk) | `MACHINERY_USAGE_ALLOCATION_DUPLICATE`: "Posted machine work log exists for this project; field job adds another MACHINERY_USAGE allocation." |

**Answer (design):**

- **Should manual Machinery Charge be allowed when Field Job machine usage exists?**
  - **Yes, only when** work logs included in the charge are **disjoint** from work logs / economic scope already **financially** posted on a **Field Job**, **or** the field job machine lines are **not** using overlapping work logs (no `source_work_log_id` collision) and tenant policy treats them as different events (e.g. charge = landlord billing, field job = internal transfer) — **documented exception** per tenant SOP.
  - **No (strict default):** If **posted** field job machine lines reference `source_work_log_id`, **those** work logs **must not** appear on a **posted** machinery charge.

---

### 4.2 Harvest in-kind machine share vs Field Job machinery costing

| Event | Allowed source | Blocked sources | Conditions | Enforcement | Message |
|-------|----------------|-----------------|------------|-------------|---------|
| Recognize machine participant’s share via harvest in-kind | `Harvest` post with share line `recipient_role=MACHINE`, `settlement_mode=IN_KIND` | **Posting** / **posted** `FIELD_JOB` with machinery **financial** GL for **same** `(tenant, project_id, machine_id)` in overlapping **cost window** (e.g. same crop cycle + date range policy) | `source_field_job_id` **not** set or does not match the conflicting field job | **HARD BLOCK** (strict) | `HARVEST_IN_KIND_MACHINE_CONFLICTS_FIELD_JOB`: "Operational machinery cost already posted on field job {id}. Set source_field_job_id on the share line, reverse the field job, or switch settlement mode." |
| Same with explicit link | Harvest share line with `source_field_job_id` → Field Job | Posted field job exists | **Netting not implemented** | **HARD BLOCK** *or* **SOFT WARNING** per §3 LK-0 | `HARVEST_IN_KIND_REQUIRES_NETTING`: "Linked field job {id} already recognized machinery GL; confirm netting or reverse field job machinery." |
| No operational field job | Harvest in-kind only | — | — | **ALLOWED** | — |

**Answer (design):**

- **Should Field Job machine costing be restricted when harvest in-kind machine share exists?**
  - **Strict:** **Yes** — do not **post** a new **Field Job** with machinery financial lines if a **posted** `Harvest` already contains an **in-kind machine** share for the same **project + machine** unless policy explicitly allows (or reverse harvest / use `flexible`).
  - **Conversely:** Do not **post Harvest** with in-kind machine if **posted Field Job** already has machinery financials for same **project + machine** without valid `source_field_job_id` + future netting.

---

## 5. Labour rules

**Principle:** For the same **worker + project + work date** (or **lab work log id**), at most one **labour expense** accrual path: **Lab Work Log** **or** **Field Job** labour **or** **Crop Activity** labour — not multiples.

| Event | Allowed source | Blocked sources | Conditions | Enforcement | Message |
|-------|----------------|-----------------|------------|-------------|---------|
| Accrue wages to pool | **One of:** `LabWorkLog` post, `FieldJob` labour post, `CropActivity` labour post | The other two for overlapping worker/project/date | **strict** | **HARD BLOCK** on second post | `LABOUR_DOUBLE_ACCRUAL`: "Labour already recognized for this worker and project on {document_type} {id}." |
| Harvest in-kind labour | `Harvest` share line `RECIPIENT_LABOUR`, `IN_KIND` | **Posted** `LabWorkLog` / **posted** `FieldJob` labour for same worker+project in same window | `source_lab_work_log_id` / labour link missing | **HARD BLOCK** (strict) | `HARVEST_IN_KIND_LABOUR_CONFLICTS_OPS`: "Labour already accrued on lab work log {id}. Link source_lab_work_log_id or reverse operational accrual." |
| Field Job labour vs Lab Work Log | Prefer **one** primary per tenant (config) | Secondary module | **flexible** | **SOFT WARNING** | `LABOUR_ALTERNATE_MODULE`: "Tenant policy prefers {primary}; this duplicates labour capture." |

**When is each allowed? (design)**

| Module | Allowed when |
|--------|----------------|
| **LabWorkLog** | Daily payroll / time-sheet truth; **not** duplicated by field job labour for same shift. |
| **FieldJob** labour | Job-ticket truth for crop ops; **not** duplicated by lab work log for same shift. |
| **Harvest share (labour)** | In-kind settlement only; **requires** either no operational duplicate **or** explicit `source_lab_work_log_id` (and future netting rules). |
| **CropActivity** | Legacy only; **blocked** in strict if **FieldJob** exists for same operational intent (optional **HARD BLOCK**). |

---

## 6. Harvest rules (share lines vs settlement)

| Event | Allowed source | Blocked sources | Conditions | Enforcement | Message |
|-------|----------------|-----------------|------------|-------------|---------|
| Distribute profit after harvest sharing | `SettlementService::postSettlement` | — | **strict:** All **draft** harvests for `project_id` that **should** be posted before profit lock | **SOFT WARNING** or **HARD BLOCK** (config) | `SETTLEMENT_WITH_DRAFT_HARVESTS`: "Draft harvests exist for this project; post or exclude before settlement." |
| Same | Same | — | Harvest has **in-kind** share lines; settlement uses **income/expense** from ledger | — | **ALLOWED** (no block): in-kind is **operational** P&amp;L; settlement is **clearing** — **do not** block solely because share lines exist |
| Double distribution | Cash settlement | Second settlement for same scope | Already **posted** `SETTLEMENT` for same project+period | **HARD BLOCK** (idempotency / existing business rules) | `SETTLEMENT_ALREADY_POSTED` |
| No share lines on harvest | `Harvest` post (production only) | — | Default buckets: **100%** owner/pool per `HarvestShareBucketService` / product rules | **ALLOWED**; document **fallback** | — |

**If share lines exist — should manual settlement be blocked?**

- **Design answer: No (default HARD BLOCK not recommended).** Share lines define **allocation** and in-kind **P&amp;L**; **manual settlement** still posts **party clearing** (`PROFIT_DISTRIBUTION_CLEARING`). Blocking settlement would prevent legitimate **cash** distribution. Instead:
  - **SOFT WARNING** if draft harvests remain, or
  - **HARD BLOCK** only when **strict** + **business rule** “no settlement until harvests posted” is enabled.

**If no share lines — fallback behavior**

- **Harvest post** still runs bucket computation with **implicit owner** / single pool bucket (per existing `HarvestShareBucketService` behavior).
- **Settlement** uses ledger profit as today; **no** share-line prerequisite.

---

## 7. Inventory rules

| Event | Allowed source | Blocked sources | Conditions | Enforcement | Message |
|-------|----------------|-----------------|------------|-------------|---------|
| Issue inputs to project | **One of:** `FieldJob` input post, `InvIssue` post, `CropActivity` input post | The others for same `(store_id, item_id, project_id, qty)` in same intent window | **strict** | **HARD BLOCK** | `INPUTS_DOUBLE_ISSUE`: "Inventory already issued to this project via {source} {id}." |
| Machinery in-kind pay | `MachineryService` nested `InvIssue` | Standalone `InvIssue` of same batch for same payment story | Duplicate qty | **HARD BLOCK** or **SOFT WARNING** | `INPUTS_DUPLICATE_MACHINERY_INKIND`: "Issue already created for machinery service {id}." |
| Harvest output | `Harvest` post (`HARVEST` movement) | `InvGrn` / other receipt of **same physical lot** | Lot/batch id not modeled → **strict** cannot always detect | **SOFT WARNING** on second receipt of same item+qty+date | `PRODUCE_POSSIBLE_DUPLICATE_RECEIPT` |
| Field job + generic issue | `FieldJob` | `InvIssue` | Same lines | **HARD BLOCK** in strict | See `INPUTS_DOUBLE_ISSUE` |

**Duplicate harvest output recognition**

- **HARD BLOCK** when a **posted** `HARVEST` already increased stock for `(item, store)` and user attempts a second **capitalizing** movement with same **harvest reference** (idempotency already helps); for **different** document types, use **SOFT WARNING** until batch tracking exists.

---

## 8. Master rule table (high-risk paths from Phase 4A.1)

| ID | Economic event | Allowed primary source | Blocked / conflicting sources | Mode | Type | Example message |
|----|----------------|------------------------|----------------------------------|------|------|-----------------|
| D1 | Machinery financial GL | Posted `FIELD_JOB` **xor** posted `MACHINERY_CHARGE` **xor** posted `MACHINERY_SERVICE` (disjoint scope) | Any second source for **same work log / same declared event** | strict | **HARD** | `MACHINERY_DOUBLE_FINANCIAL` |
| D1 | Same | Same as tenant primary SOP | Same | flexible | **SOFT** | `MACHINERY_POSSIBLE_DUPLICATE` |
| D2 | In-kind machine vs ops | Harvest in-kind **after** ops reversed **or** ops not used | Posted `FIELD_JOB` machinery + Harvest in-kind same machine+project | strict | **HARD** | `HARVEST_IN_KIND_MACHINE_CONFLICTS_FIELD_JOB` |
| D3 | Machinery usage allocation | Single of: `MACHINE_WORK_LOG` **or** `FIELD_JOB` usage rows | Both for identical hours without link | strict | **SOFT** (or HARD if configured) | `MACHINERY_USAGE_ALLOCATION_DUPLICATE` |
| D4 | Labour accrual | One of `LABOUR_WORK_LOG`, `FIELD_JOB`, `CROP_ACTIVITY` | Second accrual same worker+project+date | strict | **HARD** | `LABOUR_DOUBLE_ACCRUAL` |
| D5 | In-kind labour vs ops | Harvest with `source_lab_work_log_id` **or** no ops accrual | Posted lab log + in-kind labour | strict | **HARD** | `HARVEST_IN_KIND_LABOUR_CONFLICTS_OPS` |
| D6 | Inputs issue | One of field job / issue / crop activity | Second path same consumption | strict | **HARD** | `INPUTS_DOUBLE_ISSUE` |
| D7 | Produce receipt | `HARVEST` post | Manual GRN of same lot | strict | **SOFT** | `PRODUCE_POSSIBLE_DUPLICATE_RECEIPT` |
| D8 | Machinery in-kind issue | Nested issue from `MachineryService` | Duplicate `InvIssue` | strict | **HARD**/**SOFT** | `INPUTS_DUPLICATE_MACHINERY_INKIND` |
| D9 | Manual journal to ops accounts | `JOURNAL_ENTRY` | Same period as operational posting (cannot detect automatically) | strict | **SOFT** (policy) | `JOURNAL_OVERLAPS_OPERATIONS` |
| D10 | Supplier invoice vs issue | Invoice capitalization | Same purchase as GRN+issue already expensed | strict | **SOFT**/**HARD** (if PO link) | `PURCHASE_DOUBLE_EXPENSE` |

---

## 9. Conflict resolution (non-conflicting ordering)

1. **Detect** conflicts at **post** time (draft documents may **warn** only).
2. **Prefer** explicit `source_*` links to **explain** pairs; without links, **strict** applies **HARD BLOCK** for D1, D4, D6, D2, D5.
3. **Reversal** of the **primary** posting clears the block for the **secondary** module (document in message).
4. **Settlement** never overrides harvest **in-kind** GL; settlement rules **warn** on draft harvests, **do not** block solely on share lines.

---

## 10. Acceptance checklist

| Requirement | § |
|-------------|---|
| Machinery: Field Job vs Charge vs Harvest in-kind | §4 |
| Labour: Field Job vs Lab Work Log vs Harvest | §5 |
| Harvest: share lines vs settlement; no-share fallback | §6 |
| Inventory: double issue; duplicate produce | §7 |
| `source_field_job_id`, `source_lab_work_log_id`, `source_machinery_charge_id` | §3, tables |
| HARD / SOFT / ALLOWED | §1, §7.1 |
| Tenant strict vs flexible | §2, §7.1 |
| High-risk paths D1–D10 covered | §8 |

---

## 11. Non-goals (this document)

- No code, migrations, or feature flags implemented.
- No automatic GL netting implementation spec beyond “required before relaxing HARD blocks on linked shares.”

---

*End of Phase 4B.1 enforcement rules design.*
