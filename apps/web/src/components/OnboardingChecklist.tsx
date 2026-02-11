import { Link } from 'react-router-dom';
import { useOnboardingQuery, useOnboardingUpdateMutation } from '../hooks/useOnboarding';
import type { OnboardingStepId } from '@farm-erp/shared';

const STEP_CONFIG: { id: OnboardingStepId; label: string; path: string; required: boolean }[] = [
  { id: 'farm_profile', label: 'Farm Profile', path: '/app/admin/farm', required: true },
  { id: 'add_land_parcel', label: 'Add Land Parcel', path: '/app/land', required: true },
  { id: 'create_crop_cycle', label: 'Create Crop Cycle', path: '/app/crop-cycles', required: true },
  { id: 'create_first_project', label: 'Create First Project', path: '/app/projects', required: true },
  { id: 'add_first_party', label: 'Add First Party', path: '/app/parties', required: false },
  { id: 'post_first_transaction', label: 'Post First Transaction', path: '/app/transactions', required: false },
];

export function OnboardingChecklist() {
  const { data, isLoading } = useOnboardingQuery();
  const updateMutation = useOnboardingUpdateMutation();

  if (isLoading || !data) return null;
  if (data.dismissed) return null;

  const steps = data.steps ?? {};

  const handleDismiss = () => {
    updateMutation.mutate({ dismissed: true });
  };

  return (
    <div
      data-testid="onboarding-checklist"
      className="bg-[#E6ECEA] border border-[#1F6F5C]/30 rounded-lg p-4 mb-4"
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <h3 className="font-semibold text-[#2D3A3A]">Getting started</h3>
          <p className="text-sm text-[#2D3A3A]/80 mt-1">
            Complete these steps to set up your farm in Terrava ERP.
          </p>
          <ul className="mt-3 space-y-1.5">
            {STEP_CONFIG.map((step) => {
              const done = steps[step.id];
              return (
                <li key={step.id} className="flex items-center gap-2 text-sm">
                  <span
                    className={`inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-xs font-medium ${
                      done ? 'bg-[#1F6F5C] text-white' : 'bg-white/80 text-gray-500'
                    }`}
                    aria-hidden
                  >
                    {done ? '✓' : step.required ? '!' : '○'}
                  </span>
                  <Link
                    to={step.path}
                    className={`${done ? 'text-gray-600' : 'text-[#1F6F5C] hover:underline'} font-medium`}
                  >
                    {step.label}
                    {step.required && (
                      <span className="text-gray-500 font-normal ml-1">(required)</span>
                    )}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
        <div className="flex flex-col gap-2 flex-shrink-0">
          <button
            type="button"
            onClick={handleDismiss}
            data-testid="onboarding-dismiss"
            className="px-3 py-1.5 text-sm rounded border border-[#1F6F5C]/50 text-[#1F6F5C] hover:bg-[#1F6F5C]/10 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            Dismiss
          </button>
        </div>
      </div>
    </div>
  );
}

export function ReopenOnboardingButton() {
  const updateMutation = useOnboardingUpdateMutation();

  const handleReopen = () => {
    updateMutation.mutate({ dismissed: false });
  };

  return (
    <button
      type="button"
      onClick={handleReopen}
      data-testid="onboarding-reopen"
      className="px-3 py-2 text-sm rounded border border-[#1F6F5C]/50 text-[#1F6F5C] hover:bg-[#1F6F5C]/10 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
    >
      Reopen onboarding checklist
    </button>
  );
}
