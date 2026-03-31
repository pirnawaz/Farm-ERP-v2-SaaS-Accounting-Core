import { LoadingSpinner } from '../LoadingSpinner';

function toMessage(err: unknown): string | undefined {
  if (!err) return undefined;
  if (typeof err === 'string') return err;
  if (err instanceof Error) return err.message;
  try {
    return JSON.stringify(err);
  } catch {
    return undefined;
  }
}

export function ReportLoadingState(props: { label?: string; className?: string }) {
  const label = props.label ?? 'Loading report...';
  return (
    <div className={`bg-white rounded-lg shadow p-8 ${props.className ?? ''}`}>
      <div className="flex flex-col items-center justify-center gap-3 text-gray-600">
        <LoadingSpinner size="lg" />
        <div className="text-sm font-medium">{label}</div>
      </div>
    </div>
  );
}

export function ReportErrorState(props: {
  title?: string;
  error?: unknown;
  fallbackMessage?: string;
  className?: string;
}) {
  const title = props.title ?? 'We couldn’t load this report.';
  const msg = toMessage(props.error) ?? props.fallbackMessage ?? 'Please try again.';
  return (
    <div className={`bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded ${props.className ?? ''}`}>
      <div className="font-medium">{title}</div>
      <div className="text-sm mt-1 whitespace-pre-wrap">{msg}</div>
    </div>
  );
}

export function ReportEmptyStateCard(props: { message: string; className?: string }) {
  return (
    <div className={`bg-white rounded-lg shadow p-8 text-center text-gray-600 ${props.className ?? ''}`}>
      <div className="text-sm">{props.message}</div>
    </div>
  );
}

