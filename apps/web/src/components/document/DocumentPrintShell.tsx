import { ReactNode } from 'react';

/**
 * Root wrapper for browser-printed documents (invoice, statement, etc.).
 * Pairs with `.print-document` rules in `index.css`.
 */
export function DocumentPrintShell({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`print-document hidden ${className}`}>{children}</div>;
}
