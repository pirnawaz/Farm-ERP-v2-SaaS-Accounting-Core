import { apiClient } from '@farm-erp/shared';
import type { OnboardingState, OnboardingUpdatePayload } from '@farm-erp/shared';

export const onboardingApi = {
  getOnboarding: () =>
    apiClient.get<OnboardingState>('/api/tenant/onboarding'),

  updateOnboarding: (payload: OnboardingUpdatePayload) =>
    apiClient.put<OnboardingState>('/api/tenant/onboarding', payload),
};
