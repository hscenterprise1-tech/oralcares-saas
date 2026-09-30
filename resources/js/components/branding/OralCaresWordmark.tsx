import React from 'react';

export type WordmarkSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';
export type WordmarkVariant = 'default' | 'monochrome-dark' | 'monochrome-light' | 'white';

export interface WordmarkProps {
  size?: WordmarkSize;
  variant?: WordmarkVariant;
  subtitle?: string | null;
  className?: string;
  customOralColor?: string;
  customCaresColor?: string;
}

export function OralCaresWordmark({
  size = 'md',
  variant = 'default',
  subtitle = 'Enterprise OS',
  className = '',
  customOralColor,
  customCaresColor,
}: WordmarkProps) {
  const sizeConfig: Record<WordmarkSize, { title: string; subtitle: string; gap: string }> = {
    xs: { title: 'text-[12px]', subtitle: 'text-[9px]', gap: 'gap-0.5' },
    sm: { title: 'text-[13px]', subtitle: 'text-[10px]', gap: 'gap-0.5' },
    md: { title: 'text-[15px]', subtitle: 'text-[11px]', gap: 'gap-0.5' },
    lg: { title: 'text-[18px]', subtitle: 'text-[12px]', gap: 'gap-1' },
    xl: { title: 'text-[22px]', subtitle: 'text-[13px]', gap: 'gap-1' },
    '2xl': { title: 'text-[28px]', subtitle: 'text-[14px]', gap: 'gap-1.5' },
  };

  const { title: titleSize, subtitle: subSize, gap: gapSize } = sizeConfig[size] || sizeConfig.md;

  // Determine colors based on variant & custom overrides
  let oralColor = '#0A2540';
  let caresColor = '#17B6C8';
  let subColor = 'text-[#64748B]';

  if (variant === 'monochrome-dark') {
    oralColor = '#0A2540';
    caresColor = '#0A2540';
    subColor = 'text-[#64748B]';
  } else if (variant === 'monochrome-light' || variant === 'white') {
    oralColor = '#FFFFFF';
    caresColor = '#FFFFFF';
    subColor = 'text-white/70';
  }

  if (customOralColor) oralColor = customOralColor;
  if (customCaresColor) caresColor = customCaresColor;

  return (
    <div className={`flex flex-col min-w-0 ${gapSize} select-none ${className}`}>
      <div className={`${titleSize} font-extrabold leading-none tracking-tight flex items-center`}>
        <span style={{ color: oralColor }}>Oral</span>
        <span style={{ color: caresColor }}>Cares</span>
      </div>
      {subtitle && (
        <span className={`${subSize} font-semibold ${subColor} leading-none tracking-normal font-sans`}>
          {subtitle}
        </span>
      )}
    </div>
  );
}

