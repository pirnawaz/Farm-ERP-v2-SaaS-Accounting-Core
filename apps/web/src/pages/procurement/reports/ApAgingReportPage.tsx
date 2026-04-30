import { useMemo, useState } from 'react';
import { exportToCSV } from '../../../utils/csvExport';
import { apReportsApi, type ApAgingRow } from '../../../lib/api/procurement/apReports';

export function ApAgingReportPage() {
  const [asOf, setAsOf] = useState('');
  const [partyId, setPartyId] = useState('');
  const [rows, setRows] = useState<ApAgingRow[]>([]);
  const [loading, setLoading] = useState(false);

  const csvRows = useMemo(
    () =>
      rows.map((r) => ({
        supplier_name: r.supplier_name,
        current: r.current,
        d1_30: r.d1_30,
        d31_60: r.d31_60,
        d61_90: r.d61_90,
        d90_plus: r.d90_plus,
        total_outstanding: r.total_outstanding,
      })),
    [rows],
  );

  async function run() {
    if (!asOf) return;
    setLoading(true);
    try {
      const res = await apReportsApi.aging({ as_of: asOf, party_id: partyId || undefined });
      setRows(res.rows);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ padding: 16 }}>
      <h2 style={{ margin: 0 }}>AP Aging</h2>
      <div style={{ display: 'flex', gap: 12, marginTop: 12, flexWrap: 'wrap' }}>
        <label>
          As of
          <input style={{ display: 'block' }} type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
        </label>
        <label>
          Supplier Party ID (optional)
          <input style={{ display: 'block', width: 360 }} value={partyId} onChange={(e) => setPartyId(e.target.value)} />
        </label>
        <button disabled={!asOf || loading} onClick={run}>
          {loading ? 'Loading…' : 'Run'}
        </button>
        <button
          disabled={rows.length === 0}
          onClick={() =>
            exportToCSV(csvRows, 'ap-aging.csv', undefined, {
              reportName: 'APAging',
              asOfDate: asOf,
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
              {['Supplier', 'Current', '1-30', '31-60', '61-90', '90+', 'Total'].map((h) => (
                <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: 8 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.party_id}>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.supplier_name}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.current}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.d1_30}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.d31_60}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.d61_90}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.d90_plus}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.total_outstanding}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} style={{ padding: 12, color: '#777' }}>
                  Pick an “As of” date and run the report.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

