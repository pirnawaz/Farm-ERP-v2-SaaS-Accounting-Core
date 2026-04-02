import type { ReactNode } from 'react';

function cx(...parts: Array<string | null | undefined | false>): string {
  return parts.filter(Boolean).join(' ');
}

export function FormCard(props: { children: ReactNode; className?: string }) {
  return (
    <div
      className={cx(
        'bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-6 space-y-6',
        props.className
      )}
    >
      {props.children}
    </div>
  );
}

export function FormSection(props: {
  title: string;
  description?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cx('space-y-4', props.className)}>
      <div>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">{props.title}</h2>
        {props.description != null && (
          <div className="mt-1 text-sm text-gray-500">{props.description}</div>
        )}
      </div>
      {props.children}
    </section>
  );
}

export function FormActions(props: { children: ReactNode; className?: string }) {
  return (
    <div className={cx('pt-2 border-t border-gray-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3', props.className)}>
      {props.children}
    </div>
  );
}

