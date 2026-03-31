import { useEffect, useId, useMemo, useRef, useState } from 'react';

export type SearchableSelectOption = { value: string; label: string };

type SearchableSelectProps = {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  options: SearchableSelectOption[];
  disabled?: boolean;
  placeholder?: string;
  searchPlaceholder?: string;
  className?: string;
};

const inputStyles =
  'w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100 disabled:cursor-not-allowed';

export function SearchableSelect({
  id,
  value,
  onChange,
  options,
  disabled,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  className = '',
}: SearchableSelectProps) {
  const reactId = useId();
  const listboxId = `${reactId}-listbox`;
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const rootRef = useRef<HTMLDivElement>(null);

  const selectedLabel = useMemo(() => {
    const label = options.find((o) => o.value === value)?.label;
    if (label !== undefined) {
      return label;
    }
    return value || placeholder;
  }, [options, value, placeholder]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) {
      return options;
    }
    return options.filter(
      (o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q),
    );
  }, [options, query]);

  useEffect(() => {
    if (!open) {
      setQuery('');
    }
  }, [open]);

  useEffect(() => {
    function onDocMouseDown(e: MouseEvent) {
      if (!rootRef.current?.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocMouseDown);
    return () => document.removeEventListener('mousedown', onDocMouseDown);
  }, []);

  if (disabled) {
    return (
      <div className={`relative ${className}`}>
        <input id={id} readOnly value={selectedLabel} disabled className={`${inputStyles} bg-gray-100`} />
      </div>
    );
  }

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <button
        type="button"
        id={id}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listboxId}
        className={`${inputStyles} text-left flex justify-between items-center gap-2`}
        onClick={() => setOpen((o) => !o)}
      >
        <span className="truncate">{selectedLabel}</span>
        <span className="text-gray-400 text-xs shrink-0" aria-hidden>
          {open ? '▲' : '▼'}
        </span>
      </button>
      {open ? (
        <div className="absolute z-50 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg max-h-72 flex flex-col">
          <input
            type="search"
            className="border-b border-gray-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-inset focus:ring-[#1F6F5C]"
            placeholder={searchPlaceholder}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            autoComplete="off"
            autoFocus
            onKeyDown={(e) => e.stopPropagation()}
          />
          <ul id={listboxId} role="listbox" className="overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-sm text-gray-500">No matches</li>
            ) : (
              filtered.map((o) => (
                <li
                  key={o.value}
                  role="option"
                  aria-selected={o.value === value}
                  className={`px-3 py-2 text-sm cursor-pointer hover:bg-[#E6ECEA] ${
                    o.value === value ? 'bg-[#E6ECEA]/80 font-medium' : ''
                  }`}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => {
                    onChange(o.value);
                    setOpen(false);
                  }}
                >
                  {o.label}
                </li>
              ))
            )}
          </ul>
        </div>
      ) : null}
    </div>
  );
}
