import type { UserRole } from '../../types';

export type WidgetKey =
  | 'open_crop_cycles'
  | 'draft_transactions'
  | 'projects_count'
  | 'cash_balance'
  | 'ar_balance'
  | 'ap_balance'
  | 'trial_balance_link'
  | 'recent_postings'
  | 'inventory_alerts'
  | 'work_logs_summary'
  | 'setup_status'
  | 'users_count'
  | 'modules_status'
  | 'onboarding_panel';

export interface QuickAction {
  label: string;
  to: string;
  variant?: 'primary' | 'secondary' | 'outline';
  requiredModule?: string;
}

export interface DashboardConfig {
  primaryWidgets: WidgetKey[];
  secondaryWidgets: WidgetKey[];
  quickActions: QuickAction[];
}

const configs: Record<UserRole, DashboardConfig> = {
  tenant_admin: {
    primaryWidgets: [
      'open_crop_cycles',
      'projects_count',
      'cash_balance',
      'ar_balance',
      'ap_balance',
    ],
    secondaryWidgets: [
      'draft_transactions',
      'users_count',
      'modules_status',
      'setup_status',
    ],
    quickActions: [
      { label: 'New Transaction', to: '/app/transactions/new', requiredModule: 'projects_crop_cycles' },
      { label: 'New Payment', to: '/app/payments/new', requiredModule: 'treasury_payments' },
      { label: 'New Sale', to: '/app/sales/new', requiredModule: 'ar_sales' },
      { label: 'Trial Balance', to: '/app/reports/trial-balance', requiredModule: 'reports' },
      { label: 'Settings', to: '/app/settings/localisation' },
    ],
  },
  accountant: {
    primaryWidgets: [
      'cash_balance',
      'ar_balance',
      'ap_balance',
      'trial_balance_link',
      'recent_postings',
    ],
    secondaryWidgets: [
      'draft_transactions',
      'open_crop_cycles',
      'projects_count',
    ],
    quickActions: [
      { label: 'Trial Balance', to: '/app/reports/trial-balance', requiredModule: 'reports' },
      { label: 'General Ledger', to: '/app/reports/general-ledger', requiredModule: 'reports' },
      { label: 'Cashbook', to: '/app/reports/cashbook', requiredModule: 'reports' },
      { label: 'New Payment', to: '/app/payments/new', requiredModule: 'treasury_payments' },
      { label: 'Account Balances', to: '/app/reports/account-balances', requiredModule: 'reports' },
    ],
  },
  operator: {
    primaryWidgets: [
      'open_crop_cycles',
      'draft_transactions',
      'inventory_alerts',
      'work_logs_summary',
    ],
    secondaryWidgets: [
      'projects_count',
    ],
    quickActions: [
      { label: 'New Transaction', to: '/app/transactions/new', requiredModule: 'projects_crop_cycles' },
      { label: 'New Activity', to: '/app/crop-ops/activities/new', requiredModule: 'crop_ops' },
      { label: 'New Work Log', to: '/app/labour/work-logs/new', requiredModule: 'labour' },
      { label: 'New GRN', to: '/app/inventory/grns/new', requiredModule: 'inventory' },
      { label: 'View Projects', to: '/app/projects', requiredModule: 'projects_crop_cycles' },
    ],
  },
  platform_admin: {
    primaryWidgets: [
      'open_crop_cycles',
      'projects_count',
      'draft_transactions',
    ],
    secondaryWidgets: [],
    quickActions: [
      { label: 'View Projects', to: '/app/projects', requiredModule: 'projects_crop_cycles' },
      { label: 'Reports', to: '/app/reports', requiredModule: 'reports' },
    ],
  },
};

export function getDashboardConfig(role: UserRole | null): DashboardConfig {
  if (!role || !configs[role]) {
    // Default fallback for unknown roles
    return {
      primaryWidgets: ['open_crop_cycles', 'draft_transactions', 'projects_count'],
      secondaryWidgets: [],
      quickActions: [
        { label: 'View Projects', to: '/app/projects', requiredModule: 'projects_crop_cycles' },
      ],
    };
  }
  return configs[role];
}
