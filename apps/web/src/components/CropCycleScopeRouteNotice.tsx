import { useLocation } from 'react-router-dom';
import { allowsAllCropCyclesForPath } from '../config/cropCycleScopePolicy';
import { useCropCycleScope } from '../contexts/CropCycleScopeContext';

/**
 * Shown below the app header when the route expects a specific crop cycle but the user has "All Crop Cycles" selected.
 * Does not change scope automatically — user must pick a cycle in the header.
 */
export function CropCycleScopeRouteNotice() {
  const location = useLocation();
  const { scopeType } = useCropCycleScope();

  if (scopeType !== 'all') {
    return null;
  }
  if (allowsAllCropCyclesForPath(location.pathname)) {
    return null;
  }

  return (
    <div
      className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
      role="status"
    >
      <p className="font-medium text-amber-950">Choose a crop cycle for this area</p>
      <p className="mt-1 text-amber-900/90">
        This page is easier to use with a <strong>specific crop cycle</strong> selected. Use <strong>Crop cycle</strong> in
        the header to switch from &quot;All Crop Cycles&quot; to a named season.
      </p>
    </div>
  );
}
