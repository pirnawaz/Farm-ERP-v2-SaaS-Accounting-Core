import { ReactNode } from 'react';

export function DocumentLineItemsTable({
  columns,
  children,
}: {
  columns: { label: string; align?: 'left' | 'right' }[];
  children: ReactNode;
}) {
  return (
    <div className="print-line-items">
      <table className="min-w-full">
        <thead>
          <tr>
            {columns.map((c) => (
              <th
                key={c.label}
                className={c.align === 'right' ? 'text-right' : 'text-left'}
              >
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
