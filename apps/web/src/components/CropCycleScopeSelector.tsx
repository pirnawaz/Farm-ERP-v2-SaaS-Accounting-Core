import { useState, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useLocation } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { useTenant } from '../hooks/useTenant';
import { useCropCycles } from '../hooks/useCropCycles';
import { useCropCycleScope } from '../contexts/CropCycleScopeContext';
import { allowsAllCropCyclesForPath } from '../config/cropCycleScopePolicy';
import type { CropCycle } from '../types';

function useDashboardSummaryFetchState() {
  const { tenantId } = useTenant();
  const { scopeType, cropCycleId } = useCropCycleScope();
  return useQuery({
    queryKey: ['dashboard', 'summary', tenantId ?? '', scopeType, cropCycleId ?? ''],
    queryFn: () =>
      scopeType === 'crop_cycle' && cropCycleId
        ? apiClient.getDashboardSummary({ scope_type: 'crop_cycle', scope_id: cropCycleId })
        : apiClient.getDashboardSummary(),
    enabled: !!tenantId,
    staleTime: 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });
}

export function CropCycleScopeSelector() {
  const location = useLocation();
  const { tenantId } = useTenant();
  const { data: cropCycles, isLoading: cyclesLoading } = useCropCycles();
  const { scopeType, cropCycleId, setScope, initializedFromStorage } = useCropCycleScope();
  const { isFetching: dashboardFetching } = useDashboardSummaryFetchState();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  const allAllowedHere = allowsAllCropCyclesForPath(location.pathname);
  const scopeMismatch = scopeType === 'all' && !allAllowedHere;

  const openCycle = cropCycles?.find((c: CropCycle) => c.status === 'OPEN');

  useEffect(() => {
    if (cyclesLoading || initializedFromStorage || !cropCycles?.length) return;
    if (scopeType === 'all' && openCycle) {
      setScope('crop_cycle', openCycle.id);
    }
  }, [cyclesLoading, initializedFromStorage, scopeType, openCycle, cropCycles?.length, setScope]);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  if (!tenantId) return null;

  const selectedLabel =
    scopeType === 'crop_cycle' && cropCycleId
      ? cropCycles?.find((c: CropCycle) => c.id === cropCycleId)?.name ?? 'Crop cycle'
      : 'All Crop Cycles';

  return (
    <div className="relative flex items-center gap-3 min-w-0" ref={ref}>
      <div className="flex flex-col min-w-0 justify-center">
        <span
          className="text-xs font-semibold uppercase tracking-wide text-gray-800"
          title="Sets the active crop cycle for dashboards and other scoped views. Some pages need one specific cycle."
        >
          Crop cycle
        </span>
        <span className="text-[11px] text-gray-500 max-w-[13rem] leading-tight mt-0.5 hidden md:block">
          Operating context for farm data and reports.
        </span>
      </div>
      <div className="relative flex-shrink-0">
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-800 bg-white border rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] min-w-[200px] justify-between ${
            scopeMismatch ? 'border-amber-400 ring-1 ring-amber-200' : 'border-gray-300'
          }`}
          aria-expanded={open}
          aria-haspopup="listbox"
          title={
            scopeMismatch
              ? 'This page expects a specific crop cycle — select a crop cycle below'
              : 'Choose which crop cycle applies to dashboards and scoped views'
          }
        >
          <span className="truncate text-left flex items-center gap-2 min-w-0">
            <span
              className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                scopeType === 'all' ? 'bg-gray-100 text-gray-600' : 'bg-[#E6ECEA] text-[#1F6F5C]'
              }`}
            >
              {scopeType === 'all' ? 'All' : 'Cycle'}
            </span>
            <span className="truncate">{selectedLabel}</span>
          </span>
          <svg
            className={`h-4 w-4 flex-shrink-0 text-gray-500 ${open ? 'rotate-180' : ''}`}
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        {dashboardFetching && (
          <span className="absolute -right-6 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden>
            <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
              />
            </svg>
          </span>
        )}
        {open && (
          <ul
            className="absolute z-20 mt-1 left-0 right-0 py-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-60 overflow-auto"
            role="listbox"
          >
            <li role="option">
              <button
                type="button"
                disabled={!allAllowedHere}
                onClick={() => {
                  if (!allAllowedHere) return;
                  setScope('all');
                  setOpen(false);
                }}
                title={
                  allAllowedHere
                    ? 'Include all crop cycles in the current context'
                    : 'Not available on this page — pick a specific crop cycle'
                }
                className={`w-full text-left px-3 py-2 text-sm ${
                  !allAllowedHere
                    ? 'text-gray-400 cursor-not-allowed bg-gray-50'
                    : scopeType === 'all'
                      ? 'bg-[#E6ECEA] text-[#1F6F5C] font-medium'
                      : 'text-gray-700 hover:bg-gray-50'
                }`}
              >
                All Crop Cycles
                {!allAllowedHere && (
                  <span className="block text-[11px] font-normal text-gray-500 mt-0.5">
                    Not available on this page
                  </span>
                )}
              </button>
            </li>
            {cropCycles && cropCycles.length > 0 && (
              <>
                <li className="border-t border-gray-100 my-1" aria-hidden />
                {cropCycles.map((cycle: CropCycle) => (
                  <li key={cycle.id} role="option">
                    <button
                      type="button"
                      onClick={() => {
                        setScope('crop_cycle', cycle.id);
                        setOpen(false);
                      }}
                      className={`w-full text-left px-3 py-2 text-sm flex items-center justify-between gap-2 ${
                        scopeType === 'crop_cycle' && cropCycleId === cycle.id
                          ? 'bg-[#E6ECEA] text-[#1F6F5C] font-medium'
                          : 'text-gray-700 hover:bg-gray-50'
                      }`}
                    >
                      <span className="truncate">{cycle.name}</span>
                      <span
                        className={`flex-shrink-0 text-xs px-1.5 py-0.5 rounded ${
                          cycle.status === 'OPEN' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'
                        }`}
                      >
                        {cycle.status}
                      </span>
                    </button>
                  </li>
                ))}
              </>
            )}
          </ul>
        )}
      </div>
    </div>
  );
}
