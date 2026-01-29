import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { StatCard } from '../../components/StatCard';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useOperationalTransactions } from '../../hooks/useOperationalTransactions';
import { useProjects } from '../../hooks/useProjects';
import { useStockOnHand } from '../../hooks/useInventory';
import { useWorkLogs } from '../../hooks/useLabour';
import { useFormatting } from '../../hooks/useFormatting';
import { apiClient } from '@farm-erp/shared';
import type { AccountBalanceRow } from '@farm-erp/shared';
import type { WidgetKey } from './dashboardConfig';

interface DashboardWidgetProps {
  widgetKey: WidgetKey;
  isModuleEnabled: (key: string) => boolean;
}

export function DashboardWidget({ widgetKey, isModuleEnabled }: DashboardWidgetProps) {
  switch (widgetKey) {
    case 'open_crop_cycles':
      if (!isModuleEnabled('projects_crop_cycles')) return null;
      return <OpenCropCyclesWidget />;
    case 'draft_transactions':
      if (!isModuleEnabled('projects_crop_cycles')) return null;
      return <DraftTransactionsWidget />;
    case 'projects_count':
      if (!isModuleEnabled('projects_crop_cycles')) return null;
      return <ProjectsCountWidget />;
    case 'cash_balance':
      if (!isModuleEnabled('reports')) return null;
      return <CashBalanceWidget />;
    case 'ar_balance':
      if (!isModuleEnabled('reports')) return null;
      return <ARBalanceWidget />;
    case 'ap_balance':
      if (!isModuleEnabled('reports')) return null;
      return <APBalanceWidget />;
    case 'trial_balance_link':
      if (!isModuleEnabled('reports')) return null;
      return <TrialBalanceLinkWidget />;
    case 'recent_postings':
      if (!isModuleEnabled('reports')) return null;
      return <RecentPostingsWidget />;
    case 'inventory_alerts':
      if (!isModuleEnabled('inventory')) return null;
      return <InventoryAlertsWidget />;
    case 'work_logs_summary':
      if (!isModuleEnabled('labour')) return null;
      return <WorkLogsSummaryWidget />;
    case 'setup_status':
      return <SetupStatusWidget />;
    case 'users_count':
      return <UsersCountWidget />;
    case 'modules_status':
      return <ModulesStatusWidget />;
    case 'onboarding_panel':
      return null; // Handled separately in DashboardPage
    default:
      return null;
  }
}

function OpenCropCyclesWidget() {
  const { data: cropCycles, isLoading } = useCropCycles();
  const openCyclesCount = useMemo(
    () => cropCycles?.filter((c) => c.status === 'OPEN').length || 0,
    [cropCycles]
  );

  if (isLoading) return <LoadingSpinner />;
  return <StatCard title="Open Crop Cycles" value={openCyclesCount} link="/app/crop-cycles" />;
}

function DraftTransactionsWidget() {
  const { data: transactions, isLoading } = useOperationalTransactions({ status: 'DRAFT' });
  const draftCount = transactions?.length || 0;

  if (isLoading) return <LoadingSpinner />;
  return <StatCard title="Draft Transactions" value={draftCount} link="/app/transactions?status=DRAFT" />;
}

function ProjectsCountWidget() {
  const { data: projects, isLoading } = useProjects();
  const projectsCount = projects?.length || 0;

  if (isLoading) return <LoadingSpinner />;
  return <StatCard title="Projects" value={projectsCount} link="/app/projects" />;
}

function CashBalanceWidget() {
  const { formatMoney } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 30 * 1000, // 30 seconds - dashboard data
    gcTime: 2 * 60 * 1000,
  });

  const balance = useMemo(() => {
    if (!accountBalances) return 0;
    const cashAccount = accountBalances.find((row: AccountBalanceRow) => row.account_code === 'CASH');
    return cashAccount ? parseFloat(cashAccount.balance) : 0;
  }, [accountBalances]);

  if (isLoading) return <LoadingSpinner />;
  return (
    <StatCard
      title="Cash Balance"
      value={formatMoney(balance)}
      link="/app/reports/account-balances"
    />
  );
}

