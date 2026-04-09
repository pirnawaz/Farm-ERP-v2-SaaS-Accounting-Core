import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import type { LoanAgreementDetail, LoanAgreementStatement } from '@farm-erp/shared';
import { loanAgreementsApi } from '../api/loanAgreements';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';
import { useTenantSettings } from '../hooks/useTenantSettings';
import toast from 'react-hot-toast';

export default function LoanAgreementDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { settings } = useTenantSettings();
  const [agreement, setAgreement] = useState<LoanAgreementDetail | null>(null);
  const [statement, setStatement] = useState<LoanAgreementStatement | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const load = async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const [ag, st] = await Promise.all([
        loanAgreementsApi.get(id),
        loanAgreementsApi.getStatement(id, {
          from: from.trim() || undefined,
          to: to.trim() || undefined,
        }),
      ]);
      setAgreement(ag);
      setStatement(st);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Failed to load loan agreement';
      setAgreement((current) => {
        if (!current) {
          setError(msg);
        } else {
          toast.error(msg);
        }
        return current;
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reload on id or when applying filters
  }, [id]);

  const applyFilters = () => {
    load();
  };

  if (loading && !agreement) {
    return <div className="text-gray-600 p-4">Loading…</div>;
  }

  if (error || !agreement || !statement) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">{error || 'Not found'}</p>
        <Link to="/app/loans" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          ← Back to loans
        </Link>
      </div>
    );
  }

  const cc = agreement.currency_code;
  const functionalCc = (settings?.currency_code || 'GBP').toUpperCase();
  const loanCc = (cc || functionalCc).toUpperCase();

  return (
    <div className="space-y-6">
      <PageHeader
        title={agreement.reference_no ? `Loan · ${agreement.reference_no}` : 'Loan agreement'}
        backTo="/app/loans"
        breadcrumbs={[
          { label: 'Governance', to: '/app/governance' },
          { label: 'Loans', to: '/app/loans' },
          { label: 'Detail' },
        ]}
      />

      <div className="flex flex-wrap gap-4 text-sm text-gray-600 -mt-2">
        <span>
          Status: <strong className="text-gray-900">{agreement.status}</strong>
        </span>
        {agreement.project?.name && (
          <span>
            Project: <strong className="text-gray-900">{agreement.project.name}</strong>
          </span>
        )}
        {agreement.lender_party?.name && (
          <span>
            Lender: <strong className="text-gray-900">{agreement.lender_party.name}</strong>
          </span>
        )}
        <span>
          Loan currency: <strong className="text-gray-900">{loanCc}</strong>
        </span>
        <span>
          Functional (reporting): <strong className="text-gray-900">{functionalCc}</strong>
        </span>
      </div>

      <section className="bg-white rounded-lg shadow p-6" data-testid="loan-agreement-summary">
        <h2 className="text-lg font-semibold mb-4">Facility</h2>
        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Principal (facility)</dt>
            <dd className="mt-1 font-medium tabular-nums">
              {agreement.principal_amount != null
                ? formatMoney(agreement.principal_amount, { currencyCode: cc })
                : '—'}
            </dd>
          </div>
          <div>
            <dt className="text-gray-500">Currency</dt>
            <dd className="mt-1">{cc}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Start</dt>
            <dd className="mt-1">{agreement.start_date ? formatDate(agreement.start_date) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Maturity</dt>
            <dd className="mt-1">{agreement.maturity_date ? formatDate(agreement.maturity_date) : '—'}</dd>
          </div>
        </dl>
      </section>

      <section className="bg-white rounded-lg shadow p-6">
        <div className="flex flex-col lg:flex-row lg:items-end gap-4 mb-6">
          <div>
            <h2 className="text-lg font-semibold">Loan statement</h2>
            <p className="text-sm text-gray-500 mt-1">
              Posted drawdowns and repayments (principal affects outstanding liability). Optional date range
              filters statement lines.
            </p>
          </div>
          <div className="flex flex-wrap items-end gap-3 lg:ml-auto">
            <label className="text-sm">
              <span className="text-gray-600 mr-2">From</span>
              <input
                type="date"
                className="border border-gray-300 rounded-md px-2 py-1.5 text-sm"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
              />
            </label>
            <label className="text-sm">
              <span className="text-gray-600 mr-2">To</span>
              <input
                type="date"
                className="border border-gray-300 rounded-md px-2 py-1.5 text-sm"
                value={to}
                onChange={(e) => setTo(e.target.value)}
              />
            </label>
            <button
              type="button"
              onClick={applyFilters}
              className="px-3 py-2 bg-[#1F6F5C] text-white rounded text-sm font-medium hover:bg-[#1a5a4a]"
            >
              Apply
            </button>
          </div>
        </div>

        <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 p-4 bg-gray-50 rounded-lg">
          <div>
            <dt className="text-sm text-gray-500">Opening balance</dt>
            <dd className="text-lg font-semibold tabular-nums">
              {formatMoney(statement.opening_balance, { currencyCode: cc })}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Closing balance</dt>
            <dd className="text-lg font-semibold tabular-nums">
              {formatMoney(statement.closing_balance, { currencyCode: cc })}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Period</dt>
            <dd className="text-sm">
              {statement.from ?? '—'} → {statement.to ?? '—'}
            </dd>
          </div>
        </dl>

        <h3 className="text-md font-semibold mb-2">Activity (chronological)</h3>
        <div className="overflow-x-auto border border-gray-200 rounded-lg">
          <table className="min-w-full divide-y divide-gray-200" data-testid="loan-statement-lines">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Principal</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Interest</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                  Balance after
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {statement.lines.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                    No posted activity in this range.
                  </td>
                </tr>
              ) : (
                statement.lines.map((line) => (
                  <tr key={`${line.kind}-${line.id}`} className="hover:bg-gray-50">
                    <td className="px-3 py-2 text-sm tabular-nums whitespace-nowrap">
                      {formatDate(line.date)}
                    </td>
                    <td className="px-3 py-2 text-sm">{line.kind}</td>
                    <td className="px-3 py-2 text-sm text-right tabular-nums">
                      {formatMoney(line.amount, { currencyCode: cc })}
                    </td>
                    <td className="px-3 py-2 text-sm text-right tabular-nums text-gray-700">
                      {line.principal != null ? formatMoney(line.principal, { currencyCode: cc }) : '—'}
                    </td>
                    <td className="px-3 py-2 text-sm text-right tabular-nums text-gray-700">
                      {line.interest != null ? formatMoney(line.interest, { currencyCode: cc }) : '—'}
                    </td>
                    <td className="px-3 py-2 text-sm text-right tabular-nums font-medium">
                      {formatMoney(line.balance_after, { currencyCode: cc })}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <div className="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div>
            <h3 className="text-md font-semibold mb-2">Drawdowns (period)</h3>
            <div className="overflow-x-auto border border-gray-200 rounded-lg">
              <table className="min-w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-2 py-2 text-left">Posted</th>
                    <th className="px-2 py-2 text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {statement.drawdowns.length === 0 ? (
                    <tr>
                      <td colSpan={2} className="px-2 py-4 text-center text-gray-500">
                        None
                      </td>
                    </tr>
                  ) : (
                    statement.drawdowns.map((d) => (
                      <tr key={d.id} className="border-t border-gray-100">
                        <td className="px-2 py-2">{formatDate(d.posting_date)}</td>
                        <td className="px-2 py-2 text-right tabular-nums">
                          {formatMoney(d.amount, { currencyCode: cc })}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
          <div>
            <h3 className="text-md font-semibold mb-2">Repayments (period)</h3>
            <div className="overflow-x-auto border border-gray-200 rounded-lg">
              <table className="min-w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-2 py-2 text-left">Posted</th>
                    <th className="px-2 py-2 text-right">Principal</th>
                    <th className="px-2 py-2 text-right">Interest</th>
                    <th className="px-2 py-2 text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {statement.repayments.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="px-2 py-4 text-center text-gray-500">
                        None
                      </td>
                    </tr>
                  ) : (
                    statement.repayments.map((r) => (
                      <tr key={r.id} className="border-t border-gray-100">
                        <td className="px-2 py-2">{formatDate(r.posting_date)}</td>
                        <td className="px-2 py-2 text-right tabular-nums">
                          {formatMoney(r.principal_amount, { currencyCode: cc })}
                        </td>
                        <td className="px-2 py-2 text-right tabular-nums">
                          {formatMoney(r.interest_amount, { currencyCode: cc })}
                        </td>
                        <td className="px-2 py-2 text-right tabular-nums">
                          {formatMoney(r.amount, { currencyCode: cc })}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
