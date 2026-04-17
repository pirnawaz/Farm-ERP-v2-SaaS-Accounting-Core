import { apiClient } from '@farm-erp/shared';

/** Draft/posted overhead allocations (cost center → projects). */
export async function fetchOverheadAllocations(): Promise<unknown[]> {
  return apiClient.get<unknown[]>('/api/overhead-allocations');
}

/** Bill recognition schedules (prepaid / accrual spread). */
export async function fetchBillRecognitionSchedules(): Promise<unknown[]> {
  return apiClient.get<unknown[]>('/api/bill-recognition-schedules');
}
