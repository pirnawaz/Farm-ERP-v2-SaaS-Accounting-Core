type CheckItem = { ok: boolean; label: string };

type PrePostChecklistProps = {
  items: CheckItem[];
  /** Shown when not all items pass (e.g. above POST button) */
  blockingHint?: string;
  className?: string;
};

/**
 * Lightweight checklist for front-end “safe draft” gating before POST/save.
 */
export function PrePostChecklist({ items, blockingHint, className = '' }: PrePostChecklistProps) {
  const allOk = items.length > 0 && items.every((i) => i.ok);

  return (
    <div
      className={`rounded-lg border border-gray-200 bg-gray-50/90 px-3 py-3 text-sm ${className}`}
      role="status"
      aria-label="Readiness checklist"
    >
      {!allOk && blockingHint ? (
        <p className="text-amber-900 font-medium mb-2">{blockingHint}</p>
      ) : null}
      <ul className="space-y-1.5">
        {items.map((it, idx) => (
          <li
            key={idx}
            className={`flex items-start gap-2 ${it.ok ? 'text-green-900' : 'text-gray-600'}`}
          >
            <span className="select-none w-5 shrink-0 text-center" aria-hidden>
              {it.ok ? '✓' : '○'}
            </span>
            <span>{it.label}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
