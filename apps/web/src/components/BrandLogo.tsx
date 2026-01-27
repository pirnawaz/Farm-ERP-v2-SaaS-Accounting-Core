interface BrandLogoProps {
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  alt?: string;
}

export function BrandLogo({ size = 'md', className = '', alt = 'Terrava' }: BrandLogoProps) {
  const sizeClasses = {
    sm: 'h-8',
    md: 'h-10',
    lg: 'h-16',
  };

  return (
    <img
      src="/brand/terrava_logo_clean.png"
      alt={alt}
      title="Terrava ERP"
      className={`${sizeClasses[size]} ${className}`}
    />
  );
}
