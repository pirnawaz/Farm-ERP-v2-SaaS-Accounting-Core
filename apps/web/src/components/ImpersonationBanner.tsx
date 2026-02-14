import { useNavigate } from 'react-router-dom';
import { useImpersonation } from '../hooks/useImpersonation';
import { useAuth } from '../hooks';

interface ImpersonationBannerProps {
  /** Only fetch impersonation status when true (e.g. when user is platform_admin and on /app). */
  enabled: boolean;
}

export function ImpersonationBanner({ enabled }: ImpersonationBannerProps) {
  const navigate = useNavigate();
  const { userRole } = useAuth();
  const { status, isLoading, isImpersonating, stop } = useImpersonation(enabled && userRole === 'platform_admin');

  const handleExit = async () => {
    await stop(status?.target_tenant_id);
    navigate('/app/platform/tenants');
  };

  if (!enabled || isLoading || !isImpersonating || !status?.target_tenant_name) {
    return null;
  }

  return (
    <div
      className="bg-amber-100 border-b border-amber-300 px-4 py-2 flex items-center justify-between text-amber-900"
      data-testid="impersonation-banner"
    >
      <span className="text-sm font-medium">
        Impersonating: <strong>{status.target_tenant_name}</strong>
        {status.target_user_email ? ` (${status.target_user_email})` : ''}
      </span>
      <button
        type="button"
        onClick={handleExit}
        className="px-3 py-1 text-sm font-medium bg-amber-200 hover:bg-amber-300 rounded border border-amber-400"
        data-testid="impersonation-exit"
      >
        Exit impersonation
      </button>
    </div>
  );
}
