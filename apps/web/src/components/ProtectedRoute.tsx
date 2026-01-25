import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks';

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const USER_ROLE_KEY = 'farm_erp_user_role';

export function ProtectedRoute({ children }: ProtectedRouteProps) {
  const { userRole } = useAuth();

  // For Phase 1, we'll allow access if userRole exists (even if it's just from localStorage)
  // Check both state and localStorage to handle async state updates
  const storedRole = localStorage.getItem(USER_ROLE_KEY);
  const hasRole = userRole || storedRole;

  if (!hasRole) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}
