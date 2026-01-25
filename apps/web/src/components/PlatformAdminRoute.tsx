import { Navigate } from 'react-router-dom';
import { useRole } from '../hooks/useRole';

interface PlatformAdminRouteProps {
  children: React.ReactNode;
}

export function PlatformAdminRoute({ children }: PlatformAdminRouteProps) {
  const { hasRole } = useRole();

  if (!hasRole('platform_admin')) {
    return <Navigate to="/app/dashboard" replace />;
  }

  return <>{children}</>;
}
