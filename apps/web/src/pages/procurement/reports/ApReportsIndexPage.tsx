import { Link } from 'react-router-dom';

export function ApReportsIndexPage() {
  return (
    <div style={{ padding: 16 }}>
      <h2 style={{ margin: 0 }}>Procurement → Reports</h2>
      <p style={{ marginTop: 8, color: '#555' }}>
        Read-only Accounts Payable reports derived from posted supplier bills and payments.
      </p>

      <div style={{ display: 'grid', gap: 12, marginTop: 16 }}>
        <Link to="/app/procurement/reports/supplier-ledger">Supplier Ledger</Link>
        <Link to="/app/procurement/reports/unpaid-bills">Unpaid Bills</Link>
        <Link to="/app/procurement/reports/ap-aging">AP Aging</Link>
        <Link to="/app/procurement/reports/credit-premium">Credit Premium by Crop/Project</Link>
      </div>
    </div>
  );
}