function ARBalanceWidget() {
  const { formatMoney } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 30 * 1000, // 30 seconds - dashboard data
    gcTime: 2 * 60 * 1000,
  });

  const balance = useMemo(() => {
    if (!accountBalances) return 0;
    const arAccount = accountBalances.find((row: AccountBalanceRow) => row.account_code === 'AR');
    return arAccount ? parseFloat(arAccount.balance) : 0;
  }, [accountBalances]);

  if (isLoading) return <LoadingSpinner />;
  return (
    <StatCard
      title="Accounts Receivable"
      value={formatMoney(balance)}
      link="/app/reports/account-balances"
    />
  );
}

function APBalanceWidget() {
  const { formatMoney } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 30 * 1000, // 30 seconds - dashboard data
    gcTime: 2 * 60 * 1000,
  });

  const balance = useMemo(() => {
    if (!accountBalances) return 0;
    const apAccount = accountBalances.find((row: AccountBalanceRow) => row.account_code === 'AP');
    return apAccount ? parseFloat(apAccount.balance) : 0;
  }, [accountBalances]);

  if (isLoading) return <LoadingSpinner />;
  return (
    <StatCard
      title="Accounts Payable"
      value={formatMoney(balance)}
      link="/app/reports/account-balances"
    />
  );
}

function TrialBalanceLinkWidget() {
  return (
    <Link to="/app/reports/trial-balance">
      <div className="bg-white rounded-lg shadow border border-gray-200 p-6 hover:border-[#1F6F5C]/30 transition-colors">
        <p className="text-sm font-medium text-gray-600">Trial Balance</p>
        <p className="text-sm text-[#1F6F5C] mt-2 font-medium">View Report →</p>
      </div>
    </Link>
  );
}

function RecentPostingsWidget() {
  // Simple widget showing link to general ledger
  return (
    <Link to="/app/reports/general-ledger">
      <div className="bg-white rounded-lg shadow border border-gray-200 p-6 hover:border-[#1F6F5C]/30 transition-colors">
        <p className="text-sm font-medium text-gray-600">Recent Postings</p>
        <p className="text-sm text-[#1F6F5C] mt-2 font-medium">View General Ledger →</p>
      </div>
    </Link>
  );
}

function InventoryAlertsWidget() {
  const { data: stock, isLoading } = useStockOnHand({});
  const lowStockCount = useMemo(
    () => stock?.filter((s) => parseFloat(s.qty_on_hand) < 10).length || 0,
    [stock]
  );

  if (isLoading) return <LoadingSpinner />;
  return (
    <StatCard
      title="Low Stock Items"
      value={lowStockCount}
      link="/app/inventory/stock-on-hand"
    />
  );
}

function WorkLogsSummaryWidget() {
  const { data: workLogs, isLoading } = useWorkLogs({ status: 'DRAFT' });
  const draftLogsCount = (workLogs ?? []).length;

  if (isLoading) return <LoadingSpinner />;
  return (
    <StatCard
      title="Draft Work Logs"
      value={draftLogsCount}
      link="/app/labour/work-logs?status=DRAFT"
    />
  );
}

function SetupStatusWidget() {
  // Simple status widget - could be enhanced with actual setup checks
  return (
    <Link to="/app/settings/localisation">
      <div className="bg-white rounded-lg shadow border border-gray-200 p-6 hover:border-[#1F6F5C]/30 transition-colors">
        <p className="text-sm font-medium text-gray-600">Setup Status</p>
        <p className="text-sm text-[#1F6F5C] mt-2 font-medium">Configure Settings →</p>
      </div>
    </Link>
  );
}

function UsersCountWidget() {
  const { data: users, isLoading } = useQuery({
    queryKey: ['tenant', 'users'],
    queryFn: () => apiClient.get<any[]>('/api/tenant/users'),
    staleTime: 5 * 60 * 1000, // 5 minutes - users don't change frequently
    gcTime: 15 * 60 * 1000,
  });

  const userCount = useMemo(() => users?.length || 0, [users]);

  if (isLoading) return <LoadingSpinner />;
  return <StatCard title="Users" value={userCount} link="/app/admin/users" />;
}

function ModulesStatusWidget() {
  return (
    <Link to="/app/admin/modules">
      <div className="bg-white rounded-lg shadow border border-gray-200 p-6 hover:border-[#1F6F5C]/30 transition-colors">
        <p className="text-sm font-medium text-gray-600">Modules</p>
        <p className="text-sm text-[#1F6F5C] mt-2 font-medium">Manage Modules →</p>
      </div>
    </Link>
  );
}
