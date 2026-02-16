import { describe, it, expect, vi, beforeEach } from 'vitest';
import { apiClient } from '@farm-erp/shared';
import { bankReconciliationApi } from '../bankReconciliation';

vi.mock('@farm-erp/shared', () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

describe('bankReconciliationApi', () => {
  beforeEach(() => {
    vi.mocked(apiClient.get).mockReset();
    vi.mocked(apiClient.post).mockReset();
  });

  it('list builds query with account_code and limit', async () => {
    vi.mocked(apiClient.get).mockResolvedValue([]);
    await bankReconciliationApi.list({ account_code: 'BANK', limit: 50 });
    expect(apiClient.get).toHaveBeenCalledWith(
      '/api/bank-reconciliations?account_code=BANK&limit=50'
    );
  });

  it('get fetches single reconciliation', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({ id: 'rec-1', status: 'DRAFT' });
    await bankReconciliationApi.get('rec-1');
    expect(apiClient.get).toHaveBeenCalledWith('/api/bank-reconciliations/rec-1');
  });

  it('create posts payload', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({
      id: 'new-id',
      account_code: 'BANK',
      statement_date: '2026-02-16',
      statement_balance: 1000,
      status: 'DRAFT',
    });
    await bankReconciliationApi.create({
      account_code: 'BANK',
      statement_date: '2026-02-16',
      statement_balance: 1000,
    });
    expect(apiClient.post).toHaveBeenCalledWith('/api/bank-reconciliations', {
      account_code: 'BANK',
      statement_date: '2026-02-16',
      statement_balance: 1000,
    });
  });

  it('clear posts ledger_entry_ids', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ cleared: ['c1'] });
    await bankReconciliationApi.clear('rec-1', {
      ledger_entry_ids: ['le-1', 'le-2'],
      cleared_date: '2026-02-16',
    });
    expect(apiClient.post).toHaveBeenCalledWith(
      '/api/bank-reconciliations/rec-1/clear',
      { ledger_entry_ids: ['le-1', 'le-2'], cleared_date: '2026-02-16' }
    );
  });

  it('unclear posts ledger_entry_ids', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ voided: 1 });
    await bankReconciliationApi.unclear('rec-1', {
      ledger_entry_ids: ['le-1'],
      reason: 'Wrong match',
    });
    expect(apiClient.post).toHaveBeenCalledWith(
      '/api/bank-reconciliations/rec-1/unclear',
      { ledger_entry_ids: ['le-1'], reason: 'Wrong match' }
    );
  });

  it('finalize posts to correct endpoint', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({
      id: 'rec-1',
      status: 'FINALIZED',
    });
    await bankReconciliationApi.finalize('rec-1');
    expect(apiClient.post).toHaveBeenCalledWith(
      '/api/bank-reconciliations/rec-1/finalize',
      {}
    );
  });
});
