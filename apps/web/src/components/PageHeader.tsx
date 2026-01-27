import { useNavigate, useLocation, Link } from 'react-router-dom';
import type { ReactNode } from 'react';

export interface Breadcrumb {
  label: string;
  to?: string;
}

/**
 * Page header with Back, breadcrumbs, and optional actions.
 * Use only on internal pages (lists, details, forms)—not on module root/main
 * pages (e.g. /app/inventory, /app/dashboard). On root pages use a plain h1.
 */
interface PageHeaderProps {
  title: string;
  backTo?: string;
  breadcrumbs?: Breadcrumb[];
  right?: ReactNode;
}

export function PageHeader({ title, backTo, breadcrumbs, right }: PageHeaderProps) {
  const navigate = useNavigate();
  const location = useLocation();

  const handleBack = () => {
    const from = (location.state as { from?: string } | null)?.from;
    if (from) {
      navigate(from);
      return;
    }
    if (window.history.length > 1) {
      navigate(-1);
      return;
    }
    if (backTo) {
      navigate(backTo);
      return;
    }
    navigate('/app/dashboard');
  };

  return (
    <div className="mb-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="min-w-0">
          <button
            type="button"
            onClick={handleBack}
            className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block text-sm"
          >
            ← Back
          </button>
          {breadcrumbs && breadcrumbs.length > 0 && (
            <nav className="flex items-center gap-1 text-sm text-gray-500 mb-1">
              {breadcrumbs.map((b, i) => (
                <span key={i} className="flex items-center gap-1">
                  {i > 0 && <span className="text-gray-400">/</span>}
                  {b.to ? (
                    <Link to={b.to} className="text-[#1F6F5C] hover:text-[#1a5a4a]">
                      {b.label}
                    </Link>
                  ) : (
                    <span>{b.label}</span>
                  )}
                </span>
              ))}
            </nav>
          )}
          <h1 className="text-2xl font-semibold text-gray-900">{title}</h1>
        </div>
        {right && <div className="flex-shrink-0">{right}</div>}
      </div>
    </div>
  );
}
