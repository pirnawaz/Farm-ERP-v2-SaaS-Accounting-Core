import type { InvItem } from '../types';

/**
 * Safe display label for an inventory item in lists/detail views.
 * - No item (deleted): "(Deleted item)"
 * - Inactive item: "Name (Inactive)"
 * - Active item: "Name"
 */
export function formatItemDisplayName(item: InvItem | null | undefined): string {
  if (!item) return '(Deleted item)';
  return item.is_active ? item.name : `${item.name} (Inactive)`;
}
