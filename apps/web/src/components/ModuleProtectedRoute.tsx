import { Navigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useModules } from '../contexts/ModulesContext';
import { LoadingSpinner } from './LoadingSpinner';

interface ModuleProtectedRouteProps {
  requiredModule: string;
  children: React.ReactNode;
}

export function ModuleProtectedRoute({ requiredModule, children }: ModuleProtectedRouteProps) {
  const { isModuleEnabled, loading } = useModules();

  if (loading) {
    return (
      <div className="flex justify-center items-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!isModuleEnabled(requiredModule)) {
    toast.error('Module not enabled');
    return <Navigate to="/app" replace />;
  }

  return <>{children}</>;
}
