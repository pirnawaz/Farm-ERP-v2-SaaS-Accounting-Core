import type { ReactNode } from 'react';

function cx(...parts: Array<string | null | undefined | false>): string {
  return parts.filter(Boolean).join(' ');
}

export type KpiTone = 'neutral' | 'good' | 'bad';
export type KpiPadding = 'standard' | 'compact' | 'none';

export function KpiGrid(props: { children: ReactNode; className?: string }) {
  return <div className={cx('grid grid-cols-1 sm:grid-cols-3 gap-4', props.className)}>{props.children}</div>;
}

export function KpiCard(props: {
  label: string;
  value: ReactNode;
  helperText?: ReactNode;
  tone?: KpiTone;
  emphasized?: boolean;
  padding?: KpiPadding;
  className?: string;
}) {
  const tone = props.tone ?? 'neutral';
  const paddingClass =
    props.padding === 'none' ? '' : props.padding === 'compact' ? 'px-3 py-2' : 'p-4';

  const frame =
    props.emphasized || tone !== 'neutral'
      ? tone === 'bad'
        ? 'border-2 border-red-200 bg-red-50/40'
        : tone === 'good'
          ? 'border-2 border-[#1F6F5C]/20 bg-[#1F6F5C]/5'
          : 'border-2 border-gray-200 bg-white'
      : 'border border-gray-200 bg-white';

  const labelTone = tone === 'bad' ? 'text-red-700' : tone === 'good' ? 'text-gray-600' : 'text-gray-500';

  return (
    <div className={cx('rounded-xl', paddingClass, frame, props.className)}>
      <p className={cx('text-sm font-medium', labelTone)}>{props.label}</p>
      <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">{props.value}</p>
      {props.helperText != null && (
        <p className="mt-2 text-xs text-gray-400">{props.helperText}</p>
      )}
    </div>
  );
}

