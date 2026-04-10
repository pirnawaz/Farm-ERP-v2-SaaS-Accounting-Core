# Phase 6A.1 — Agreements Engine (Design)

**Status:** Design only (no implementation).  
**Domain:** `apps/api` (operational economics + harvest workflows).  
**Builds on:** Phase 3 harvest share lines, `SuggestionService` (Phase 5C), field job / machinery rate resolution, existing **Land Lease** domain (`App\Domains\Operations\LandLease\*`) for accrual-style leases.

---

## Purpose

Introduce **formal agreements** that define how **output** and **costs** are shared, so the system can move from **implicit** rules (history, field-job lines alone) to **explicit**, queryable terms. Agreements **inform** draft UX (`SuggestionService`), **validate** draft share lines and costing assumptions, and supply **read-only inputs at posting time**; **posted documents store snapshots** and never depend on live agreement edits.

**Relationship to Land Lease (existing):** The existing `LandLease` model handles lease documents, parcels, and accrual posting. **`LAND_LEASE` agreement type** in this engine is the **operational projection** of “what share / rent applies to a given project or crop cycle for harvest and costing,” either by **linking** to a `land_lease_id` or by **standalone** terms when the legal object lives elsewhere. This design does not require merging the two models on day one; it requires a **clear mapping** in implementation phases.

---

## 1. Agreement types

| Code | Purpose | Primary subjects | Drives |
|------|---------|------------------|--------|
| `LAND_LEASE` | Landlord share of output and/or fixed / stepped rent | `party_id` (landlord), optional `land_parcel_id` | Harvest share lines (landlord bucket), optional cash rent accrual hints |
| `MACHINE_USAGE` | How machine time or output share is compensated | `machine_id` (and optional operator / contractor `party_id`) | Machinery charges, field-job machine lines, harvest machine shares |
| `LABOUR` | Wage, piece rate, or output share for workers | `worker_id` (lab worker) and/or `party_id` | Field-job labour lines, harvest labour shares, labour payables |

Each agreement row is **one effective policy** for a scope; composite deals may require **multiple rows** with priorities (see §5–6).

---

## 2. Core fields (conceptual schema)

| Field | Notes |
|-------|--------|
| `id` | UUID |
| `tenant_id` | Required |
| `agreement_type` | `LAND_LEASE` \| `MACHINE_USAGE` \| `LABOUR` |
| `project_id` | Optional; narrows to a field cycle |
| `crop_cycle_id` | Optional; narrows to a season |
| `party_id` | Optional; landlord, contractor, or other counterparty |
| `machine_id` | Required when type is `MACHINE_USAGE` (nullable otherwise) |
| `worker_id` | Required when type is `LABOUR` (nullable otherwise) |
| `terms` | JSON (validated per type; see §3) |
| `effective_from` | Date (inclusive) |
| `effective_to` | Date (inclusive) or null = open-ended |
| `priority` | Integer; **higher wins** when overlapping (see §4) |
| `status` | e.g. `DRAFT` \| `ACTIVE` \| `SUPERSEDED` \| `CANCELLED` — only `ACTIVE` participates in resolution |
| `source_land_lease_id` | Optional FK to existing `LandLease` when `LAND_LEASE` is derived from legal lease |
| `notes` | Free text |
| `created_at` / `updated_at` | Audit |

**Scope rule:** At least one of (`project_id`, `crop_cycle_id`) should be set for farm-wide operational use; global tenant defaults are possible but discouraged without explicit `crop_cycle_id` to avoid accidental broad application.

---

## 3. Term structures (`terms` JSON)

All monetary amounts use **tenant currency** unless `terms.currency` is explicitly added later. Structures are **discriminated** by `mode` (or `basis`).

### 3.1 Shared primitives

- **Percent share:** `{ "basis": "PERCENT", "percent": "12.5" }` (of harvest line qty or of cost pool — see consumer docs per workflow).
- **Fixed quantity:** `{ "basis": "FIXED_QTY", "qty": "100", "uom": "BAG" }`.
- **Ratio:** `{ "basis": "RATIO", "numerator": "1", "denominator": "10" }` (interpreted relative to line or pool per consumer).
- **Fixed rate (money):** `{ "basis": "FIXED_RATE", "amount": "80.00", "rate_unit": "HOUR" | "DAY" | "HECTARE" | "UNIT" }`.
- **Output share (machine/labour):** `{ "basis": "OUTPUT_SHARE", "percent": "5" }` or ratio variant — maps to harvest share semantics (`HarvestShareLine` basis enums).

**Validation:** Server-side JSON schema per `agreement_type` + `terms.basis`; reject unknown keys for the type.

### 3.2 By agreement type (examples only)

