import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { useAuth } from '../hooks/useAuth';
import { useRole } from '../hooks/useRole';
import { useModules } from '../contexts/ModulesContext';
import type { UserRole } from '../types';

type HubCardKey = 'settlement' | 'accounting_periods' | 'bank_reconciliation' | 'reports';

type HubCard = {
  key: HubCardKey;
  title: string;
  description: string;
  to: string;
  linkLabel: string;
  roles: UserRole[];
  requiredModule?: string;
};

const HUB_CARDS: HubCard[] = [
  {
    key: 'settlement',
    title: 'Settlement Packs',
    description: 'Create and manage settlement packs for projects and crop cycles.',
    to: '/app/settlement',
    linkLabel: 'Open',
    roles: ['tenant_admin', 'accountant', 'operator'],
    requiredModule: 'settlements',
  },
  {
    key: 'accounting_periods',
    title: 'Accounting Periods',
    description: 'View and close accounting periods; manage period locks.',
    to: '/app/accounting/periods',
    linkLabel: 'Open',
    roles: ['tenant_admin', 'accountant'],
    requiredModule: 'reports',
  },
  {
    key: 'bank_reconciliation',
    title: 'Bank Reconciliation',
    description: 'Reconcile bank statements with ledger entries.',
    to: '/app/reports/bank-reconciliation',
    linkLabel: 'Open',
    roles: ['tenant_admin', 'accountant'],
    requiredModule: 'reports',
  },
  {
    key: 'reports',
    title: 'Reports',
    description: 'Trial balance, P&L, balance sheet, reconciliation dashboard, and more.',
    to: '/app/reports',
    linkLabel: 'Open',
    roles: ['tenant_admin', 'accountant', 'operator'],
    requiredModule: 'reports',
  },
];

function GovernanceCardBadge({ label }: { label: string }) {
  return (
    <span className="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
      {label}
    </span>
  );
}

export default function GovernanceHubPage() {
  const { tenantId } = useAuth();
  const { userRole, hasRole } = useRole();
  const { isModuleEnabled } = useModules();

  const { data: summary } = useQuery({
    queryKey: ['dashboard', 'summary', tenantId],
    queryFn: () => apiClient.getDashboardSummary(),
    enabled: !!tenantId,
    staleTime: 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });

  const visibleCards = HUB_CARDS.filter((card) => {
    if (!userRole || !hasRole(card.roles)) return false;
    if (card.requiredModule && !isModuleEnabled(card.requiredModule)) return false;
    return true;
  });

  const getBadgeForCard = (card: HubCard): string | null => {
    if (!summary?.governance) return null;
    const { governance } = summary;
    switch (card.key) {
      case 'settlement': {
        const n = governance.settlements_pending_count;
        if (n == null || n <= 0) return null;
        return n === 1 ? '1 pending' : `${n} pending`;
      }
      case 'accounting_periods': {
        const n = governance.locks_warning?.length ?? 0;
        if (n <= 0) return null;
        return n === 1 ? '1 closed' : `${n} closed`;
      }
      case 'bank_reconciliation':
      case 'reports':
      default:
        return null;
    }
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Governance</h1>
        <p className="mt-1 text-sm text-gray-600">
          Controls, settlements, locks, and audit-grade reporting.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {visibleCards.map((card) => {
          const badgeLabel = getBadgeForCard(card);
          return (
            <div
              key={card.to}
              className="flex flex-col rounded-lg border border-gray-200 bg-white p-6 shadow"
            >
              <div className="flex flex-wrap items-center gap-1">
                <h3 className="text-base font-semibold text-gray-900">{card.title}</h3>
                {badgeLabel != null && <GovernanceCardBadge label={badgeLabel} />}
              </div>
              <p className="mt-2 flex-1 text-sm text-gray-600">{card.description}</p>
              <div className="mt-4">
                <Link
                  to={card.to}
                  className="inline-flex items-center rounded-md bg-[#1F6F5C] px-3 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
                >
                  {card.linkLabel}
                </Link>
              </div>
            </div>
          );
        })}
      </div>

      {visibleCards.length === 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
          No governance sections available for your role and modules.
        </div>
      )}
    </div>
  );
}
