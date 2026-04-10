# Phase 3A.4 — Harvest Share / In-Kind Settlement: Operator-First Workflow

**Status:** Design only — no production code.  
**Domain:** `apps/web` (UX), `apps/api` (API contract assumptions).  
**Builds on:** `phase-3a-1` … `phase-3a-3`, and the existing **Field Job** document flow (`FieldJobDetailPage`, post/reverse, cost summary).

---

## 1. Where users define shares (question 1)

**Recommended: hybrid — “suggested defaults + edit on the harvest before post”.**

| Layer | Role |
|-------|------|
| **Templates / agreements** (machine rate cards, labour agreements, land lease / project rules, optional future `ShareRule`-like defaults) | **Pre-fill** suggested splits, recipient parties, and store hints. **Not** the legal source of truth for a specific physical harvest. |
| **Harvest document (DRAFT)** | **Single operator-facing place** where shares for **this** event are confirmed or edited. **Immutable snapshot** locked at **post** (same pattern as Field Job machine costing snapshots). |

**Rationale:** Operators think in **this truckload / this pick**; accountants need **one document** that matches the posted `HARVEST` posting group. Inheritance reduces typing; **harvest** remains the editable surface before post.

**Rejected as sole source:** Templates only (without per-harvest confirmation) — risk of wrong field application. Harvest form only with no defaults — unnecessary friction.

---

## 2. What the operator should see (question 2)

**Goal:** Complete the real-world event in **one pass** with **plain language**.

**Primary screen (harvest detail / edit):**

- **Total harvest quantity** per line (item + UOM) — already familiar.
- **Share breakdown** (per line or per document — product choice):  
  - **Machine share** (qty or % + optional linked machine)  
  - **Labour share** (qty or % + worker or crew)  
  - **Landlord / contractor** (qty or % + party)  
  - **Owner / project retained** — **computed**, not typed first: **net retained = total − Σ(shares)**.
- **Destination store** per bucket (or sensible default from template).
- **Plain-language summary strip:**  
  - “**Total harvested:** 10 bags”  
  - “**Allocated to others:** 1 bag (machine)”  
  - “**You keep:** 9 bags”  
- **Warnings (non-blocking where possible):** e.g. share sum ≠ total; missing store; machine share without Field Job in period (link to policy).

**Secondary:** Link to **related operational docs** (read-only): recent Field Jobs for same project/cycle, posted work logs — **for context**, not duplicate entry.

---

## 3. What the accountant should see (question 3)

**Goal:** **Auditability** without forcing operators through GL.

**Harvest detail (POSTED):**

- **Immutable snapshot** of shares (qty, %, value basis, beneficiaries) — mirror Field Job “rate snapshot / machinery cost” pattern.
- **Posting group** link (`/app/posting-groups/:id`).
- **Settlement / accrual hints** from design 3A.3: which slices **net** Field Job / work log (IDs in `rule_snapshot` when implemented).
- **Ledger impact summary** (read-only): “In-kind machinery settlement $X”, “WIP released $Y” — **derived from API**, not calculated in UI.

**Separate:** Standard **reports** and **settlement** screens unchanged; harvest in-kind does **not** replace periodic cash settlement unless policy says so — **disclose** in copy.

---

## 4. DRAFT vs POSTED visibility (question 4)

| Data | DRAFT | POSTED / REVERSED |
|------|-------|-------------------|
| Header (date, project, cycle) | Editable (today’s rules) | Read-only |
| Physical lines (item, qty, store) | Editable | Read-only |
| Share template / suggested defaults | Editable, refreshable | **Frozen snapshot** only |
| **Computed** retained qty / values | Live preview | **Posted snapshot** |
| Links to posting group | Hidden until posted | Visible |
| Reverse | N/A | Accountant (or role policy) |

**REVERSED:** Same read-only as posted + **reversal posting group** + clear banner (“Shares shown are historical; reversed on …”).

---

## 5. Old workflows: remain but secondary (question 5)

**Keep available, **do not** drive primary harvest-share flow:**

| Workflow | Positioning |
|----------|-------------|
| **Manual MachineryCharge** | “Cash or accrual machinery billing” — **separate** document. UI warns if harvest share **+** charge could **double-settle** same service window (future validation). |
| **Periodic cash settlement** (`SettlementService`) | “Pool profit distribution” — **not** the same as **produce** share at harvest; help text distinguishes **bags** vs **money**. |
| **Field Job** | **Operational cost** of work performed — operators continue to use it; harvest share **references** it only for **netting** (3A.3), not duplicate entry. |

**Hidden:** Nothing **removed**; **primary nav** emphasizes **Harvest → define shares → post** for in-kind output.

---

## 6. UI copy concepts (question 6)

| Term | Operator-facing copy |
|------|----------------------|
| **Total harvest quantity** | “Total brought in” / “Total recorded on this harvest” |
| **Owner share / retained** | “Left for the project / owner” / “Your crop after shares” |
| **Machine share** | “Paid to machinery as crop” / “Machinery’s portion (in bags/kg)” |
| **Labour share** | “Workers’ portion of this harvest” |
| **Landlord / other** | Use party name + “share of this harvest” |
| **Net retained quantity** | **Bold** number: “**Net you keep: X**” = total − shares |

**Accountant labels:** Retain technical terms in secondary tooltips (allocation type, `CROP_WIP`, accrual IDs).

---

## 7. Recommended operator workflow (end-to-end)