- **LAND_LEASE:** May combine `rent` (fixed / stepped) and `harvest_share` (percent / ratio / fixed qty). Example: `{ "rent": { "basis": "FIXED_RATE", "amount": "5000", "period": "YEAR" }, "harvest_share": { "basis": "PERCENT", "percent": "15" } }`.
- **MACHINE_USAGE:** e.g. `{ "pricing": { "basis": "FIXED_RATE", "amount": "45", "rate_unit": "HOUR" } }` or `{ "output_share": { "basis": "PERCENT", "percent": "8" } }` for in-kind settlement.
- **LABOUR:** e.g. `{ "wage": { "basis": "FIXED_RATE", "amount": "120", "rate_unit": "DAY" } }` or `{ "piece_rate": { "amount": "2.50", "per_unit": "BAG" } }` or `{ "output_share": { "basis": "RATIO", "numerator": "1", "denominator": "20" } }`.

---

## 4. Conflict rules

### 4.1 Multiple agreements for the same entity

- **Same machine + overlapping dates:** Resolve by **`priority` (desc)**, then **`effective_from` (desc)** — highest priority wins; lower-priority rows are ignored for that window unless explicitly modeled as “fallback” (optional `fallback: true` in `terms` in a later iteration).
- **Same worker / same landlord:** Same hierarchy.
- **Explicit override:** Optional future field `supersedes_agreement_id` for audit trail (non-goal for 6A.1 implementation).

### 4.2 Overlapping dates

- Treat `[effective_from, effective_to]` as **closed intervals**; `effective_to = null` means “until superseded or cancelled.”
- Two agreements **overlap** if ranges intersect. **No overlap** → no conflict.
- **Tie-break** (same priority + same dates): deterministic **`id` ascending** last resort (documented; avoid in operations).

### 4.3 Cross-type conflicts

- Landlord **percent** vs machine **output share** both apply to the **same harvest line** → they are **not** mutually exclusive; allocation order follows **harvest share bucket ordering** (Phase 3) and **remainder** rules. Agreements **do not** replace `HarvestShareLine` math; they **seed** lines and **constrain** totals.

---

## 5. Integration points

| Consumer | Role |
|----------|------|
| **`SuggestionService`** | Resolve **active** agreements for `(harvest.project_id, harvest.crop_cycle_id, date)` and emit suggestion rows with `reason_codes: ['AGREEMENT']` and `agreement_id` in payload (design extension to Phase 5C response shape). Historical patterns become **fallback** when no agreement matches. |
| **Harvest share generation (draft)** | When user “Apply from agreements,” create **draft** `HarvestShareLine` candidates from `LAND_LEASE` / machine / labour agreements; user still confirms. |
| **Harvest posting** | Load agreements **as of `posting_date`** (read-only); persist `rule_snapshot` / `agreement_snapshot_id` on share lines or harvest (aligned with Phase 3C snapshot patterns). |
| **Field job costing (optional)** | `MACHINE_USAGE` / `LABOUR` agreements inform **expected** rates or share splits when building field-job lines; `MachineryRateResolver` may take **optional agreement id** as tie-break over generic rate card. |

**Read-only at posting:** Posting services receive **frozen** `terms` snapshots attached to the document or pulled by id + `as_of` date that match the draft; no live update to agreement rows during post.

---

## 6. Priority hierarchy (resolution order)

1. **Explicit agreement** (ACTIVE, in scope, date-effective, highest `priority`).
2. **Historical pattern** (existing `SuggestionService` “previous harvest” templates).
3. **Rate card / default resolver** (machinery).
4. **Manual input** (user-entered lines with no agreement link).

Document this order in API responses as `resolution_source` per suggestion line (future field).

---

## Architectural invariants

| Invariant | Enforcement |
|-----------|-------------|
| Agreements are **read-only at posting time** | Posting reads snapshot or version id; does not `UPDATE` agreement rows. |
| **Snapshots stored at post** | `HarvestShareLine.rule_snapshot` / posting metadata includes agreement id + terms hash or embedded terms. |
| **No retroactive change to posted data** | Editing an agreement never mutates posted harvests or field jobs; reversals follow existing reversal flows. |
| **Tenant isolation** | All queries scoped by `tenant_id`. |

---

## Acceptance criteria (design)

- [ ] Supports **percent**, **fixed qty**, **ratio**, and **fixed money rate** across the three agreement types.
- [ ] **Overlaps** and **multiple rows** resolved deterministically via **priority** + dates.
- [ ] Clear hand-off to **`SuggestionService`** and harvest share draft generation without duplicating Phase 3 posting math.
- [ ] Coexists with **existing Land Lease** domain via optional `source_land_lease_id` or documented bridge.
- [ ] Posting path remains **snapshot-based** and auditable.

---

## Non-goals (6A.1)

- Database migrations, models, or APIs.
- UI for agreement authoring.
- Legal document storage or e-signatures.
- Automatic posting from agreements without user confirmation.

---

## References

- Phase 3 harvest share docs (`phase-3a-*`, `phase-3c-*`).
- `SuggestionService` (Phase 5C).
- `MachineryRateResolver` / `MachineRateCard`.
- `DuplicateWorkflowGuard` — agreements do not bypass duplicate operational checks.
- `App\Domains\Operations\LandLease\LandLease` — legal lease entity.
