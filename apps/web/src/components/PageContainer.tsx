import type { ReactNode } from 'react';

export type PageWidthVariant = 'standard' | 'form' | 'narrow';

/**
 * Terrava page-width guardrail.
 *
 * Why this exists:
 * - The app shell (`AppLayout`) already provides the canonical "content column" width (`max-w-7xl ...`).
 * - Over time, pages drifted into ad-hoc `max-w-* mx-auto` wrappers, creating inconsistent whitespace.
 * - `PageContainer` keeps page-level width decisions explicit, reusable, and consistent.
 *
 * Width variants:
 * - **standard** (default): Use for dashboards, lists, detail pages, drilldowns, and reports.
 *   This intentionally applies **no additional max-width** so the page inherits the shell width.
 * - **form**: Use for primary create/edit flows where a centered, wide form column is the product pattern
 *   (e.g. activity/issue/GRN/sale forms). Keeps forms readable without feeling "too narrow".
 * - **narrow**: Rare. Use only when the page is intentionally constrained (reading, wizard, confirmation,
 *   modal-like standalone screens). Avoid for normal app pages.
 *
 * Guidance:
 * - Prefer `PageContainer` over hardcoded page-level `max-w-*` utility classes.
 * - New arbitrary page wrappers should be treated as an exception with an intentional UX reason.
 * - This component should not change shell/layout behavior; it only adds an optional page-level wrapper.
 */
function cx(...parts: Array<string | null | undefined | false>): string {
  return parts.filter(Boolean).join(' ');
}

export function PageContainer({
  children,
  width = 'standard',
  className,
}: {
  children: ReactNode;
  width?: PageWidthVariant;
  className?: string;
}) {
  const widthClass =
    width === 'form'
      ? 'max-w-5xl mx-auto'
      : width === 'narrow'
        ? 'max-w-2xl mx-auto'
        : '';

  return <div className={cx(widthClass, className)}>{children}</div>;
}

