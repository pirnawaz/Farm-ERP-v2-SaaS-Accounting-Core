# Phase 3A.2 — Harvest Share: Inventory Movement & Valuation (technical note)

**Status:** Design only — no migrations or production code.  
**Companion:** `phase-3a-1-harvest-share-in-kind-settlement.md` (scope and architecture); `phase-3a-3-harvest-share-accounting.md` (GL / allocations).  
**Focus:** Concrete valuation and `inv_stock_movements` behavior for produce-share posting.

---

## 0. Current codebase behavior (audit)

### 0.1 HARVEST stock movement

- `HarvestService::post` calls `InventoryStockService::applyMovement` **once per `harvest_line`** with:
  - `movement_type`: **`HARVEST`**
  - `qty_delta`: **positive** line `quantity` (string)
  - `value_delta`: **positive** `round($allocatedCost, 2)` for that line
  - `unit_cost_snapshot`: **`$allocatedCost / $quantity`** rounded to **6** decimal places (via `(string) round($unitCost, 6)`)
  - `posting_group_id`: the single **`HARVEST`** posting group
  - `source_type` / `source_id`: **`harvest`** / **`harvest.id`**

- **Allocated cost** comes from `allocateCost($totalWipCost, $lines)`: proportional by **line quantity**; non-last lines use `round(..., 2)`; **last line absorbs remainder** so the sum of allocated costs equals `totalWipCost` exactly.

- **`HarvestLine`** does not persist `unit_cost_snapshot` or `line_total` today (unlike `InvIssueLine` after issue post). The audit trail for cost per line is **`inv_stock_movements`** + **`allocation_rows.rule_snapshot`**.

### 0.2 Valuation snapshot

- There is **no** separate “NRV” or market field on harvest. Valuation at receipt is **fully determined** by:
  1. **Net `CROP_WIP`** for the crop cycle up to posting date (`calculateWipCost`), and  
  2. **Proportional split** across harvest lines.

- Ledger: Dr **`INVENTORY_PRODUCE`** per line (if cost &gt; 0.001), one Cr **`CROP_WIP`** for **total** WIP released (rounded 2 dp). Small rounding differences between sum of Dr lines and Cr are possible in edge cases; the **cost allocation** is line-consistent with movements.

### 0.3 ISSUE stock movement (contrast)

- `InventoryPostingService::postIssue` issues **`INVENTORY_INPUTS`** (inputs category): `applyMovement` with **`ISSUE`**, **negative** qty and **negative** value.
- **Unit value** = **current `inv_stock_balances.wac_cost`** at issue time × qty (`line_total`), **not** the cost at original GRN. Snapshot stores that **WAC** string on the movement.

### 0.4 WAC / balance update

- `applyMovement` always: `newQty = balance.qty + qty_delta`, `newValue = balance.value + value_delta`, `new WAC = newValue / newQty` (or 0 if qty 0).
- **Reversal** (`HarvestService::reverse`): replays movements with **negated** `qty_delta` and `value_delta`, **same** `unit_cost_snapshot` as original row — balance returns to prior state if no other activity.

### 0.5 One `PostingGroup`, multiple movements

- **Yes.** Harvest already attaches **multiple** `inv_stock_movements` rows to the **same** `posting_group_id` (one per line). There is no engine limitation preventing **multiple stores/items** or additional movement types in that group.

---

## 1. Recommended inventory movement model

**Chosen pattern: “split inbound + optional ISSUE-out in the same posting group”**

1. **Compute** total WIP to capitalize and **per–harvest-line** cost using the **existing** `allocateCost` logic (unchanged contract: last line remainder on **money**).

2. **Split each physical harvest line** into **share buckets** (owner retained, machine, labour, landlord, contractor, …) with **deterministic quantities** (see §3). Each bucket has:
   - `store_id` (where the stock **lands**),
   - `qty`,
   - **value** = `round(unit_layer_cost * qty, 2)` with **last bucket on that line** absorbing **value** remainder so line value matches allocated cost for that line.

3. **Inbound:** For each bucket, call `applyMovement` with `movement_type = HARVEST`, **positive** qty/value, `unit_cost_snapshot = value/qty` (6 dp), **same** `posting_group_id`, `source_type = harvest`, `source_id = harvest.id`.  
   - **Multiple HARVEST rows per line** are normal (e.g. 9 bags store A, 1 bag store B).

