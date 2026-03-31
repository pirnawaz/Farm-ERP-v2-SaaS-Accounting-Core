import { Link } from 'react-router-dom';
import { useFormatting } from '../hooks/useFormatting';

interface StatCardProps {
  title: string;
  value: string | number;
  link?: string;
  icon?: React.ReactNode;
}

export function StatCard({ title, value, link, icon }: StatCardProps) {
  const { formatNumber } = useFormatting();
  const display =
    typeof value === 'number' && Number.isFinite(value) ? formatNumber(value) : value;

  const content = (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium text-gray-600">{title}</p>
          <p className="text-2xl font-bold text-gray-900 mt-2">{display}</p>
        </div>
        {icon && <div className="text-gray-400">{icon}</div>}
      </div>
    </div>
  );

  if (link) {
    return <Link to={link}>{content}</Link>;
  }

  return content;
}
