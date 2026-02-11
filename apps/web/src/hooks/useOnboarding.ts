import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { onboardingApi } from '../api/onboarding';
import type { OnboardingUpdatePayload } from '@farm-erp/shared';

export const ONBOARDING_QUERY_KEY = ['tenantOnboarding'];

export function useOnboardingQuery() {
  return useQuery({
    queryKey: ONBOARDING_QUERY_KEY,
    queryFn: () => onboardingApi.getOnboarding(),
    staleTime: 60 * 1000,
  });
}

export function useOnboardingUpdateMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: OnboardingUpdatePayload) =>
      onboardingApi.updateOnboarding(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ONBOARDING_QUERY_KEY });
    },
  });
}