4. **If a share is not physically stored** (off-inventory settlement — see §7 rejected options): **do not** create positive inbound for that bucket; recognize only via ledger (out of scope for *inventory integrity* in the strict sense — not recommended as default).

5. **Optional same-PG outbound:** If a share is **stored then immediately** transferred to a beneficiary without staying in project store, model **`ISSUE`** (produce) or **`TRANSFER`** in the **same** posting group **after** all inbounds, using **layer WAC = the unit cost just established** for that lot (see §2), so issue value **matches** inbound value for that bucket (no silent WAC drift at split).

**Why not “only owner inventory + ledger-only for others” as default?** Operators and accountants need **obvious** traceability: bags **moved** and **valued** consistently. Ledger-only for non-owners hides quantity from stock reports and breaks “no silent mutation” unless heavily disclosed.

---

## 2. Recommended valuation model

**Single unambiguous basis (default): layered **harvest unit cost** from WIP**

- For each harvest line *i*:  
  - `allocated_cost_i` = output of `allocateCost` (already money-tied to WIP pool).  
  - `unit_cost_i = allocated_cost_i / qty_i` (use a **high-precision** internal value; store **6 dp** on movements as today).

- For each **share bucket** *k* on line *i* with quantity `q_ik`:  
  - `value_ik = round(unit_cost_i * q_ik, 2)` for all but the **last bucket** on that line;  
  - **last bucket** gets `allocated_cost_i - sum(previous value_ik)` so **Σ_k value_ik = allocated_cost_i** exactly.

**Source valuation basis (business decision 1):**

| Basis | Definition | Use |
|-------|------------|-----|
| **Primary (recommended)** | **Implicit WIP transfer value** per unit at post, as implemented today, extended to buckets. | Default for all examples below. |
| **Override (optional later)** | Fixed **price per kg/bag** on share snapshot for in-kind **statutory** or contract value. | Requires explicit field and policy; **not** mixed silently with WIP in one posting without documentation. |

**Determinism:** All inputs are fixed at post: WIP pool, line qtys, share split snapshot → **unique** movements and values.

**ISSUE from project store after split:** Use **bucket’s** `unit_cost_snapshot` (the layer just posted), **not** the store’s **blended** WAC if the system would otherwise mix lots — for a **single** harvest post, prefer **issue at layer cost** encoded on the movement to avoid rounding drift (same pattern as harvest: explicit snapshot per movement).

---

## 3. Rounding policy

### 3.1 Quantities (business decisions 2 & 3)

- **Storage:** Use **harvest line quantity precision** already on `harvest_lines.quantity` (**`decimal(18,3)`** in migration). Share qtys should use the **same** scale unless UOM requires finer (then extend schema explicitly).

- **Percent shares:** Compute **raw** `qty * pct`, then:
  - **Round each non-owner bucket** to allowed precision (e.g. 3 dp),
  - **Owner / designated “residual holder”** gets **`line_qty - sum(others)`** so **exact** reconciliation with physical line qty.

- **Integer bags:** Express shares in **whole units** where business requires; **remainder bags** go to **owner** (or rule-stated party).

### 3.2 Money

- **Per-bucket value:** round to **2 dp** with **last bucket on the line** absorbing **cents** remainder (mirrors `allocateCost`).

### 3.3 Residuals from ratios

- **Never** leave fractional orphans: **one** residual bucket per line (recommended: **project owner / operator retained**).

---

## 4. Reversal policy

- **Single** `ReversalService::reversePostingGroup(original_harvest_pg_id)` negates **ledger** and **allocation** rows.

- **Inventory:** For **every** `inv_stock_movement` row with `posting_group_id = original_harvest_pg`, replay `applyMovement` with **negated** `qty_delta` and `value_delta`, **same** `unit_cost_snapshot`, **same** `movement_type`, **same** store/item/source — as **today** in `HarvestService::reverse`.

- **Net effect:** Stock and value return to pre-harvest state for those layers; **no** separate manual reversal per bucket if all were in one PG.

- **If** future code adds ISSUE in same group: reversal order must be **inverse** of post (issues reversed before inbound, or engine replays all movements in reverse order — implementation detail; **net** must be exact).

---

## 5. Worked examples

### 5.1 Ten bags, one bag machine share (integer bags)

- **Line:** 10 bags, `allocated_cost` for line = **$500** → `unit_cost = $50.000000`/bag.

