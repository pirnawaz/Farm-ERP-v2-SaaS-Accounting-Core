/**
 * Farm-first UI terminology. Accounting terms are used as secondary hints only.
 * Do not use for: API params, types, query keys, or backend identifiers.
 */

export type TermKey =
  | 'postingGroup'
  | 'reversalPostingGroup'
  | 'ledgerEntries'
  | 'allocationRows'
  | 'postAction'
  | 'reverseAction'
  | 'postActionPending'
  | 'reverseActionPending'
  | 'trialBalance'
  | 'generalLedger'
  | 'arAgeing'
  | 'profitAndLoss'
  | 'balanceSheet'
  // Project-like record (UI only; domain entity remains Project)
  | 'fieldCycle'
  | 'fieldCycles'
  | 'confirmPost'
  | 'confirmReverse'
  // Inventory (farm-first)
  | 'grn'
  | 'grnSingular'
  | 'issue'
  | 'issueSingular'
  | 'transfer'
  | 'transferSingular'
  | 'adjustment'
  | 'adjustmentSingular'
  | 'stockOnHand'
  | 'stockMovements'
  | 'inventoryItem'
  | 'inventoryItemSingular'
  | 'inventoryCategory'
  | 'inventoryCategorySingular'
  // Crop Ops (farm-first)
  | 'activityType'
  | 'activityTypeSingular'
  | 'activities'
  | 'newActivity'
  // Sprint 14: operational records & sidebar
  | 'pendingReview'
  | 'operationalRecord'
  | 'operationalRecordPlural'
  // Farm-first nav & labels
  | 'navFarm'
  | 'navPeople'
  | 'navMoney'
  | 'navAccounting'
  | 'navSettings'
  | 'navDomainFarm'
  | 'navDomainOperations'
  | 'navDomainFinance'
  | 'navDomainGovernance'
  | 'navDomainSettings'
  | 'navFields'
  | 'navWork'
  | 'navPayReceive'
  | 'todayOnFarm'
  | 'moneySnapshot'
  | 'reviewQueue'
  | 'seasonSnapshot'
  | 'noActiveSeason'
  | 'createCropCycleCta'
  | 'quickActions'
  | 'seasonSetup'
  | 'assignFieldsToSeason'
  | 'fieldBlocks'
  | 'advancedSetup';

