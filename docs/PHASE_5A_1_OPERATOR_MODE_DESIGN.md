# Phase 5A.1 — Operator Mode Design (Terrava)

**Status:** Design only (no implementation in this phase).  
**Depends on:** Phases 1–4 (including duplicate prevention, traceability UI, and operator nav pruning in `nav.ts`).  
**Principles:** Operator-first UX, linear workflows, **no changes to accounting posting logic** on the API, **role-based visibility only**, **additive rollout**.

---

## 1. Operator navigation

### 1.1 Goals

- A **farm operator** should complete daily work using three mental pillars: **Field Jobs** (work), **Harvests** (output + sharing), **Sales** (commercialisation).
- **Machinery charges**, **labour work logs**, **inventory issues (stock used)**, and **manual settlement** are **not** part of the default operator story; they remain available to **accountants** and **tenant admins** for control and exception handling.
- Navigation must make the **primary path obvious** and push alternates behind **Advanced** or hide them entirely for operators.

### 1.2 Proposed sidebar structure (Operator role)

Domains and sections below reflect **intent** for Phase 5A.1. Item keys should map to existing `nav.ts` keys unless noted as **new**.

| Domain | Section / group | Items (in order) | Notes |
|--------|------------------|------------------|--------|
| **Farm** | *(flat)* | Farm Pulse, Today, Alerts | Unchanged. |
| **Operations** | **Primary ops** *(new group label or reorder)* | **Crop Ops Overview**, **Field Jobs**, **Harvests** | Single “spine” for day-to-day ops. Sidebar hints (Phase 4D): “Use Field Jobs to record work”, “Use Harvest to record output and sharing”. |
| | Land & Crops | Land Parcels, Land Allocation, Land Leases, Crop Cycles, Fields, Orchards/Livestock *(if addons)*, Production Units *(if applicable)* | Setup/planning; keep visible but below primary ops. |
| | **Crop Ops** *(secondary)* | Work Types, Draft Entries | **Hide** “Field Work Logs” for operators (alternate path to work). |
| | Machinery | Machinery Overview, Machines, Service History, Maintenance Jobs, Maintenance Setup, Rate Cards | **Hide** Machine Usage, **Machinery Charges** (advanced). |
| | Inventory | Overview, Current Stock, Stock History, Goods Received, Transfers, Adjustments, Items, Categories, Units, Stores | **Hide** Stock Used (issues). GRNs remain for receiving. |
| | People & Workforce | Labour Overview, Workers, Payables, People & Partners | **Hide** Labour Work Logs. |
| **Finance** | **Sales & money** *(operator slice)* | **Sales & Money** (`/app/sales`) | **Required for goal:** operators must reach sales without opening full accounting. *Today `sales` may be missing from operator allow-list — add `sales` to operator pruning allow set.* |
| | Treasury *(optional demote)* | Payments, Advances | Keep if operators record customer payments in practice; otherwise demote under “More” or hide per tenant policy (future). |
| | *(rest of Finance)* | — | **Hidden** for operators: full Accounting Overview, journals, GL, most reports, reconciliation, etc. *(Current behaviour: operators see Finance domain with a subset — align list with “Sales + minimal treasury”.)* |
| **Settings** | — | **Hidden** for operators *(if not already)* | Only tenant admins manage users/modules. |

**Exact structure (bullet tree for implementation reference):**

```
Farm
  Farm Pulse
  Today
  Alerts

Operations
  [Primary ops]
    Crop Ops Overview
    Field Jobs          ← sidebar hint
    Harvests            ← sidebar hint
  Land & Crops
    … (land / cycles / fields …)
  Crop Ops (secondary)
    Work Types
    Draft Entries
  Machinery
    Machinery Overview
    Machines
    Service History
    (omit: Machine Usage, Machinery Charges)
    Maintenance Jobs
    Maintenance Setup
    Rate Cards
  Inventory
    … (omit: Stock Used)
  People & Workforce
    Labour Overview
    Workers
    Payables
    (omit: Labour Work Logs)
    People & Partners

Finance  [operator slice]
  Sales & Money
  [optional] Payments
  [optional] Advances
```

### 1.3 Modules visible vs hidden (Operator)

| Module / area | Operator | Rationale |
|---------------|----------|-----------|
| `crop_ops` | ✅ Yes | Field Jobs + Harvests. |
| `ar_sales` | ✅ Yes | Sales & Money entry point. |
| `inventory` | ✅ Yes (subset) | Receiving, stock visibility, transfers; **not** manual “stock used” as default. |
| `machinery` | ✅ Yes (subset) | Fleet and maintenance; **not** charges or standalone usage as default. |
| `labour` | ✅ Yes (subset) | Workers and payables context; **not** standalone work logs. |
| `land`, `projects_crop_cycles`, etc. | ✅ As today | Planning. |
| Full `reports` / accounting-heavy screens | ❌ No | Accountant / admin. |
| `settlements` | ❌ No * in nav* | Avoid manual settlement path for operators; settlement remains in system for accountants. |

\* Routes may still exist for deep links; navigation does not advertise them.

---

## 2. Workflow mapping (canonical paths)

