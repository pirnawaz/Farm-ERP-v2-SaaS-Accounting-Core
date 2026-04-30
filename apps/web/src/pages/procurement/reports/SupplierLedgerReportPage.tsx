import { useEffect, useMemo, useState } from 'react';
import { exportToCSV } from '../../../utils/csvExport';
import { apReportsApi, type SupplierLedgerRow } from '../../../lib/api/procurement/apReports';

export function SupplierLedgerReportPage() {
  const [partyId, setPartyId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [rows, setRows] = useState<SupplierLedgerRow[]>([]);
  const [supplierName, setSupplierName] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const canRun = partyId.trim().length > 0;

  const csvRows = useMemo(
    () =>
      rows.map((r) => ({
        date: r.date,
        type: r.type,
        reference: r.reference ?? '',
        debit: r.debit,
        credit: r.credit,
        running_balance: r.running_balance,
      })),
    [rows],
  );

  async function run() {
    if (!canRun) return;
    setLoading(true);
    try {
      const res = await apReportsApi.supplierLedger({
        party_id: partyId.trim(),
        from: from || undefined,
        to: to || undefined,
      });
      setRows(res.rows);
      setSupplierName(res.supplier_name);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    setRows([]);
    setSupplierName(null);
  }, [partyId]);

  return (
    <div style={{ padding: 16 }}>
      <h2 style={{ margin: 0 }}>Supplier Ledger</h2>
      <div style={{ display: 'flex', gap: 12, marginTop: 12, flexWrap: 'wrap' }}>
        <label>
          Supplier Party ID
          <input style={{ display: 'block', width: 360 }} value={partyId} onChange={(e) => setPartyId(e.target.value)} />
        </label>
        <label>
          From
          <input style={{ display: 'block' }} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>
          To
          <input style={{ display: 'block' }} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
        <button disabled={!canRun || loading} onClick={run}>
          {loading ? 'Loading…' : 'Run'}
        </button>
        <button
          disabled={rows.length === 0}
          onClick={() =>
            exportToCSV(csvRows, 'supplier-ledger.csv', undefined, {
              reportName: 'SupplierLedger',
              fromDate: from || undefined,
              toDate: to || undefined,
              metadataRows: supplierName ? [['Supplier', supplierName], ['PartyId', partyId.trim()]] : undefined,
            })
          }
        >
          Export CSV
        </button>
      </div>

      {supplierName && <div style={{ marginTop: 12, color: '#555' }}>Supplier: {supplierName}</div>}

      <div style={{ overflowX: 'auto', marginTop: 16 }}>
        <table style={{ borderCollapse: 'collapse', width: '100%' }}>
          <thead>
            <tr>
              {['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Running balance'].map((h) => (
                <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: 8 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.ref_id}>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.date}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.type}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.reference ?? ''}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.debit}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.credit}</td>
                <td style={{ padding: 8, borderBottom: '1px solid #f0f0f0' }}>{r.running_balance}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={6} style={{ padding: 12, color: '#777' }}>
                  Enter a Supplier ID and run the report.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

