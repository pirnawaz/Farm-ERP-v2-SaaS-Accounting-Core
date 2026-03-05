import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useImpersonation } from '../hooks/useImpersonation';
import toast from 'react-hot-toast';

const IMPERSONATION_RETURN_TO_KEY = 'impersonation.return_to';

function getReturnToPath(): string | null {
  const raw = sessionStorage.getItem(IMPERSONATION_RETURN_TO_KEY);
  if (raw && raw.startsWith('/app')) return raw;
  return null;
}

interface ImpersonationBannerProps {
  /** Fetch and show banner when true (e.g. when on /app so tenant app can show while impersonating). */
  enabled: boolean;
}

export function ImpersonationBanner({ enabled }: ImpersonationBannerProps) {
  const navigate = useNavigate();
  const { status, isLoading, isImpersonating, isError, error, stop, forceStop } = useImpersonation(enabled);
  const [stopFailed, setStopFailed] = useState(false);
  const returnToPath = getReturnToPath();

  useEffect(() => {
    if (!enabled || !isError || !error) return;
    const message = error?.message ?? 'Unknown error';
    console.error('[Impersonation status] request failed', { message, error });
    if (import.meta.env.DEV) {
      toast.error(`Impersonation status: ${message}`);
    }
  }, [enabled, isError, error]);

  const navigateAfterStop = () => {
    const path = getReturnToPath();
    sessionStorage.removeItem(IMPERSONATION_RETURN_TO_KEY);
    navigate(path ?? '/app/platform/tenants');
  };

  const handleExit = async () => {
    setStopFailed(false);
    try {
      await stop(status?.tenant?.id);
      navigateAfterStop();
    } catch {
      setStopFailed(true);
      toast.error('Stop failed. Try "Force stop".');
    }
  };

  const handleForceStop = async () => {
    setStopFailed(false);
    try {
      await forceStop();
      navigateAfterStop();
    } catch (e) {
      toast.error((e as Error)?.message ?? 'Force stop failed');
    }
  };

  if (!enabled) return null;

  if (isError) {
    return (
      <div
        className="bg-amber-100 border-b border-amber-300 px-4 py-2 flex items-center justify-between text-amber-900"
        data-testid="impersonation-status-unavailable"
      >
        <span className="text-sm font-medium">Impersonation status unavailable</span>
      </div>
    );
  }

  if (isLoading || !isImpersonating) return null;

  const tenantName = status?.tenant?.name ?? 'tenant';
  const userEmail = status?.user?.email ?? 'user';

  return (
    <div
      className="bg-amber-100 border-b border-amber-300 px-4 py-2 flex items-center justify-between text-amber-900"
      data-testid="impersonation-banner"
    >
      <span className="text-sm font-medium">
        Impersonating {userEmail} in <strong>{tenantName}</strong>
      </span>
      <div className="flex items-center gap-2 flex-wrap">
        {returnToPath && (
          <Link
            to={returnToPath}
            className="px-3 py-1 text-sm font-medium text-amber-800 underline hover:no-underline"
            data-testid="impersonation-return-to-platform"
          >
            Return to platform
          </Link>
        )}
        {stopFailed && (
          <button
            type="button"
            onClick={handleForceStop}
            className="px-3 py-1 text-sm font-medium bg-red-200 hover:bg-red-300 rounded border border-red-400"
            data-testid="impersonation-force-stop"
          >
            Force stop
          </button>
        )}
        <button
          type="button"
          onClick={handleExit}
          className="px-3 py-1 text-sm font-medium bg-amber-200 hover:bg-amber-300 rounded border border-amber-400"
          data-testid="impersonation-exit"
        >
          Stop impersonation
        </button>
      </div>
    </div>
  );
}
