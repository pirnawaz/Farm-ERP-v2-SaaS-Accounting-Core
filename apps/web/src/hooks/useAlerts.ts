/**
 * Alert Center hook: aggregates data from existing APIs/hooks only,
 * computes alert list with stable ordering (critical first), and total count.
 * Respects alert preferences (enabled types, overdue bucket, threshold, showComingSoon).
 */

import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { useOperationalTransactions } from './useOperationalTransactions';
import { usePayablesOutstanding } from './useLabour';
import { useCropCycles } from './useCropCycles';
import { useProjects } from './useProjects';
import { useModules } from '../contexts/ModulesContext';
import { useAlertPreferences } from './useAlertPreferences';
import { getActiveCropCycleId } from '../utils/formDefaults';
import {
  sortAlertsBySeverity,
  buildPendingReviewAlert,
  buildOverdueCustomersAlert,
  buildUnpaidLabourAlert,
  buildLowStockComingSoonAlert,
  buildNegativeMarginFieldsAlert,
} from '../utils/alerts';
import type { Alert } from '../types/alerts';
import type { ARAgeingReport } from '../types';
import { isOverdueInBucketOrWorse } from '../utils/alertOverdueBucket';

export function useAlerts(): {
  alerts: Alert[];
  totalCount: number;
  isLoading: boolean;
} {
  const { isModuleEnabled } = useModules();
  const { preferences } = useAlertPreferences();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);
  const today = asOf;

  // A) Pending review: draft operational transactions
  const { data: draftTransactions = [], isLoading: draftLoading } = useOperationalTransactions({
    status: 'DRAFT',
  });
  const pendingReviewCount = draftTransactions.length;

  // B) Overdue customers: AR Ageing report, count buyers with 31-60 / 61-90 / 90+ > 0
  const { data: arAgeing, isLoading: arAgeingLoading } = useQuery<ARAgeingReport>({
    queryKey: ['ar-ageing', asOf],
    queryFn: () => {
      const params = new URLSearchParams();
      params.append('as_of', asOf);
      return apiClient.get<ARAgeingReport>(`/api/reports/ar-ageing?${params.toString()}`);
    },
    enabled: isModuleEnabled('ar_sales'),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });
  const overdueCustomersCount = useMemo(() => {
    if (!arAgeing?.rows?.length) return 0;
    const bucket = preferences.overdueBucket;
    return arAgeing.rows.filter((row) => isOverdueInBucketOrWorse(row, bucket)).length;
  }, [arAgeing, preferences.overdueBucket]);

  // C) Unpaid labour: workers with payable_balance > 0
  const { data: payablesRows = [], isLoading: payablesLoading } = usePayablesOutstanding();
  const unpaidLabourCount = useMemo(
    () => payablesRows.filter((r) => parseFloat(r.payable_balance || '0') > 0).length,
    [payablesRows]
  );

  // D) Low stock: API has no min_level per item → only "coming soon" when inventory enabled
  const inventoryEnabled = isModuleEnabled('inventory');

  // E) Negative margin fields: project P&L for active cycle, count projects with margin < 0
  const { data: cropCycles } = useCropCycles();
  const activeCycleId = useMemo(() => getActiveCropCycleId(cropCycles), [cropCycles]);
  const activeCycle = useMemo(
    () => cropCycles?.find((c) => c.id === activeCycleId),
    [cropCycles, activeCycleId]
  );
  const cycleStart = activeCycle?.start_date ?? today;
  const cycleEnd =
    activeCycle?.end_date && activeCycle.end_date < today ? activeCycle.end_date : today;
  const { data: projectPLRows = [], isLoading: projectPLLoading } = useQuery({
    queryKey: ['reports', 'project-pl', { from: cycleStart, to: cycleEnd }],
    queryFn: () => apiClient.getProjectPL({ from: cycleStart, to: cycleEnd }),
    enabled:
      !!cycleStart &&
      !!cycleEnd &&
      isModuleEnabled('reports') &&
      isModuleEnabled('projects_crop_cycles'),
    staleTime: 2 * 60 * 1000,
  });
  const { data: projectsForCycle } = useProjects(activeCycleId ?? undefined);
  const projectIdsInCycle = useMemo(
    () => new Set((projectsForCycle ?? []).map((p) => p.id)),
    [projectsForCycle]
  );
  const threshold = preferences.negativeMarginThreshold;
  const negativeMarginCount = useMemo(() => {
    return projectPLRows
      .filter(
        (row) =>
          (!activeCycleId || projectIdsInCycle.has(row.project_id)) &&
          parseFloat(row.net_profit) < threshold
      )
      .length;
  }, [projectPLRows, activeCycleId, projectIdsInCycle, threshold]);

  const alerts = useMemo(() => {
    const list: Alert[] = [];
    const enabled = preferences.enabled;

    if (enabled.PENDING_REVIEW) {
      const a1 = buildPendingReviewAlert(pendingReviewCount);
      if (a1) list.push(a1);
    }
    if (enabled.OVERDUE_CUSTOMERS && isModuleEnabled('ar_sales')) {
      const a2 = buildOverdueCustomersAlert(overdueCustomersCount);
      if (a2) list.push(a2);
    }
    if (enabled.UNPAID_LABOUR && isModuleEnabled('labour')) {
      const a3 = buildUnpaidLabourAlert(unpaidLabourCount);
      if (a3) list.push(a3);
    }
    if (
      enabled.LOW_STOCK &&
      inventoryEnabled &&
      preferences.showComingSoon
    ) {
      list.push(buildLowStockComingSoonAlert());
    }
    if (
      enabled.NEGATIVE_MARGIN_FIELDS &&
      isModuleEnabled('reports') &&
      isModuleEnabled('projects_crop_cycles')
    ) {
      const a5 = buildNegativeMarginFieldsAlert(negativeMarginCount);
      if (a5) list.push(a5);
    }

    return sortAlertsBySeverity(list);
  }, [
    preferences.enabled,
    preferences.showComingSoon,
    pendingReviewCount,
    overdueCustomersCount,
    unpaidLabourCount,
    negativeMarginCount,
    inventoryEnabled,
    isModuleEnabled,
  ]);

  const totalCount = useMemo(
    () => alerts.reduce((sum, a) => sum + (a.count ?? 0), 0),
    [alerts]
  );

  const isLoading =
    draftLoading ||
    (isModuleEnabled('ar_sales') && arAgeingLoading) ||
    (isModuleEnabled('labour') && payablesLoading) ||
    (isModuleEnabled('reports') &&
      isModuleEnabled('projects_crop_cycles') &&
      projectPLLoading);

  return { alerts, totalCount, isLoading };
}
