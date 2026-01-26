import { useState } from 'react';
import { ConfirmDialog } from './ConfirmDialog';

interface ReverseButtonProps {
  onClick: () => void;
  disabled?: boolean;
  isPending?: boolean;
  isCycleClosed?: boolean;
  cycleName?: string;
  className?: string;
}

export function ReverseButton({
  onClick,
  disabled = false,
  isPending = false,
  isCycleClosed = false,
  cycleName,
  className = '',
}: ReverseButtonProps) {
  const [showConfirm, setShowConfirm] = useState(false);

  const handleClick = () => {
    setShowConfirm(true);
  };

  const handleConfirm = () => {
    setShowConfirm(false);
    onClick();
  };

  const isDisabled = disabled || isPending || isCycleClosed;

  return (
    <>
      <div className="space-y-2">
        {isCycleClosed && (
          <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3 text-sm text-yellow-800">
            <strong>Cannot reverse:</strong> {cycleName ? `Crop cycle "${cycleName}" is closed.` : 'Crop cycle is closed.'} Reversals are disabled for closed cycles.
          </div>
        )}
        {!isCycleClosed && (
          <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">
            <strong>Warning:</strong> Reversing this document will create offsetting entries. This action cannot be undone.
          </div>
        )}
        <button
          type="button"
          onClick={handleClick}
          disabled={isDisabled}
          className={`px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed ${className}`}
        >
          {isPending ? 'Reversing...' : 'Reverse'}
        </button>
      </div>

      <ConfirmDialog
        isOpen={showConfirm}
        onClose={() => setShowConfirm(false)}
        onConfirm={handleConfirm}
        title="Confirm Reverse"
        message="Are you sure you want to reverse this document? This will create offsetting entries and cannot be undone."
        confirmText="Reverse"
        variant="danger"
      />
    </>
  );
}
