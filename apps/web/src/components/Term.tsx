import { term, accountingTerm, type TermKey } from '../config/terminology';

interface TermProps {
  k: TermKey;
  showHint?: boolean;
  className?: string;
}

/**
 * Renders farm-first terminology. Use showHint to show the accounting term as a muted suffix.
 */
export function Term({ k, showHint = false, className = '' }: TermProps) {
  if (showHint) {
    return (
      <span className={className}>
        {term(k)}
        <span className="text-gray-500 text-xs font-normal ml-1" title={accountingTerm(k)}>
          ({accountingTerm(k)})
        </span>
      </span>
    );
  }
  return <span className={className}>{term(k)}</span>;
}
