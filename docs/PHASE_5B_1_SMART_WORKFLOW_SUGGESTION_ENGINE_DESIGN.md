# Phase 5B.1 — Smart Workflow Suggestion Engine (Design)

**Status:** Design only (no implementation).  
**Domain:** `apps/api` (read-model, no posting).  
**Depends on:** Field jobs, harvests, share lines, `HarvestSharePreviewService` / `HarvestShareBucketService` (economic preview — **distinct** from this feature).

---

## Purpose

Provide **deterministic, side-effect-free suggestions** so the UI can pre-fill or highlight next steps when moving between workflows (e.g. field work → harvest → share structure). Users **always** confirm; nothing is posted automatically.

**Relationship to existing APIs:**

| Existing | Role |
|----------|------|
| `GET .../harvests/{id}/share-preview` | Computes **posted/draft economics** (WIP, bucket qty/value) for a given posting date — **not** a suggestion of *which* share lines to create. |
| **5B.1 suggestions** | Proposes **candidate lines, ratios, and links** (machine/labour/landlord) with **confidence** — read-only hints for forms. |

No duplication of posting math: suggestion engine may **call** bucket/preview services only to **validate** or **score** a candidate, not to replace them.

---

## 1. Suggestion types

| Type | Code | Typical payload | Use case |
|------|------|-----------------|----------|
| **Machine** | `machine` | `machine_id`, optional `usage_qty` / hours, `source_field_job_machine_id`, `rate_hint` | Pre-fill harvest-related machine share or explain linkage from field job machinery lines. |
| **Labour** | `labour` | `worker_id`, optional `units` / basis, `source_field_job_labour_id` | Pre-fill labour share or labour-related hints from field job labour lines. |
| **Share split** | `share_split` | Recipient role, `share_basis`, `share_value` / ratio / remainder flags, optional `beneficiary_party_id`, `source_*` links | Proposed `HarvestShareLine`-shaped rows (draft intent only). |

Each suggestion item is a **union** discriminated by `type` (see §3 JSON schema).

---

## 2. Data sources (read-only)

| Source | Used for |
|--------|----------|
| **FieldJob** (and lines: `FieldJobMachine`, `FieldJobLabour`, `FieldJobInput`) | Machine/labour → harvest: suggest which machines/workers “were part of” work on same `project_id` / `crop_cycle_id` / `job_date` near harvest date. |
| **Machine rate cards** (`MachineRateCard` + `MachineryRateResolver` rules) | Rate suggestion for machine usage; **not** posting. |
| **Historical harvests** | Same `crop_cycle_id` + `project_id` (and optionally same parcel): last **posted** harvest’s share line **structure** (roles, basis, ordering, not necessarily quantities). |
| **Harvest lines** (current harvest) | Scale or allocate suggested shares against line qty. |
| **Agreements** (if present) | e.g. land lease / revenue share: **optional** `agreement_snapshot_id` or party-specific rules — **feature-flagged**; safe fallback = ignore. |
| **Traceability fields** (`source_field_job_id`, etc.) | Prefer suggestions that **reuse** existing source links to avoid duplicate workflow (align with `DuplicateWorkflowGuard` semantics). |

**Determinism:** All inputs are **snapshot IDs + tenant + explicit query parameters** (see §3). No randomness; “last used” is **latest by `posted_at` / `harvest_date` with stable tie-break** (e.g. `id`).

---

## 3. API design

### 3.1 Primary endpoint (required by task)

```
GET /api/v1/crop-ops/harvests/{id}/suggestions
```

**Query parameters (all optional unless noted):**

| Param | Description |
|-------|-------------|
| `posting_date` | ISO date; aligns with share-preview when cross-checking WIP (default: harvest `harvest_date` or `today`). |
| `source_field_job_id` | If user is creating harvest “from” a field job — narrows machine/labour suggestions to that job’s lines. |
| `include_history` | `true|false` (default `true`) — include `last_harvest_pattern` block. |
| `include_agreements` | `true|false` (default `false`) — requires future agreement service. |

**Auth / modules:** Same as harvest read (`crop_ops` + tenant); **read-only**.

**Response structure (conceptual):**

```json
{
  "harvest_id": "uuid",
  "generated_at": "2026-04-10T12:00:00Z",
  "engine_version": "5b.1.0",
  "suggestions": [
    {
      "type": "machine",
      "confidence": "HIGH",
      "reason_codes": ["FIELD_JOB_LINE", "RATE_CARD_MATCH"],
      "machine_id": "uuid",
      "label": "Tractor A",
      "usage_reference": { "field_job_machine_id": "uuid", "usage_qty": "2.00" },
      "rate_hint": { "amount": "80.00", "currency": "GBP", "basis": "HOUR" },
      "warnings": []
    },
    {
      "type": "labour",
      "confidence": "MEDIUM",
      "reason_codes": ["FIELD_JOB_LINE", "SAME_DAY_WORKER"],
      "worker_id": "uuid",
      "label": "R. Singh",
      "usage_reference": { "field_job_labour_id": "uuid", "units": "1.0" }
    },
    {
      "type": "share_split",
      "confidence": "HIGH",
      "reason_codes": ["PREVIOUS_HARVEST_PATTERN"],
      "recipient_role": "MACHINE",
      "settlement_mode": "IN_KIND",
      "share_basis": "PERCENT",
      "share_value": "15",
      "machine_id": "uuid",
      "source_field_job_id": "uuid",
      "notes": "Mirrors harvest H-2024-001 share lines order"
    }
  ],
  "patterns": {
    "last_harvest_for_scope": {
      "harvest_id": "uuid",
      "harvest_no": "H-2024-002",
      "line_count": 3,
      "applies_to": { "crop_cycle_id": "uuid", "project_id": "uuid" }
    }
  },
  "fallback": {
    "empty": false,
    "message": null
  }
}
```

