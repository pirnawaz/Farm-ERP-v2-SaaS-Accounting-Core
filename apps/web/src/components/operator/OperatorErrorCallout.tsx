import type { OperatorFriendlyError } from '../../utils/operatorFriendlyErrors';

type Props = {
  error: OperatorFriendlyError | null;
  className?: string;
};

/** Shows friendly operator copy; optional raw message in a collapsible detail for support. */
export function OperatorErrorCallout({ error, className = '' }: Props) {
  if (!error?.friendly) return null;
  return (
    <div className={`rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 ${className}`}>
      <p className="font-medium">{error.friendly}</p>
      {error.raw ? (
        <details className="mt-2 text-xs text-red-800/80">
          <summary className="cursor-pointer select-none hover:underline">Technical detail</summary>
          <pre className="mt-1 whitespace-pre-wrap break-words font-mono">{error.raw}</pre>
        </details>
      ) : null}
    </div>
  );
}
