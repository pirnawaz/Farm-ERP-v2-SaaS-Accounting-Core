import { Fragment, ReactNode } from 'react';

export type DocumentMetaItem = { label: string; value: ReactNode };

/**
 * Two-column metadata grid (document number, dates, party, project, etc.).
 */
export function DocumentMetaGrid({
  leftColumn,
  rightColumn,
}: {
  leftColumn: DocumentMetaItem[];
  rightColumn: DocumentMetaItem[];
}) {
  return (
    <div className="print-document-meta">
      <div>
        <dl>
          {leftColumn.map((item, i) => (
            <Fragment key={`l-${i}`}>
              <dt>{item.label}</dt>
              <dd>{item.value}</dd>
            </Fragment>
          ))}
        </dl>
      </div>
      <div>
        <dl>
          {rightColumn.map((item, i) => (
            <Fragment key={`r-${i}`}>
              <dt>{item.label}</dt>
              <dd>{item.value}</dd>
            </Fragment>
          ))}
        </dl>
      </div>
    </div>
  );
}
