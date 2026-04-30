import { useMemo, useState } from 'react';
import { exportToCSV } from '../../../utils/csvExport';
import { apReportsApi, type CreditPremiumRow } from '../../../lib/api/procurement/apReports';

export function CreditPremiumByProjectReportPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [partyId, setPartyId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [rows, setRows] = useState<CreditPremiumRow[]>([]);
  const [loading, setLoading] = useState(false);

  const csvRows = useMemo(
    () =>
      rows.map((r) => ({
        posting_date: r.posting_date,
        crop_cycle: r.crop_cycle_name ?? '',
        project: r.project_name ?? '',
        supplier: r.supplier_name,
        line_no: r.line_no ?? '',
        description: r.description,
        credit_premium_amount: r.credit_premium_amount,
      })),
    [rows],
  );

  async function run() {
    setLoading(true);
    try {
      const res = await apReportsApi.creditPremiumByProject({
        from: from || undefined,
        to: to || undefined,
        party_id: partyId || undefined,
        crop_cycle_id: cropCycleId || undefined,
        project_id: projectId || undefined,
      });
      setRows(res.rows);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ padding: 16 }}>
      <h2 style={{ margin: 0 }}>Credit Premium by Crop/Project</h2>
      <div style={{ display: 'flex', gap: 12, marginTop: 12, flexWrap: 'wrap' }}>
        <label>
          From
          <input style={{ display: 'block' }} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>
          To
          <input style={{ display: 'block' }} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
        <label>
          Supplier Party ID
          <input style={{ display: 'block', width: 320 }} value={partyId} onChange={(e) => setPartyId(e.target.value)} />
        </label>
        <label>
          Crop cycle ID
          <input style={{ display: 'block', width: 320 }} value={cropCycleId} onChange={(e) => setCropCycleId(e.target.value)} />
        </label>
        <label>
          Project ID
          <input style={{ display: 'block', width: 320 }} value={projectId} onChange={(e) => setProjectId(e.target.value)} />
        </label>
        <button disabled={loading} onClick={run}>
          {loading ? 'Loading…' : 'Run'}
        </button>
        <button
          disabled={rows.length === 0}
          onClick={() =>
            exportToCSV(csvRows, 'credit-premium.csv', undefined, {
              reportName: 'CreditPremium',
              fromDate: from || undefined,
              toDate: to || undefined,
            })
          }
        >
          Export CSV
        </button>
      </div>

      <div style={{ overflowX: 'auto', marginTop: 16 }}>
        <table style={{ borderCollapse: 'collapse', width: '100%' }}>
          <thead>
            <tr>
              {[
                'Posting date',
                'Crop cycle',
                'Project',
                'Supplier',
                'Line',
                'Description',
                'Credit premium',
              ].map((h) => (
                <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: 8 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, idx) => (
              <tr key={`${r.supplier_invoice_id}:${r.supplier_invoice_line_id ?? 'none'}:${idx}`}>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.posting_date}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.crop_cycle_name ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.project_name ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.supplier_name}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.line_no ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.description}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.credit_premium_amount}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} style={{ padding: 12, color: '#777' }}>
                  Run the report to see results (draft invoices are excluded).
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

