import { ReactNode } from 'react';

export function DocumentNotesBlock({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="document-notes print-footer mt-4 pt-3 text-sm">
      <p>
        <strong>{title}:</strong> {children}
      </p>
    </div>
  );
}
