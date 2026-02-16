import { apiClient } from '@farm-erp/shared';

export interface AccountOption {
  id: string;
  code: string;
  name: string;
  type?: string;
}

export const accountsApi = {
  list: () => apiClient.get<AccountOption[]>('/api/accounts'),
};