| Bucket | Qty | Value (2 dp) |
|--------|-----|----------------|
| Machine | 1 | 50.00 |
| Owner (residual) | 9 | 450.00 |

- **Movements:**  
  - `HARVEST` +1 bag, +$50 to **machine store** (or project “machine” sub-store).  
  - `HARVEST` +9 bags, +$450 to **owner store**.  
- **Ledger/in-kind** (elsewhere in design): recognize settlement at **$50** machine / **$450** retained — inventory already reflects bags.

### 5.2 1000 kg, 2.5% labour share

- **Line:** 1000.000 kg, say `allocated_cost = $12,000` → **$12/kg** layer cost.

- Labour share qty = **25.000 kg** (exact). Owner residual **975.000 kg**.

| Bucket | Qty (kg) | Value |
|--------|----------|--------|
| Labour | 25.000 | round(12×25, 2) = **300.00** |
| Owner | 975.000 | **12,000 − 300 = 11,700.00** (remainder) |

- Two `HARVEST` movements (or store split as needed). No floating-point drift on money.

### 5.3 Multiple recipients + remainder to owner

- **Line qty 100**; shares: Machine **10%**, Landlord **15%**, Labour **5%** → **70%** owner residual.

| Party | Qty |
|-------|-----|
| Machine | 10 |
| Landlord | 15 |
| Labour | 5 |
| Owner | 70 |

- Value per bucket: `round(unit_cost × qty, 2)` for Machine, Landlord, Labour **in fixed order**; **Owner** gets **line allocated cost − sum(three)**.

**Order rule (must be fixed in code):** e.g. alphabetical by role or **explicit priority** in snapshot — **last** bucket is always **remainder** for **both** qty and value on that line.

---

## 6. DB fields likely required on share rows (conceptual)

*Not implemented in this step; for implementation alignment.*

| Field | Purpose |
|-------|---------|
| `harvest_id` | Parent harvest |
| `harvest_line_id` | Physical line being split |
| `recipient_role` | enum: OWNER, MACHINE, LABOUR, LANDLORD, CONTRACTOR, … |
| `beneficiary_party_id` | nullable for internal pool |
| `store_id` | Where inbound lands |
| `quantity` | Share qty (matches UOM) |
| `value_amount` | Rounded 2 dp; or computed at post only |
| `valuation_basis` | `WIP_LAYER` \| `FIXED_PRICE` (future) |
| `unit_cost_snapshot` | 6 dp at post |
| `sort_order` / `remainder_bucket` | bool — which line gets penny/last qty fix |
| `posted_posting_group_id` | FK after post (often harvest PG) |

---

## 7. Tradeoffs rejected (and why)

| Option | Rejection reason |
|--------|------------------|
| **A. Only owner stock; non-owner = off-inventory** | Hides physical entitlement from **stock** reports; “silent” from inventory perspective; harder audit unless ledger is heavily labeled. |
| **B. Inbound 100% then ISSUE to recipients using blended WAC** | **Blended WAC** can differ from **layer** cost if other activity hits the store before issue; breaks **deterministic** harvest valuation unless issue uses **explicit** snapshot (then equivalent to modeled split). |
| **C. Separate posting group per recipient** | Violates **one source event → one posting group** for the harvest run; reversal and idempotency become harder. |
| **D. Market price as default without WIP link** | Introduces **duplicate** profit recognition vs operational costs unless carefully reconciled with `CROP_WIP` release. |

---

## 8. Auditability for operators & accountants

- **Single harvest document** + **one posting group** + **movement list** export: every bag/kg traceable to **store** and **$** with **`unit_cost_snapshot`**.
- **Allocation `rule_snapshot`** should echo: `harvest_line_id`, `recipient_role`, `bucket_index`, `valuation_basis: WIP_LAYER`, and **remainder** flags.
- **No silent changes:** all mutations are **posted** movements with **immutable** snapshots; reversals are **full** negations.

---

## 9. Acceptance checklist (for implementers)

- [ ] Valuation basis documented in code enum: **WIP_LAYER** default.  
- [ ] Qty remainder to **one** designated bucket per line.  
- [ ] Money remainder to **last** value bucket per line.  
- [ ] All buckets sum to line **qty** and **allocated cost**.  
- [ ] Reversal nets **each** movement to zero.  
- [ ] No reliance on **store WAC** for the **first** issue off a fresh harvest layer without explicit snapshot.

---

*End of note.*
