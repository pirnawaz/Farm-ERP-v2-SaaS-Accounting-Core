import { Badge } from './Badge';

export type SetupCompleteness = 'COMPLETE' | 'PARTIAL' | 'NOT_SET';

export function SetupStatusBadge(props: {
  present: boolean;
  presentLabel?: string;
  missingLabel?: string;
  size?: 'sm' | 'md';
  className?: string;
}) {
  const { present, presentLabel = 'Present', missingLabel = 'Missing', size = 'sm', className } = props;
  return (
    <Badge variant={present ? 'success' : 'neutral'} size={size} className={className}>
      {present ? presentLabel : missingLabel}
    </Badge>
  );
}

export function SetupCompletenessBadge(props: { completeness: SetupCompleteness; size?: 'sm' | 'md' }) {
  const { completeness, size = 'sm' } = props;
  const variant = completeness === 'COMPLETE' ? 'success' : completeness === 'PARTIAL' ? 'warning' : 'neutral';
  return (
    <Badge variant={variant} size={size}>
      {completeness}
    </Badge>
  );
}

