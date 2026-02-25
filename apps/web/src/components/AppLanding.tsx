import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

/**
 * Role-based default landing after /app.
 * tenant_admin, operator → Farm Pulse (cash-first).
 * accountant, platform_admin → existing Dashboard.
 */
export function AppLanding() {
  const { userRole } = useAuth();
  if (userRole === 'tenant_admin' || userRole === 'operator') {
    return <Navigate to="/app/farm-pulse" replace />;
  }
  return <Navigate to="/app/dashboard" replace />;
}
