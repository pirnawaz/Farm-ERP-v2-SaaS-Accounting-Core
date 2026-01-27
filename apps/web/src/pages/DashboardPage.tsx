import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useRole } from '../hooks/useRole';
import { useModules } from '../contexts/ModulesContext';
import { useOnboardingState } from '../hooks/useOnboardingState';
import { OnboardingPanel } from '../components/OnboardingPanel';
import { getDashboardConfig } from './dashboard/dashboardConfig';
import { DashboardWidget } from './dashboard/DashboardWidgets';
import type { WidgetKey } from './dashboard/dashboardConfig';

export default function DashboardPage() {
  const { userRole } = useRole();
  const { isModuleEnabled } = useModules();
  const onboardingState = useOnboardingState();

  const config = useMemo(() => getDashboardConfig(userRole), [userRole]);

  // Filter quick actions by module availability - memoized
  const availableQuickActions = useMemo(
    () => config.quickActions.filter(
      (action) => !action.requiredModule || isModuleEnabled(action.requiredModule)
    ),
    [config.quickActions, isModuleEnabled]
  );

  // Filter widgets by module availability - memoized
  const filterWidgets = useMemo(
    () => (widgets: WidgetKey[]) =>
      widgets.filter((widget) => {
        // Widgets that require specific modules are handled in DashboardWidget component
        return true;
      }),
    []
  );

  const primaryWidgets = useMemo(
    () => filterWidgets(config.primaryWidgets),
    [config.primaryWidgets, filterWidgets]
  );
  const secondaryWidgets = useMemo(
    () => filterWidgets(config.secondaryWidgets),
    [config.secondaryWidgets, filterWidgets]
  );

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
      </div>

      {/* Onboarding Panel - only show for tenant_admin */}
      {userRole === 'tenant_admin' && <OnboardingPanel onboardingState={onboardingState} />}

      {/* Quick Actions Strip */}
      {availableQuickActions.length > 0 && (
        <div className="mb-6">
          <div className="bg-white rounded-lg shadow border border-gray-200 p-4">
            <div className="flex flex-wrap gap-2">
              {availableQuickActions.map((action, idx) => (
                <Link
                  key={idx}
                  to={action.to}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    action.variant === 'primary'
                      ? 'bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]'
                      : action.variant === 'outline'
                      ? 'border border-[#1F6F5C] text-[#1F6F5C] hover:bg-[#1F6F5C]/10'
                      : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                  }`}
                >
                  {action.label}
                </Link>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Primary Widgets */}
      {primaryWidgets.length > 0 && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 mb-8">
          {primaryWidgets.map((widgetKey) => (
            <DashboardWidget
              key={widgetKey}
              widgetKey={widgetKey}
              isModuleEnabled={isModuleEnabled}
            />
          ))}
        </div>
      )}

      {/* Secondary Widgets */}
      {secondaryWidgets.length > 0 && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 mb-8">
          {secondaryWidgets.map((widgetKey) => (
            <DashboardWidget
              key={widgetKey}
              widgetKey={widgetKey}
              isModuleEnabled={isModuleEnabled}
            />
          ))}
        </div>
      )}
    </div>
  );
}