export const TERMS: Record<TermKey, { farm: string; accounting: string }> = {
  postingGroup: { farm: 'Posted Transaction', accounting: 'Posting Group' },
  reversalPostingGroup: { farm: 'Reversal Transaction', accounting: 'Posting Group reversal' },
  ledgerEntries: { farm: 'Accounting Lines', accounting: 'Ledger Entries' },
  allocationRows: { farm: 'Cost Split', accounting: 'Allocation Rows' },
  postAction: { farm: 'Post to Accounts', accounting: 'POST' },
  reverseAction: { farm: 'Reverse Posting', accounting: 'REVERSE' },
  postActionPending: { farm: 'Posting...', accounting: 'Post' },
  reverseActionPending: { farm: 'Reversing...', accounting: 'Reverse' },
  trialBalance: { farm: 'Trial Balance', accounting: 'Trial Balance' },
  generalLedger: { farm: 'Account Activity', accounting: 'General Ledger' },
  arAgeing: { farm: 'Overdue Customers', accounting: 'AR Ageing' },
  profitAndLoss: { farm: 'Farm Profit', accounting: 'Profit & Loss' },
  balanceSheet: { farm: 'Farm Position', accounting: 'Balance Sheet' },
  fieldCycle: { farm: 'Field Cycle', accounting: 'Project' },
  fieldCycles: { farm: 'Field Cycles', accounting: 'Projects' },
  confirmPost: { farm: 'Confirm Post to Accounts', accounting: 'Confirm Post' },
  confirmReverse: { farm: 'Confirm Reverse Posting', accounting: 'Confirm Reverse' },
  grn: { farm: 'Goods Received', accounting: 'GRNs' },
  /** Document label (list row, toasts, detail title before doc no.) */
  grnSingular: { farm: 'Goods Received', accounting: 'GRN' },
  issue: { farm: 'Stock Used', accounting: 'Issues' },
  issueSingular: { farm: 'Stock used', accounting: 'Issue' },
  transfer: { farm: 'Transfer Stock', accounting: 'Transfers' },
  transferSingular: { farm: 'Stock transfer', accounting: 'Transfer' },
  adjustment: { farm: 'Adjust Stock', accounting: 'Adjustments' },
  adjustmentSingular: { farm: 'Stock adjustment', accounting: 'Adjustment' },
  stockOnHand: { farm: 'Current Stock', accounting: 'Stock On Hand' },
  stockMovements: { farm: 'Stock History', accounting: 'Stock Movements' },
  inventoryItem: { farm: 'Items', accounting: 'Items' },
  inventoryItemSingular: { farm: 'Item', accounting: 'Item' },
  inventoryCategory: { farm: 'Categories', accounting: 'Categories' },
  inventoryCategorySingular: { farm: 'Category', accounting: 'Category' },
  activityType: { farm: 'Work Types', accounting: 'Activity Types' },
  activityTypeSingular: { farm: 'Work Type', accounting: 'Activity Type' },
  activities: { farm: 'Field Work', accounting: 'Activities' },
  newActivity: { farm: 'Log Field Work', accounting: 'New Activity' },
  pendingReview: { farm: 'Drafts (Unposted)', accounting: 'Unposted Transactions' },
  operationalRecord: { farm: 'Farm record', accounting: 'Transaction' },
  operationalRecordPlural: { farm: 'Farm records', accounting: 'Transactions' },
  navFarm: { farm: 'FARM', accounting: 'Farm Operations' },
  navPeople: { farm: 'PEOPLE', accounting: 'People' },
  navMoney: { farm: 'MONEY', accounting: 'Money' },
  navAccounting: { farm: 'ACCOUNTING', accounting: 'Finance & Review' },
  navSettings: { farm: 'SETTINGS', accounting: 'Admin' },
  navDomainFarm: { farm: 'Farm', accounting: 'Farm' },
  navDomainOperations: { farm: 'Operations', accounting: 'Operations' },
  navDomainFinance: { farm: 'Finance', accounting: 'Finance' },
  navDomainGovernance: { farm: 'Governance', accounting: 'Governance' },
  navDomainSettings: { farm: 'Settings', accounting: 'Settings' },
  // `/app/projects` is a field-season record, not a physical land unit.
  navFields: { farm: 'Field Cycles', accounting: 'Projects' },
  navWork: { farm: 'Work', accounting: 'Crop Ops' },
  navPayReceive: { farm: 'Pay & Receive', accounting: 'Payments' },
  todayOnFarm: { farm: 'Today on the farm', accounting: 'Today' },
  moneySnapshot: { farm: 'Money snapshot', accounting: 'Cash & Dues' },
  reviewQueue: { farm: 'Review Queue', accounting: 'Review Queue' },
  seasonSnapshot: { farm: 'Season snapshot', accounting: 'Season Snapshot' },
  noActiveSeason: { farm: 'No active season yet. Create a crop cycle to track this season.', accounting: 'No active crop cycle.' },
  createCropCycleCta: { farm: 'Create crop cycle', accounting: 'New crop cycle' },
  quickActions: { farm: 'Quick actions', accounting: 'Quick actions' },
  seasonSetup: { farm: 'Season setup', accounting: 'Crop cycle setup' },
  assignFieldsToSeason: { farm: 'Add fields to season', accounting: 'Assign fields' },
  fieldBlocks: { farm: 'Field blocks', accounting: 'Projects (costing)' },
  advancedSetup: { farm: 'Advanced setup (split fields / multiple crops)', accounting: 'Advanced' },
};

export function term(k: TermKey): string {
  return TERMS[k]?.farm ?? k;
}

export function termWithHint(k: TermKey): string {
  const t = TERMS[k];
  if (!t) return k;
  return `${t.farm} (${t.accounting})`;
}

export function accountingTerm(k: TermKey): string {
  return TERMS[k]?.accounting ?? k;
}
