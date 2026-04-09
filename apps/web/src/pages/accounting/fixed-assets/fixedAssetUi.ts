import type { FixedAsset, FixedAssetBook } from '@farm-erp/shared';

export function primaryBook(asset: { books?: FixedAssetBook[] | null }): FixedAssetBook | undefined {
  return asset.books?.find((b) => b.book_type === 'PRIMARY');
}

export function moneyNum(v: string | number | null | undefined): number {
  if (v == null) return 0;
  if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
  const n = parseFloat(v);
  return Number.isFinite(n) ? n : 0;
}

export function isReadOnlyAsset(asset: FixedAsset): boolean {
  return asset.status === 'DISPOSED' || asset.status === 'RETIRED';
}
