import { useState } from 'react';
import { ConfirmDialog } from './ConfirmDialog';

interface PostButtonProps {
  onClick: () => void;
  disabled?: boolean;
  isPending?: boolean;
  isCycleClosed?: boolean;
  cycleName?: string;
  className?: string;
}

export function PostButton({
  onClick,
  disabled = false,
  isPending = false,
  isCycleClosed = false,
  cycleName,
  className = '',
}: PostButtonProps) {
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
            <strong>Cannot post:</strong> {cycleName ? `Crop cycle "${cycleName}" is closed.` : 'Crop cycle is closed.'} Posting is disabled for closed cycles.
          </div>
        )}
        {!isCycleClosed && (
          <div className="bg-orange-50 border border-orange-200 rounded-md p-3 text-sm text-orange-800">
            <strong>Warning:</strong> This action is irreversible. Posting will create accounting entries that cannot be modified. Only reversal is allowed.
          </div>
        )}
        <button
          type="button"
          onClick={handleClick}
          disabled={isDisabled}
          className={`px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed ${className}`}
        >
          {isPending ? 'Posting...' : 'Post'}
        </button>
      </div>

      <ConfirmDialog
        isOpen={showConfirm}
        onClose={() => setShowConfirm(false)}
        onConfirm={handleConfirm}
        title="Confirm Post"
        message="Are you sure you want to post this document? This will create accounting entries that cannot be modified. Only reversal is allowed."
        confirmText="Post"
        variant="default"
      />
    </>
  );
}
