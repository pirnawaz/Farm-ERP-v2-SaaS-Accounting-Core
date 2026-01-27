import { useFormatting } from '../../hooks/useFormatting';

interface PrintHeaderProps {
  title: string;
  subtitle?: string;
  metaLeft?: string;
  metaRight?: string;
  showLogo?: boolean;
}

export function PrintHeader({ 
  title, 
  subtitle, 
  metaLeft, 
  metaRight,
  showLogo = true 
}: PrintHeaderProps) {
  const { formatDate } = useFormatting();
  
  // Default metaRight to current date if not provided
  const generatedDate = metaRight || formatDate(new Date());

  return (
    <div className="print-header hidden">
      <div className="print-header-content">
        <div className="print-header-top">
          {showLogo && (
            <div className="print-logo">
              <img 
                src="/brand/terrava_logo_clean.png" 
                alt="Terrava" 
                className="h-8 w-auto"
                onError={(e) => {
                  // Fallback if logo doesn't exist - hide the image
                  (e.target as HTMLImageElement).style.display = 'none';
                }}
              />
            </div>
          )}
        </div>
        <div className="print-title-section">
          <h1 className="print-title">{title}</h1>
          {subtitle && <div className="print-subtitle">{subtitle}</div>}
        </div>
        {(metaLeft || metaRight) && (
          <div className="print-meta-row">
            {metaLeft && <div className="print-meta-left">{metaLeft}</div>}
            {metaRight && <div className="print-meta-right">Generated: {generatedDate}</div>}
          </div>
        )}
        <div className="print-header-divider"></div>
      </div>
    </div>
  );
}
