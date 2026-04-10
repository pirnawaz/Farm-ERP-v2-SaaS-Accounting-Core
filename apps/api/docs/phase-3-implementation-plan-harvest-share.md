# Phase 3 — Harvest Share / In-Kind Settlement: Execution Plan

**Status:** Implementation plan only — **no production code in this document**.  
**Synthesizes:** `phase-3a-1` … `phase-3a-4` (see `docs/phase-3a-*.md`).  
**Domains:** `apps/api`, `apps/web`.

---

## 1. Chosen architecture summary

| Decision | Choice |
|----------|--------|
| **Source event** | One **`Harvest`** document → one **`PostingGroup`** (`source_type = HARVEST`, `source_id = harvest.id`). |
| **Share definition** | **Immutable snapshot** at post (hybrid: templates pre-fill; operator edits on harvest in DRAFT). |
| **Inventory** | **Split inbound** — multiple **`HARVEST`** `applyMovement` rows per bucket/store; **WIP layer** unit cost; **last bucket** absorbs **value** remainder per line (3A.2). |
| **Accounting** | **Layer 1:** Dr `INVENTORY_PRODUCE` / Cr `CROP_WIP` (capitalization). **Layer 2 (in-kind):** P&amp;L pairs per 3A.3 (e.g. internal machine: Dr `MACHINERY_SERVICE_INCOME` / Cr `EXP_SHARED`; labour: Dr `WAGES_PAYABLE` / Cr `LABOUR_EXPENSE`; landlord per accrual pattern). **All in same PG** unless a future exception is explicitly approved. |
| **Settlement** | **Do not** use `PROFIT_DISTRIBUTION_CLEARING` / `PARTY_CONTROL_*` on harvest — those remain **`SETTLEMENT`** PG only. |
| **Field Job** | Operational cost document; harvest **links** for netting via `rule_snapshot` — **no duplicate** Field Job for same physical settlement. |
| **Rollout** | **Additive:** default path = **current** harvest behavior (no shares = 100% owner bucket as today). |

---

## 2. Schema plan (Phase 3B)

**Goal:** Persist draft share intent and posted snapshot without breaking existing `harvests` / `harvest_lines` consumers.

**Recommended tables (exact names TBD in migration PR):**

| Table | Purpose |
|-------|---------|
| **`harvest_share_lines`** (or nested JSON on `harvests` — prefer **normalized** lines for audit) | Per harvest **line** + **bucket**: `tenant_id`, `harvest_id`, `harvest_line_id`, `sort_order`, `role` (OWNER \| MACHINE \| LABOUR \| LANDLORD \| CONTRACTOR), `beneficiary_party_id` nullable, `machine_id` nullable, `worker_id` nullable, `store_id`, `quantity`, `valuation_basis` enum (`WIP_LAYER`), optional `settles_posting_group_id` (FK to Field Job PG), `amount_snapshot` nullable until post, `is_remainder_bucket` bool. |
| **`harvests`** columns (minimal) | `share_snapshot_version` int, `share_mode` enum (`NONE` \| `SIMPLE` \| `FULL`) optional — or infer from line count. |

**Constraints:**

- Σ bucket `quantity` per `harvest_line_id` = `harvest_lines.quantity` at post (validate in service).
- FKs: `harvest_id`, `harvest_line_id` tenant-scoped.

**Non-goals (3B):** No posting logic; no UI; optional **seed** data only if needed for dev.

---

## 3. Posting plan (Phase 3C)

**Goal:** Extend `HarvestService::post` (and helpers) to:

1. Compute **existing** `totalWipCost` + **per-line** `allocateCost` (unchanged).
2. **Split each line** into buckets from `harvest_share_lines` (or default single bucket = full line to existing store).
3. For each bucket: **Dr `INVENTORY_PRODUCE`**, movement `HARVEST`, **Cr `CROP_WIP`** total unchanged — **sum of Dr lines = sum of layer values**; **one** Cr `CROP_WIP` = `round(totalWipCost, 2)`.
4. **Allocation rows:**  
   - Capitalization: `HARVEST_PRODUCTION` or new types (`HARVEST_OWNER_RETAINED`, `HARVEST_IN_KIND_*`) per 3A.3 — **must** include `rule_snapshot` with `harvest_line_id`, `bucket_role`, `share_snapshot_version`.
5. **In-kind P&amp;L lines** (when enabled and policy satisfied): emit **balanced** Dr/Cr for each share type per 3A.3; **never** duplicate `CROP_WIP` credit.
6. **Idempotency:** Keep `PostingIdempotencyService` + existing keys.
7. **`LedgerWriteGuard`:** Extend `config/ledger_write_allowlist.php` **only** if new classes are introduced; prefer keeping **`HarvestService`** as single writer.

**Small additive fix (same phase or first 3C PR):** Add **`FIELD_JOB`** to `SettlementService::OPERATIONAL_SOURCE_TYPES` so project profit / settlement preview includes Field Job P&amp;L (known gap from 3A.1).

