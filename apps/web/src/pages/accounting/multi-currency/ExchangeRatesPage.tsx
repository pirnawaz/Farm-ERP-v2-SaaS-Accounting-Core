import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { PageHeader } from '../../../components/PageHeader';
import { PageContainer } from '../../../components/PageContainer';
import { useFormatting } from '../../../hooks/useFormatting';
import { useTenantSettings } from '../../../hooks/useTenantSettings';
import { useRole } from '../../../hooks/useRole';
import { exchangeRatesApi } from '../../../api/multiCurrency';
import type { ExchangeRateRow } from '@farm-erp/shared';
import toast from 'react-hot-toast';

export default function ExchangeRatesPage() {
  const { formatDate } = useFormatting();
  const { settings } = useTenantSettings();
  const { canPost } = useRole();
  const baseDefault = (settings?.currency_code || 'GBP').toUpperCase();

  const [rows, setRows] = useState<ExchangeRateRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');

  const [rateDate, setRateDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [baseCode, setBaseCode] = useState(baseDefault);
  const [quoteCode, setQuoteCode] = useState('');
  const [rate, setRate] = useState('');
  const [source, setSource] = useState('manual');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const data = await exchangeRatesApi.list({
        from_date: fromDate || undefined,
        to_date: toDate || undefined,
      });
      setRows(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load exchange rates');
    } finally {
      setLoading(false);
    }
  }, [fromDate, toDate]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    setBaseCode(baseDefault);
  }, [baseDefault]);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    const r = parseFloat(rate);
    if (!quoteCode || quoteCode.length !== 3) {
      toast.error('Enter a 3-letter quote currency (e.g. EUR).');
      return;
    }
    if (!Number.isFinite(r) || r <= 0) {
      toast.error('Enter a positive rate.');
      return;
    }
    try {
      setSaving(true);
      await exchangeRatesApi.create({
        rate_date: rateDate,
        base_currency_code: baseCode.toUpperCase(),
        quote_currency_code: quoteCode.toUpperCase(),
        rate: r,
        source: source.trim() || 'manual',
      });
      toast.success('Exchange rate saved');
      setQuoteCode('');
      setRate('');
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Exchange rates"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Multi-currency', to: '/app/accounting/exchange-rates' },
          { label: 'Rates' },
        ]}
      />

      <section className="bg-white rounded-lg shadow p-6 space-y-3">
        <h2 className="text-lg font-semibold">Functional currency</h2>
        <p className="text-sm text-gray-600">
          Your tenant&apos;s reporting currency is <strong className="text-gray-900">{baseDefault}</strong> (from
          settings). Stored rates are <strong>base units per 1 unit of quote currency</strong> (e.g.{' '}
          {baseDefault}/EUR = how many {baseDefault} for one EUR).
        </p>
      </section>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Add rate</h2>
        <p className="text-sm text-gray-600">
          One row per date and currency pair. Posting and FX revaluation use the latest rate on or before the
          relevant date (deterministic lookup).
        </p>
        <form onSubmit={onSubmit} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-4xl">
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Rate date</span>
            <input
              type="date"
              className="w-full border rounded-md px-3 py-2"
              value={rateDate}
              onChange={(e) => setRateDate(e.target.value)}
              required
            />
          </label>
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Base (functional)</span>
            <input
              type="text"
              maxLength={3}
              className="w-full border rounded-md px-3 py-2 uppercase"
              value={baseCode}
              onChange={(e) => setBaseCode(e.target.value)}
              required
            />
          </label>
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Quote (foreign)</span>
            <input
              type="text"
              maxLength={3}
              placeholder="EUR"
              className="w-full border rounded-md px-3 py-2 uppercase"
              value={quoteCode}
              onChange={(e) => setQuoteCode(e.target.value)}
              required
            />
          </label>
          <label className="text-sm sm:col-span-2 lg:col-span-1">
            <span className="text-gray-600 block mb-1">Rate (base per 1 quote)</span>
            <input
              type="text"
              inputMode="decimal"
              className="w-full border rounded-md px-3 py-2 tabular-nums"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              placeholder="e.g. 1.25"
              required
            />
          </label>
          <label className="text-sm sm:col-span-2">
            <span className="text-gray-600 block mb-1">Source (audit)</span>
            <input
              type="text"
              className="w-full border rounded-md px-3 py-2"
              value={source}
              onChange={(e) => setSource(e.target.value)}
            />
          </label>
          <div className="flex items-end">
            <button
              type="submit"
              disabled={saving || !canPost}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {saving ? 'Saving…' : 'Save rate'}
            </button>
          </div>
        </form>
        {!canPost && (
          <p className="text-sm text-amber-800">Only tenant administrators and accountants can add rates.</p>
        )}
      </section>

      <section className="space-y-4">
        <div className="flex flex-wrap items-end gap-4">
          <label className="text-sm text-gray-600">
            From
            <input
              type="date"
              className="ml-2 border rounded-md px-2 py-1"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
            />
          </label>
          <label className="text-sm text-gray-600">
            To
            <input
              type="date"
              className="ml-2 border rounded-md px-2 py-1"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
            />
          </label>
          <button type="button" onClick={() => load()} className="text-sm text-[#1F6F5C] hover:underline">
            Apply filters
          </button>
        </div>

        {loading && <div className="text-gray-600">Loading…</div>}
        {error && (
          <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4" role="alert">
            {error}
          </div>
        )}
        {!loading && !error && rows.length === 0 && (
          <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">
            No exchange rates in this range. Add a rate above — foreign-currency posting and FX revaluation need
            rates for each quote currency.
          </div>
        )}
        {!loading && rows.length > 0 && (
          <div className="overflow-x-auto bg-white rounded-lg shadow">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b bg-gray-50 text-left">
                  <th className="p-3">Date</th>
                  <th className="p-3">Pair</th>
                  <th className="p-3 text-right">Rate</th>
                  <th className="p-3">Source</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-gray-100">
                    <td className="p-3 whitespace-nowrap">{formatDate(row.rate_date)}</td>
                    <td className="p-3">
                      <span className="font-mono text-xs">
                        {row.base_currency_code}/{row.quote_currency_code}
                      </span>
                    </td>
                    <td className="p-3 text-right tabular-nums font-medium">{String(row.rate)}</td>
                    <td className="p-3 text-gray-600">{row.source ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </PageContainer>
  );
}