- **`suggestions`:** Ordered: **machine** → **labour** → **share_split** (or group by `priority` integer if needed).
- **`errors`:** Not used for “no data” — use empty `suggestions` + `fallback.empty: true` and optional `fallback.message`.

### 3.2 Optional companion endpoint (field job → harvest)

To support “start harvest from this job” without passing a long `source_field_job_id` in the client:

```
GET /api/v1/crop-ops/field-jobs/{id}/harvest-suggestions
```

Returns the same **suggestion item shapes** for machine/labour (and **optional** draft harvest header hints: `suggested_harvest_date`, `project_id`). Does **not** create a harvest.

**Implementation note:** Internally both endpoints delegate to a single **`WorkflowSuggestionService`** to avoid duplicate logic.

### 3.3 Machine usage / rate-only helper (optional, thin)

If the UI needs rate hints outside a harvest context:

```
GET /api/v1/machinery/suggestions/rate?machine_id=&project_id=&posting_date=
```

Returns `{ "rate_hint": { ... }, "confidence": "HIGH|MEDIUM|LOW" }` — **read-only**, wraps existing rate card resolution.

---

## 4. Confidence levels

| Level | Meaning | UI guidance |
|-------|---------|-------------|
| **HIGH** | Single unambiguous source (e.g. one field job line + rate card hit; or exact repeat of last harvest pattern for scope). | **Auto-fill** draft fields; still require explicit save/post. |
| **MEDIUM** | Multiple sources agree partially, or history match with one mismatch (e.g. different machine count). | **Suggest** — highlight fields; user edits. |
| **LOW** | Heuristic only (e.g. default landlord party from project) or missing agreement data. | **Optional** row; collapsed by default. |

**Mapping rules (deterministic examples):**

- Field job line + rate card resolved → machine rate **HIGH**.
- Field job labour line only (no history) → labour **MEDIUM**.
- Historical pattern with **identical** project + crop cycle + line count → share structure **HIGH**.
- Agreement module off → landlord **LOW** or omitted.

---

## 5. User override rules

1. **Suggestions are never authoritative:** API returns **candidates**; `POST/PATCH` harvest and share line endpoints remain unchanged and accept **any** valid payload per existing validation + `DuplicateWorkflowGuard`.
2. **UI must send explicit user choices:** Applying a suggestion = client copies values into forms; server does not store “accepted suggestion id” unless we add an optional **audit-only** field later (non-goal for 5B.1).
3. **Conflict with duplicate prevention:** If a suggestion would imply a duplicate workflow (e.g. second machine share without `source_field_job_id` when a posted FJ exists), include **`warnings[]`** on that item and **downgrade confidence** to **MEDIUM** or **LOW** — do not fail the GET.
4. **Overrides always win:** User clears or edits fields; no automatic re-fetch on same page unless user requests refresh.

---

## 6. Architectural invariants

| Invariant | Enforcement |
|-----------|-------------|
| Suggestions **never auto-post** | GET only; no writes, no queues. |
| User **must confirm** | All mutations via existing commands. |
| **No side effects** | No DB inserts/updates in suggestion handlers; optional in-memory cache with TTL is allowed later **only** for performance (same inputs → same output). |
| **No duplicate business logic** | One `WorkflowSuggestionService` (or domain-specific strategies behind it); rate resolution **delegates** to `MachineryRateResolver` / existing rate card code. |
| **Safe fallback** | Empty array + `fallback.empty: true` + short message when no field job, no history, no rate card. |

---

## 7. Acceptance criteria (design)

- [ ] Same tenant + same IDs + same query params → **same** JSON (deterministic; document `engine_version` when rules change).
- [ ] No second copy of harvest posting or share bucket math inside the suggestion engine — **reuse** or **call** preview services only for scoring/validation.
- [ ] When no data: **200** with empty suggestions and clear fallback — **not** 404.
- [ ] Accountants and operators see the same suggestion shapes; **role** may filter **labels** in UI only (optional), not API.

---

## 8. Non-goals

- ML training, external AI, or non-deterministic ranking.
- Implementing this document.
- Storing user “preference” rows (Phase 5A optional prefs) unless later specified.
- Auto-creating drafts for harvests or share lines on the server.

---

## 9. Backward compatibility

- New routes only; **no** change to existing `share-preview` response shape.
- Older clients ignore `GET .../suggestions` until adopted.

---

## 10. References

- `HarvestSharePreviewService`, `HarvestShareBucketService` — economic preview.
- `DuplicateWorkflowGuard` — warnings must align with duplicate rules.
- `OperationalTraceabilityService` — optional cross-links in suggestions (`source_*`).