**Files (primary):**

- `app/Services/HarvestService.php` — core logic split into private methods: `buildShareBuckets`, `postCapitalizationMovements`, `postInKindSettlementLedger`, `validateShareLines`.
- `app/Models/Harvest.php`, new `HarvestShareLine` model.
- `app/Http/Controllers/HarvestController.php` + new **Form Requests** for share payload on create/update or dedicated `PUT …/share-lines`.
- `database/migrations/*_harvest_share_tables.php`.

**Non-goals (3C):** No web UI; no `LabWorkerBalance` auto-adjust (document follow-up); no automatic reversal of Field Job — **ledger pair** only.

---

## 4. Reversal plan (Phase 3C, same release as posting)

**Goal:** One **full** reversal of the harvest posting group.

1. **`HarvestService::reverse`** — already calls `ReversalService::reversePostingGroup` + negates stock movements.
2. **After posting extension:** Ensure **every** `inv_stock_movement` and **ledger** line created at harvest post (including in-kind P&amp;L) is attached to the **same** `posting_group_id` so reversal **nets to zero** without orphan lines.
3. **If** ISSUE-in-same-PG is added later: reverse **inverse order** or document that `ReversalService` + movement replay order is sufficient (3A.2).
4. **Harvest status:** `REVERSED`, `reversal_posting_group_id` set — unchanged contract.

**Acceptance:** Reversal integration test: post with shares → reverse → ledger + stock + allocation sums zero for affected accounts.

---

## 5. Reporting update plan (Phase 3C or 3D)

| Area | Change |
|------|--------|
| **`SettlementService`** | Add `FIELD_JOB` to `OPERATIONAL_SOURCE_TYPES`; verify harvest share **allocation types** appear in any **breakdown-by-type** queries if added. |
| **`ReportController`** | Harvest qty/cost reports: join **new** allocation types / `rule_snapshot` for per-bucket cost; **backward compatible** with old harvests (single bucket). |
| **Machine profitability** | Filter or tag **in-kind** Dr `MACHINERY_SERVICE_INCOME` vs cash Field Job — document **report** filters using `allocation_type` / `rule_snapshot`. |

**Non-goals:** New dashboard; full **machine profitability** redesign.

---

## 6. Frontend / API plan (Phase 3E)

**API:**

- Extend `GET /api/v1/crop-ops/harvests/{id}` with **share lines** (draft) or **posted snapshot** (read-only).
- `PUT` or nested `POST` for share lines while `DRAFT`.
- Post body unchanged except optional **confirm** flags / `idempotency_key` already supported.
- Response: include **`share_snapshot`**, **`preview`** (`net_retained_qty`, warnings array), **`ledger_summary`** read-only when posted.

**Web:**

| File | Action |
|------|--------|
| `src/pages/harvests/HarvestDetailPage.tsx` | Output shares section, summary strip, POSTED read-only |
| `src/pages/harvests/HarvestFormPage.tsx` | Optional default share mode |
| `src/components/harvests/*` | New: `HarvestShareEditor`, `HarvestShareSummary`, `NetRetainedBanner` |
| `src/api/harvests.ts` | CRUD share payload |
| `src/types/index.ts` | `HarvestShareBucket`, extend `Harvest` |

**Non-goals:** Mobile-specific UI; machinery charge screens redesign.

---

## 7. Rollout plan

| Stage | Action |
|-------|--------|
| **Dev** | Feature flag optional: `HARVEST_SHARE_ENABLED` (config) default false until QA. |
| **Staging** | Migrate; seed project with share lines; run regression + manual UAT checklist. |
| **Prod** | Enable flag; **existing** harvests unaffected (no share rows = legacy path). |
| **Communicate** | Release notes: operators use harvest for in-kind; accountants see posting group + snapshot. |

---

## 8. Regression test plan (Phase 3D)

| Suite | Scope |
|-------|--------|
| **Existing** | `tests/Feature/HarvestTest.php` — **must stay green** with default no-share path. |
| **New** | Feature tests: post with 2 buckets (owner + machine); ledger balance; stock balance; reversal nets zero. |
| **New** | In-kind P&amp;L: Field Job posted first → harvest nets machinery slice (mock or full flow). |
| **New** | Labour / landlord minimal cases if implemented in 3C. |
| **Settlement** | Assert `FIELD_JOB` in operational profit query after list fix. |
| **FieldJob** | No regression on `FieldJobMachineryCostingRegressionTest` / existing machinery tests. |

**Non-goals:** E2E Playwright in 3D unless already standard for project.

---

## 9. Phased build order

### Phase 3B — Schema & models

**Deliverables:** Migrations, Eloquent models, factories for tests, **no posting**.

