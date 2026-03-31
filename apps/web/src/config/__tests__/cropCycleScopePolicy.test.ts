import { describe, it, expect } from 'vitest';
import { allowsAllCropCyclesForPath, singleCropCycleContextRecommended } from '../cropCycleScopePolicy';

describe('cropCycleScopePolicy', () => {
  it('allows All Crop Cycles on overview-style routes', () => {
    expect(allowsAllCropCyclesForPath('/app/dashboard')).toBe(true);
    expect(allowsAllCropCyclesForPath('/app/farm-pulse')).toBe(true);
    expect(allowsAllCropCyclesForPath('/app/reports/trial-balance')).toBe(true);
    expect(allowsAllCropCyclesForPath('/app/crop-cycles')).toBe(true);
  });

  it('allows All on inventory dashboard only, not document sub-routes', () => {
    expect(allowsAllCropCyclesForPath('/app/inventory')).toBe(true);
    expect(allowsAllCropCyclesForPath('/app/inventory/grns')).toBe(false);
    expect(allowsAllCropCyclesForPath('/app/inventory/issues/new')).toBe(false);
  });

  it('disallows All on operational and capture routes', () => {
    expect(allowsAllCropCyclesForPath('/app/transactions')).toBe(false);
    expect(allowsAllCropCyclesForPath('/app/crop-ops/activities')).toBe(false);
    expect(allowsAllCropCyclesForPath('/app/machinery/work-logs')).toBe(false);
    expect(allowsAllCropCyclesForPath('/app/accounting/journals')).toBe(false);
  });

  it('singleCropCycleContextRecommended is the inverse', () => {
    expect(singleCropCycleContextRecommended('/app/dashboard')).toBe(false);
    expect(singleCropCycleContextRecommended('/app/transactions')).toBe(true);
  });
});
