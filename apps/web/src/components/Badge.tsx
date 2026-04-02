import type { HTMLAttributes, ReactNode } from 'react';

function cx(...parts: Array<string | null | undefined | false>): string {
  return parts.filter(Boolean).join(' ');
}

export type BadgeVariant = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
export type BadgeSize = 'sm' | 'md';

export type BadgeProps = {
  children: ReactNode;
  variant?: BadgeVariant;
  size?: BadgeSize;
  className?: string;
} & Omit<HTMLAttributes<HTMLSpanElement>, 'children' | 'className'>;

export function Badge(props: BadgeProps) {
  const { children, variant: variantProp, size: sizeProp, className, ...rest } = props;
  const variant = variantProp ?? 'neutral';
  const size = sizeProp ?? 'sm';

  const sizeClass = size === 'md' ? 'px-3 py-1 text-sm' : 'px-2.5 py-0.5 text-xs';
  const base = 'inline-flex items-center rounded-full border font-medium leading-none whitespace-nowrap';

  const variantClass =
    variant === 'success'
      ? 'bg-green-50 text-green-800 border-green-200'
      : variant === 'warning'
        ? 'bg-amber-50 text-amber-900 border-amber-200'
        : variant === 'danger'
          ? 'bg-red-50 text-red-900 border-red-200'
          : variant === 'info'
            ? 'bg-blue-50 text-blue-900 border-blue-200'
            : 'bg-gray-50 text-gray-800 border-gray-200';

  return (
    <span className={cx(base, sizeClass, variantClass, className)} {...rest}>
      {children}
    </span>
  );
}

