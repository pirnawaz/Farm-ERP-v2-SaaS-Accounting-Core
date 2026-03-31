import { describe, expect, it } from 'vitest';
import { computeNetPositionSigned } from './netPosition';
import { normalizeLiability } from './normalizeLiability';

describe('normalizeLiability', () => {
  it('flips positive magnitudes to negative', () => {
    expect(normalizeLiability(22050)).toBe(-22050);
  });

  it('preserves already-negative liabilities', () => {
    expect(normalizeLiability(-22050)).toBe(-22050);
  });
});

describe('computeNetPositionSigned', () => {
  it('only payables: net matches liability sign (negative)', () => {
    expect(computeNetPositionSigned(0, 0, 0, 22050, 0)).toBe(-22050);
  });

  it('cash and payables: net is cash plus signed payables', () => {
    expect(computeNetPositionSigned(50000, 0, 0, 20000, 0)).toBe(30000);
  });

  it('does not double-flip when AP is already credit (negative)', () => {
    expect(computeNetPositionSigned(50000, 0, 0, -20000, 0)).toBe(30000);
  });
});
