import { useCropCycles } from './useCropCycles';
import { useOperationalTransactions } from './useOperationalTransactions';
import { useProjects } from './useProjects';
import { useTrialBalance } from './useReports';

export interface OnboardingState {
  showOnboarding: boolean;
  steps: {
    hasProjects: boolean;
    hasTransactions: boolean;
    hasReports: boolean;
  };
  isLoading: boolean;
}

export function useOnboardingState(): OnboardingState {
  const { data: projects, isLoading: loadingProjects } = useProjects();
  const { data: cropCycles, isLoading: loadingCycles } = useCropCycles();
  const { data: postedTransactions, isLoading: loadingPostedTransactions } = useOperationalTransactions({ status: 'POSTED' });
  
  // Get trial balance for current year to check if reports have data
  const currentYear = new Date().getFullYear();
  const trialBalanceParams = {
    from: new Date(currentYear, 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
  };
  const { data: trialBalance, isLoading: loadingTrialBalance } = useTrialBalance(trialBalanceParams);

  const isLoading = loadingProjects || loadingCycles || loadingPostedTransactions || loadingTrialBalance;

  // Check if user has projects or crop cycles
  const hasProjects = (projects && projects.length > 0) || (cropCycles && cropCycles.length > 0);
  
  // Check if user has posted transactions
  const hasTransactions = postedTransactions && postedTransactions.length > 0;
  
  // Check if trial balance has meaningful data (has rows and not all zeros)
  const hasReports = trialBalance && trialBalance.length > 0 && 
    trialBalance.some(row => {
      const debit = parseFloat(row.total_debit || '0');
      const credit = parseFloat(row.total_credit || '0');
      const net = parseFloat(row.net || '0');
      return debit !== 0 || credit !== 0 || net !== 0;
    });

  // Show onboarding if any step is incomplete
  const showOnboarding = !hasProjects || !hasTransactions || !hasReports;

  return {
    showOnboarding,
    steps: {
      hasProjects: !!hasProjects,
      hasTransactions: !!hasTransactions,
      hasReports: !!hasReports,
    },
    isLoading,
  };
}
