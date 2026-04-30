import { useMemo, useState } from 'react';
import { exportToCSV } from '../../../utils/csvExport';
import { apReportsApi, type UnpaidBillRow } from '../../../lib/api/procurement/apReports';

export function UnpaidBillsReportPage() {
  const [partyId, setPartyId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [asOf, setAsOf] = useState('');
  const [rows, setRows] = useState<UnpaidBillRow[]>([]);
  const [loading, setLoading] = useState(false);

  const csvRows = useMemo(
    () =>
      rows.map((r) => ({
        reference_no: r.reference_no ?? '',
        supplier_name: r.supplier_name,
        invoice_date: r.invoice_date ?? '',
        due_date: r.due_date ?? '',
        total: r.total,
        paid: r.paid,
        unpaid: r.unpaid,
        currency_code: r.currency_code ?? '',
        status: r.status,
      })),
    [rows],
  );

  async function run() {
    setLoading(true);
    try {
      const res = await apReportsApi.unpaidBills({
        party_id: partyId || undefined,
        crop_cycle_id: cropCycleId || undefined,
        project_id: projectId || undefined,
        as_of: asOf || undefined,
      });
      setRows(res.rows);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ padding: 16 }}>
      <h2 style={{ margin: 0 }}>Unpaid Bills</h2>
      <div style={{ display: 'flex', gap: 12, marginTop: 12, flexWrap: 'wrap' }}>
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
        <label>
          As of
          <input style={{ display: 'block' }} type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
        </label>
        <button disabled={loading} onClick={run}>
          {loading ? 'Loading…' : 'Run'}
        </button>
        <button
          disabled={rows.length === 0}
          onClick={() =>
            exportToCSV(csvRows, 'unpaid-bills.csv', undefined, {
              reportName: 'UnpaidBills',
              asOfDate: asOf || undefined,
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
              {['Invoice #', 'Supplier', 'Invoice date', 'Due date', 'Total', 'Paid', 'Unpaid', 'Currency'].map((h) => (
                <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: 8 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.supplier_invoice_id}>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.reference_no ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.supplier_name}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.invoice_date ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.due_date ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.total}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.paid}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.unpaid}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.currency_code ?? ''}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={8} style={{ padding: 12, color: '#777' }}>
                  Run the report to see results.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