| Intent | Canonical path | Operator-facing label | Alternate paths (forbidden in operator mental model) |
|--------|----------------|------------------------|------------------------------------------------------|
| Record work (labour + machinery + inputs) | **Field Job** → draft → post | “Field job” | Machinery work logs; labour work logs; duplicate entries on same day/worker/machine as already enforced by API. |
| Record output and how it is shared | **Harvest** → lines → **share lines** → post | “Harvest” | Manual settlement packs as the *default* way to allocate output; harvest share lines replace that for shared crop output. |
| Sell produce / receive money | **Sales** (`/app/sales`) and related AR flows | “Sales & Money” | Using inventory issue alone to “move” stock for economic effect without a sale document when a sale is the real event (discouraged). |

**No alternate paths (operator UX):**

- Do **not** present machinery charges or machine usage as a parallel way to “do machinery” for field work — Field Job subsumes it.
- Do **not** present labour work logs as parallel to field job labour lines.
- Do **not** present “Stock Used” as the primary way to consume inputs for field work — Field Job inputs do that.

**Backend correctness:** Unchanged. `DuplicateWorkflowGuard` and existing posting services remain authoritative.

---

## 3. Role behaviour

| Capability | Operator | Accountant | Tenant admin |
|------------|----------|------------|----------------|
| Primary nav (Field Jobs, Harvests, Sales) | Full visibility (when modules enabled) | Full + hints optional | Full |
| Advanced ops (machinery charges, usage logs, labour logs, stock issues) | **Hidden** from nav | Visible | Visible |
| Finance / GL / reports / reconciliation | **Hidden** or minimal | Full | Full |
| Settings / users / modules | **Hidden** (typical) | As per permissions | Full |
| Direct URL to advanced route | May 403 or see page with **Advanced workflow** banner (Phase 4D) | Full | Full |

**Security:** Navigation pruning is **not** a security boundary; API middleware and module guards remain unchanged.

---

## 4. UI simplification rules

### 4.1 Hide (operators)

- Sidebar: machinery **Machine Usage**, **Machinery Charges**, **Labour Work Logs**, **Field Work Logs** (crop activities), **Stock Used** (inventory issues).
- Finance: accounting overview, journals, GL, heavy reports — match current operator finance pruning.

### 4.2 Demote

- **Crop Ops Overview**, **Work Types**, **Draft Entries** — keep accessible but **below** Field Jobs / Harvests.
- Machinery maintenance and rate cards — secondary, not “daily”.
- Inventory **Goods Received** vs **Adjustments** — operational but not part of the three-pillar story; keep in inventory without starring.

### 4.3 Show as “Advanced”

- Any screen reached via bookmark or support link for: machinery charges, usage logs, labour logs, inventory issues — show **Advanced workflow** banner (Phase 4D) pointing to Field Jobs and Harvests.
- Optional future: collapsible **Advanced** section in sidebar for operators only when `VITE_*` or tenant flag allows “power user” operators (non-goal for 5A.1).

### 4.4 Emphasise (operators)

- **Field Jobs** and **Harvests** at top of Operations with persistent short hints.
- **Sales & Money** as the first Finance item for operators once `sales` is in allow list.

---

## 5. Required API changes (if any)

**Accounting logic:** **None.**

**Recommended additive API / config (optional, for later phases):**

| Change | Purpose |
|--------|---------|
| None | Operator Mode is primarily **web nav + UI affordances**; roles already exist (`operator`, `accountant`, `tenant_admin`). |
| Optional later: `GET /api/v1/me/preferences` or tenant feature flag `operator_mode_strict` | Toggle “hide advanced” vs “demote only” per tenant without code forks. |
| Optional later: expose `traceability` blocks everywhere (Phase 4E) | Already read-only; no posting change. |

**Web:** Ensure operator allow list includes **`sales`** (nav key `sales`) when `ar_sales` is enabled — **gap vs current `operatorAllowItemKeys`** to close during implementation.

---

## 6. Backward compatibility plan

1. **Default off for new behaviour:** Ship nav changes behind a single feature flag (e.g. `operator_mode_v5` or reuse role-based pruning only) so tenants can validate.
2. **Accountants and admins:** Unchanged full navigation; no removal of routes or APIs.
3. **Operators:** Stricter pruning is **additive** to existing Phase 4D pruning; only extends hidden/demoted items and adds Sales to primary flow.
4. **Bookmarks / integrations:** Old URLs continue to work; pages may show banners, not hard blocks.
5. **Mobile / future apps:** Same role claims; Operator Mode is a **presentation contract**, not a new user type.

---

## 7. Acceptance criteria (design-level)

- [ ] Operator can describe their day as: **Field Jobs → Harvests → Sales** without referencing charges, logs, or issues.
- [ ] No duplicate workflow is *prominent* in navigation; backend enforcement remains (Phase 4C).
- [ ] Accountant retains full access to advanced operational and accounting surfaces.
- [ ] No requirement to change ledger, posting groups, or duplicate guards for this phase.

---

## 8. Non-goals (Phase 5A.1)

- New database tables or new roles.
- Changing settlement or harvest posting logic.
- Replacing accountant workflows with operator workflows.
- Mobile app implementation.

---

## 9. References (internal)

- `apps/web/src/config/nav.ts` — `pruneDomainsForRole`, `operatorAllowItemKeys`, `accountantAllowItemKeys`.
- Phase 4D UI: `AdvancedWorkflowBanner`, sidebar hints on Field Jobs / Harvests.
- Phase 4C: `DuplicateWorkflowGuard`.
- Phase 4E: `OperationalTraceabilityService`, `traceability` on detail APIs.
