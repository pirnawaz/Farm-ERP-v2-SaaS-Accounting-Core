/**
 * Screen-only hint component that displays guidance about disabling browser headers/footers
 * in the print dialog for best PDF output.
 */
export function PrintHint() {
  return (
    <div className="no-print text-xs text-gray-500 mt-2">
      <span className="inline-flex items-center gap-1">
        <svg
          className="w-4 h-4"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        For best PDF output: disable &quot;Headers and footers&quot; in the print dialog.
      </span>
    </div>
  );
}
