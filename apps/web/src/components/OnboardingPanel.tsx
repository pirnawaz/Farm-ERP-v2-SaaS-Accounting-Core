import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useOnboardingState, type OnboardingState } from '../hooks/useOnboardingState';

const DISMISSED_KEY = 'terrava_onboarding_dismissed';

interface OnboardingPanelProps {
  onboardingState: OnboardingState;
}

export function OnboardingPanel({ onboardingState }: OnboardingPanelProps) {
  const navigate = useNavigate();
  const [isDismissed, setIsDismissed] = useState(() => {
    return localStorage.getItem(DISMISSED_KEY) === 'true';
  });

  // Auto-hide if all steps are complete
  const shouldShow = !onboardingState.isLoading && 
                     onboardingState.showOnboarding && 
                     !isDismissed;

  const handleDismiss = useCallback(() => {
    localStorage.setItem(DISMISSED_KEY, 'true');
    setIsDismissed(true);
  }, []);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && shouldShow) {
        handleDismiss();
      }
    };

    if (shouldShow) {
      window.addEventListener('keydown', handleKeyDown);
      return () => window.removeEventListener('keydown', handleKeyDown);
    }
  }, [shouldShow, handleDismiss]);

  if (!shouldShow) {
    return null;
  }

  const { steps } = onboardingState;
  const incompleteSteps = [];

  if (!steps.hasProjects) {
    incompleteSteps.push({
      text: 'Create your first Project or Crop Cycle',
      buttonLabel: 'Add Project',
      action: () => navigate('/app/projects'),
      primary: true,
    });
  }

  if (!steps.hasTransactions) {
    incompleteSteps.push({
      text: 'Set up your first Transaction',
      buttonLabel: 'New Transaction',
      action: () => navigate('/app/transactions/new'),
      primary: incompleteSteps.length === 0,
    });
  }

  if (!steps.hasReports) {
    incompleteSteps.push({
      text: 'Review your first reports',
      buttonLabel: 'View Trial Balance',
      action: () => navigate('/app/reports'),
      primary: incompleteSteps.length === 0,
    });
  }

  if (incompleteSteps.length === 0) {
    return null;
  }

  return (
    <div
      className="bg-white rounded-lg shadow border border-gray-200 p-6 mb-6"
      role="region"
      aria-label="Welcome onboarding"
    >
      <div className="flex justify-between items-start mb-4">
        <div>
          <h2 className="text-xl font-semibold text-gray-900 mb-1">Welcome to Terrava</h2>
          <p className="text-sm text-gray-600">Let's get your farm accounting set up.</p>
        </div>
        <button
          onClick={handleDismiss}
          className="text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] rounded p-1 transition-colors"
          aria-label="Dismiss onboarding"
          title="Dismiss (ESC)"
        >
          <svg
            className="w-5 h-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
      </div>

      <div className="space-y-3">
        {incompleteSteps.map((step, index) => (
          <div
            key={index}
            className="flex items-center justify-between p-3 bg-gray-50 rounded-md"
          >
            <span className="text-sm text-gray-700">{step.text}</span>
            <button
              onClick={step.action}
              className={`px-4 py-2 rounded-md text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] transition-colors ${
                step.primary
                  ? 'bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]'
                  : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'
              }`}
            >
              {step.buttonLabel}
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
