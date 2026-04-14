import { Link } from 'react-router-dom';
import { useModules } from '../contexts/ModulesContext';

export type QuickActionId =
  | 'field-job'
  | 'receive-supplies'
  | 'record-harvest'
  | 'record-sale'
  | 'pay-labour';

type QuickAction = {
  id: QuickActionId;
  label: string;
  to: string;
  requiredModule?: string;
  icon?: string;
};

const QUICK_ACTIONS: QuickAction[] = [
  { id: 'field-job', label: 'New Field Job', to: '/app/crop-ops/field-jobs/new', requiredModule: 'crop_ops' },
  { id: 'receive-supplies', label: 'Receive Supplies', to: '/app/inventory/grns/new', requiredModule: 'inventory' },
  { id: 'record-harvest', label: 'Record Harvest', to: '/app/harvests/new', requiredModule: 'crop_ops' },
  { id: 'record-sale', label: 'Record Sale', to: '/app/sales/new', requiredModule: 'ar_sales' },
  { id: 'pay-labour', label: 'Pay Labour', to: '/app/payments/new', requiredModule: 'treasury_payments' },
];

export function QuickActions() {
  const { isModuleEnabled } = useModules();
  const visible = QUICK_ACTIONS.filter(
    (a) => !a.requiredModule || isModuleEnabled(a.requiredModule)
  );

  return (
    <>
      {/* Desktop: horizontal row */}
      <div className="hidden sm:flex flex-wrap gap-2 justify-center sm:justify-start">
        {visible.map((action) => (
          <Link
            key={action.id}
            to={action.to}
            className="inline-flex items-center px-4 py-2.5 rounded-lg bg-[#1F6F5C] text-white text-sm font-medium hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] focus:ring-offset-2"
          >
            {action.label}
          </Link>
        ))}
      </div>
      {/* Mobile: fixed bottom bar */}
      <div className="sm:hidden fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-gray-200 shadow-[0_-2px_10px_rgba(0,0,0,0.05)] safe-area-pb">
        <div className="flex overflow-x-auto gap-2 px-3 py-3 min-h-[56px]">
          {visible.map((action) => (
            <Link
              key={action.id}
              to={action.to}
              className="flex-shrink-0 inline-flex items-center px-3 py-2 rounded-lg bg-[#1F6F5C] text-white text-xs font-medium hover:bg-[#1a5a4a] whitespace-nowrap"
            >
              {action.label}
            </Link>
          ))}
        </div>
      </div>
    </>
  );
}
