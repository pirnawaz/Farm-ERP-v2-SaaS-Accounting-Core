import type { ReactNode } from 'react';

function cx(...parts: Array<string | null | undefined | false>): string {
  return parts.filter(Boolean).join(' ');
}

export function FilterBar(props: { children: ReactNode; className?: string }) {
  return <div className={cx('space-y-4', props.className)}>{props.children}</div>;
}

export function FilterGrid(props: { children: ReactNode; className?: string }) {
  return (
    <div
      className={cx(
        'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end',
        props.className
      )}
    >
      {props.children}
    </div>
  );
}

export function FilterField(props: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={props.className}>
      <label className="block text-sm font-medium text-gray-700 mb-1">{props.label}</label>
      <div className="[&_input]:w-full [&_input]:rounded-lg [&_input]:border-gray-300 [&_input]:px-3 [&_input]:py-2 [&_input]:focus:ring-[#1F6F5C] [&_input]:focus:border-[#1F6F5C] [&_select]:w-full [&_select]:rounded-lg [&_select]:border-gray-300 [&_select]:px-3 [&_select]:py-2 [&_select]:focus:ring-[#1F6F5C] [&_select]:focus:border-[#1F6F5C]">
        {props.children}
      </div>
    </div>
  );
}

export function FilterCheckboxField(props: {
  id: string;
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  className?: string;
}) {
  return (
    <div className={cx('flex items-center gap-2', props.className)}>
      <input
        type="checkbox"
        id={props.id}
        checked={props.checked}
        onChange={(e) => props.onChange(e.target.checked)}
        className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
      />
      <label htmlFor={props.id} className="text-sm font-medium text-gray-700">
        {props.label}
      </label>
    </div>
  );
}

