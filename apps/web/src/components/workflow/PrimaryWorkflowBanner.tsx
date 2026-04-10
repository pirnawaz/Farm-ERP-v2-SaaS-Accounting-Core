import { Link } from 'react-router-dom';

type Variant = 'field-job' | 'harvest';

const COPY: Record<
  Variant,
  { title: string; body: string }
> = {
  'field-job': {
    title: 'Primary workflow — field work',
    body: 'Use this to record all work (labour, machinery, inputs).',
  },
  harvest: {
    title: 'Primary workflow — harvest',
    body: 'Use this to record output and sharing.',
  },
};

/**
 * Sage callout for the main crop operations path (field jobs → harvests → sales).
 * Does not mutate data or auto-navigate.
 */
export function PrimaryWorkflowBanner({ variant }: { variant: Variant }) {
  const { title, body } = COPY[variant];

  return (
    <div
      role="region"
      aria-label={title}
      className="rounded-lg border border-[#1F6F5C]/25 bg-[#EEF5F3] px-4 py-3 text-sm text-gray-900 shadow-sm"
    >
      <p className="font-semibold text-[#145044]">{title}</p>
      <p className="mt-1 text-gray-800">{body}</p>
      <p className="mt-2 text-xs text-gray-700">
        Typical sequence:{' '}
        <Link className="font-medium text-[#1F6F5C] underline underline-offset-2 hover:text-[#1a5a4a]" to="/app/crop-ops/field-jobs">
          Field job
        </Link>{' '}
        →{' '}
        <Link className="font-medium text-[#1F6F5C] underline underline-offset-2 hover:text-[#1a5a4a]" to="/app/harvests">
          Harvest
        </Link>{' '}
        →{' '}
        <Link className="font-medium text-[#1F6F5C] underline underline-offset-2 hover:text-[#1a5a4a]" to="/app/sales">
          Sale
        </Link>
        .
      </p>
    </div>
  );
}
