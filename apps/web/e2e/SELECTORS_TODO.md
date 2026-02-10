# E2E required data-testid and selectors

Add these hooks so E2E tests can run reliably. Prefer `data-testid`; fallbacks are listed where used.

## Login ([LoginPage.tsx](src/pages/LoginPage.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=role` | Role dropdown | Or keep `id="role"` as fallback |
| `data-testid=login-submit` | Continue button | Or `button:has-text("Continue")` |
| `data-testid=tenant-row` (optional) | Each tenant row `<tr>` | Optional: add `data-tenant-id={tenant.id}` for deterministic selection |

## App shell ([AppLayout.tsx](src/components/AppLayout.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=app-sidebar` or `data-testid=app-layout` | Sidebar or main layout | Used to assert app shell visible after login |

## Transaction detail ([TransactionDetailPage.tsx](src/pages/TransactionDetailPage.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=status-badge` | Status span (DRAFT/POSTED) | |
| `data-testid=post-btn` | Post Transaction button | |
| `data-testid=posting-date-modal` | Modal container | Or on Modal component |
| `data-testid=posting-date-input` | Posting date input | type=date |
| `data-testid=confirm-post` | Confirm Post button in modal | |
| `data-testid=posting-group-panel` or link | Posting group link after post | |
| `data-testid=create-correction-btn` | Correction/reversal trigger if present | |

## Machinery service detail ([MachineryServiceDetailPage.tsx](src/pages/machinery/MachineryServiceDetailPage.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=status-badge` | Status span | |
| `data-testid=post-btn` | Post button | |
| `data-testid=posting-date-modal` | Post modal | |
| `data-testid=posting-date-input` | Posting date input | |
| `data-testid=confirm-post` | Confirm Post in modal | |
| `data-testid=posting-group-id` | Posting group link | |
| `data-testid=create-correction-btn` or Reverse | Reverse button when posted | |

## Modal ([Modal.tsx](src/components/Modal.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=posting-date-modal` | Root of modal (when title is posting) | Or pass testid from parent |
| `data-testid=posting-date-input` | date input inside modal | Or from parent |
| `data-testid=confirm-post` | Primary submit in modal | Or from parent |

## Posting group detail ([PostingGroupDetailPage.tsx](src/pages/PostingGroupDetailPage.tsx))

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=posting-group-panel` | Posting Group Information section | |
| `data-testid=allocation-rows-table` | Allocation Rows table | |
| `data-testid=ledger-entries-table` | Ledger Entries table | |
| `data-testid=create-correction-btn` | Reverse button | |

## Toasts (react-hot-toast)

| Selector | Element | Notes |
|----------|--------|------|
| `data-testid=toast-success` | Success toast | App may need to set in Toaster or toast options |
| `data-testid=toast-error` | Error toast | |

If the app does not add these, tests use fallbacks: e.g. `.toast`, `[data-sonner-toast]`.
