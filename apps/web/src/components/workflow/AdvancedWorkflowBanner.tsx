import { Link } from 'react-router-dom';

type Props = {
  /**
   * When true, explains that capturing the same activity in multiple places can cause posting issues.
   * @default true
   */
  duplicateRiskWarning?: boolean;
};

/**
 * Shown on secondary/manual operational screens so users are steered toward
 * Field Jobs (work) and Harvests (output + sharing) as the primary workflows.
 */
export function AdvancedWorkflowBanner({ duplicateRiskWarning = true }: Props) {
  return (
    <div
      role="status"
      className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p className="font-medium">This is an advanced/manual workflow</p>
      <p className="mt-1 text-amber-900/90">
        For normal operations, use{' '}
        <Link
          to="/app/crop-ops/field-jobs"
          className="font-medium text-[#1F6F5C] underline underline-offset-2 hover:text-[#1a5a4a]"
        >
          Field Jobs
        </Link>{' '}
        to record work, and{' '}
        <Link
          to="/app/harvests"
          className="font-medium text-[#1F6F5C] underline underline-offset-2 hover:text-[#1a5a4a]"
        >
          Harvests
        </Link>{' '}
        to record output and sharing.
      </p>
      {duplicateRiskWarning ? (
        <p className="mt-2 border-t border-amber-200/80 pt-2 text-amber-950/95">
          Recording the same machine time, labour, or harvest output in more than one document can trigger duplicate
          checks at posting. Prefer one field job for field work, then a harvest for quantities and shares.
        </p>
      ) : null}
    </div>
  );
}