| File boundaries (typical) |
|---------------------------|
| `database/migrations/*_create_harvest_share_lines_table.php` |
| `app/Models/HarvestShareLine.php` |
| `app/Models/Harvest.php` — relationship `shareLines()` |
| `database/factories/HarvestShareLineFactory.php` (if project uses factories) |

**Acceptance criteria:**

- [ ] Migrations run up/down clean on Postgres.
- [ ] Model relationships and tenant scoping validated in unit test or minimal feature test.
- [ ] Existing harvest create/update **unchanged** without calling new APIs.

**Non-goals:** HarvestService post changes; API contract for shares; UI.

---

### Phase 3C — Posting & reversal & reporting hooks

**Deliverables:** `HarvestService` post/reverse extended; validation; allocation + ledger + stock per 3A.2/3A.3; `FIELD_JOB` in settlement list; `ledger_write_allowlist` unchanged if logic stays in `HarvestService`.

| File boundaries (typical) |
|---------------------------|
| `app/Services/HarvestService.php` |
| `app/Http/Controllers/HarvestController.php` |
| `app/Http/Requests/*Harvest*Request.php` |
| `routes/api.php` (if new routes) |
| `app/Services/SettlementService.php` — `OPERATIONAL_SOURCE_TYPES` |
| `config/ledger_write_allowlist.php` — only if new service class |

**Acceptance criteria:**

- [ ] Default harvest (no share rows or single owner bucket) **matches** current behavior (golden test or diff on ledger movement counts).
- [ ] Post with shares: stock + ledger + allocation assertions.
- [ ] Reversal nets to zero.
- [ ] Crop cycle lock / posting date guards still enforced.

**Non-goals:** Web UI; `LabWorkerBalance` sync; duplicate-charge blocking UI.

---

### Phase 3D — Tests & hardening

**Deliverables:** Comprehensive feature tests; fix edge cases from 3C.

| File boundaries |
|-----------------|
| `tests/Feature/HarvestSharePostingTest.php` (new) |
| `tests/Feature/HarvestTest.php` (extend) |
| `tests/Feature/SettlementServiceFieldJobInclusionTest.php` (new, small) or fold into existing |

**Acceptance criteria:**

- [ ] CI green on full suite or agreed subset.
- [ ] Idempotency: second post with same key returns same PG.
- [ ] Reversal idempotency preserved.

**Non-goals:** Frontend; performance load tests.

---

### Phase 3E — Frontend & API polish

**Deliverables:** Operator UX per 3A.4; types; API alignment.

| File boundaries |
|-----------------|
| `apps/web/src/pages/harvests/*` |
| `apps/web/src/components/harvests/*` |
| `apps/web/src/api/harvests.ts` |
| `apps/web/src/types/index.ts` |
| `apps/api` — only if API tweaks discovered in integration |

**Acceptance criteria:**

- [ ] Operator can enter shares in DRAFT and see net retained.
- [ ] POSTED read-only + posting group link.
- [ ] Accountant can audit snapshot without GL math in browser.

**Non-goals:** Template management UI for share rules; mobile app.

---

## 10. Exact file boundaries summary (all phases)

| Layer | Files |
|-------|-------|
| **DB** | `database/migrations/*harvest_share*` |
| **Models** | `Harvest.php`, `HarvestShareLine.php`, `HarvestLine.php` (touch if FK) |
| **Service** | `HarvestService.php` (major) |
| **Controller / Requests** | `HarvestController.php`, new Form Requests |
| **Settlement** | `SettlementService.php` |
| **Reports** | `ReportController.php` (harvest sections) |
| **Config** | `ledger_write_allowlist.php` (conditional) |
| **Tests** | `tests/Feature/Harvest*.php`, new share tests |
| **Web** | `HarvestDetailPage.tsx`, `HarvestFormPage.tsx`, `components/harvests/*`, `api/harvests.ts`, `types/index.ts` |

---

## 11. Acceptance criteria (cross-phase)

- [ ] **One harvest → one posting group** for all layers.
- [ ] **Immutable** posted snapshots; reversal restores state.
- [ ] **Project** `project_id` on all allocation rows.
- [ ] **Additive** — legacy harvests without share infrastructure still post.
- [ ] **No duplicate** source document for the same produce event (documented validation roadmap for charge vs harvest).

---

## 12. Non-goals (global)

- Deprecating MachineryCharge, Field Job, or cash settlement.
- Full **operator** redesign outside harvest.
- **Lot/serial** tracking per bag.
- **Multi-currency** harvest share (unless already in tenant).

---

## 13. Reference index

| Phase 3A doc | Topic |
|--------------|--------|
| `phase-3a-1-harvest-share-in-kind-settlement.md` | Scope, gaps, architecture |
| `phase-3a-2-harvest-share-inventory-valuation.md` | Movements, WAC, rounding |
| `phase-3a-3-harvest-share-accounting.md` | GL, allocations, netting |
| `phase-3a-4-harvest-share-operator-workflow.md` | UX, API shape hints |

---

*End of implementation plan.*