1. **Create harvest** (project, date, cycle) — existing flow.  
2. **Add lines** (item, qty, store) — existing.  
3. **Open “Output shares”** section: apply **defaults** from templates where configured; **adjust** qty/% and recipients.  
4. **Review summary** strip (total / shared / retained).  
5. **Post harvest** — explicit confirm modal (like Field Job): “This will record inventory and, where set, settle shares in the accounts.”  
6. **Done** — optional PDF/export later; **no second document** for the same physical event.

**Exception path:** If machinery/labour **must** be cash-only, **omit** produce share for that bucket and use existing charge/work log flows — **documented** choice.

---

## 8. Recommended accountant / audit workflow

1. **Review queue:** Posted harvests with `share_snapshot_version` / flags.  
2. **Open harvest** → verify snapshot vs **posting group** → drill into **ledger lines** (read-only).  
3. **Trace** Field Job / work log links when in-kind netting applies (3A.3).  
4. **Reverse** if wrong — single reverse action (existing pattern); **no** partial reverse of shares without full harvest reverse (until product supports otherwise).

---

## 9. Source-of-truth rules

| Truth | System |
|-------|--------|
| **Physical event** | One **Harvest** id per posting event. |
| **Posted shares** | **Snapshot** on harvest (or child rows) **at post**; identical to what **`HARVEST` posting group** used. |
| **Accounting** | **Posting group** + **allocation_rows** + **ledger_entries** (immutable). |
| **No duplicate settlement** | Same crop/service window: **either** produce share on harvest **or** duplicate cash charge — **validation** layer (future) enforces exclusivity or explicit override. |

---

## 10. Draft / post / reverse lifecycle UX

| State | Operator | Accountant |
|-------|----------|------------|
| **DRAFT** | Edit all allowed fields; see live preview | May review draft if shared |
| **POST** | Modal: date, idempotency key (if API exposes); success → read-only | Audit trail available |
| **REVERSE** | Usually **not** operator (configurable); reason + date | Full access; confirms reversal mirrors design 3A.2 |

**Copy:** Align with Field Job: “Posted — read-only”; “Reversed — read-only”.

---

## 11. Validation UX rules (client + server)

- **Σ share qty ≤ line qty** (or **=** if policy is full allocation); **last bucket = remainder** (3A.2).  
- **UOM consistency** across buckets on a line.  
- **Store** required per bucket that **receives** inventory.  
- **Warn** if machine share &gt; 0 and **no** Field Job in cycle (policy: soft vs hard).  
- **Block** post if **double-settlement** risk flags (future API `warnings[]`).  
- **Idempotency:** Same as harvest post today — show **posting id** after success.

---

## 12. Suggested API shape (future — contract hint)

**Extend `GET/PUT` harvest** (or sub-resource `.../share-snapshot`):

```json
{
  "share_snapshot_status": "NONE | DRAFT | POSTED",
  "lines": [
    {
      "harvest_line_id": "uuid",
      "total_quantity": "10",
      "buckets": [
        { "role": "OWNER", "quantity": "9", "store_id": "uuid" },
        { "role": "MACHINE", "quantity": "1", "store_id": "uuid", "machine_id": "uuid", "settles_field_job_id": null }
      ]
    }
  ],
  "preview": {
    "total_harvest_qty_by_line": [],
    "net_retained_qty_by_line": [],
    "wip_value_estimate": null
  }
}
```

**POST response:** include **`share_snapshot`** echo + **`posting_group`** summary links for UI.

*(Exact schema to be finalized with 3A.1–3 backend design.)*

---

## 13. Screen / file plan (later implementation)

### Web (`apps/web`)

| Area | Files (existing → extend) |
|------|---------------------------|
| Harvest list | `src/pages/harvests/HarvestsPage.tsx` — optional column “Shares” / status |
| Harvest create | `src/pages/harvests/HarvestFormPage.tsx` |
| Harvest detail | `src/pages/harvests/HarvestDetailPage.tsx` — **Output shares** section, summary strip, DRAFT/POSTED behavior |
| Components | `src/components/harvests/` (new) — `HarvestShareEditor`, `HarvestShareSummary`, `NetRetainedBanner` |
| Types | `src/types/index.ts` — `HarvestShareBucket`, `HarvestShareSnapshot` |
| API | `src/api/harvests.ts` — CRUD for snapshot if split endpoint |

### API (`apps/api`)

| Area | Files (future) |
|------|----------------|
| Requests | `StoreHarvestRequest`, share validation |
| Service | `HarvestService::post` extension; snapshot persistence |
| Docs | OpenAPI / internal doc update |

---

## 14. Duplicate-entry risks (explicit)

| Risk | Mitigation |
|------|------------|
| Harvest share **+** MachineryCharge for same service | Warning + optional **link** Field Job PG id; block if **already settled** flag. |
| Harvest share **+** cash settlement for **same** profit line | Product copy: different purposes; reporting tags. |
| Two harvests **same** physical grain | Operational discipline + **unique doc_no** / weighbridge id (future optional field). |

---

## 15. Acceptance checklist

- [ ] Farm manager can **complete harvest + shares** without opening GL.  
- [ ] Accountant can **audit** from harvest → posting group → snapshots.  
- [ ] **One event, one document** for produce share posting.  
- [ ] Field Job remains the **cost** document; harvest remains the **output** document; relationship is **linked**, not duplicated.

---

## 16. Related docs

- `phase-3a-1-harvest-share-in-kind-settlement.md`  
- `phase-3a-2-harvest-share-inventory-valuation.md`  
- `phase-3a-3-harvest-share-accounting.md`

---

*End of note.*
