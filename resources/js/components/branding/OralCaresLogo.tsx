import React from 'react';
import { OralCaresMonogram, MonogramSize, MonogramVariant } from './OralCaresMonogram';
import { OralCaresWordmark, WordmarkSize, WordmarkVariant } from './OralCaresWordmark';

export type LogoSize = MonogramSize;
export type LogoVariant = 'default' | 'monochrome-dark' | 'monochrome-light' | 'white' | 'inverted';
export type LogoLayout = 'horizontal' | 'vertical';

export interface LogoProps {
  size?: LogoSize;
  variant?: LogoVariant;
  layout?: LogoLayout;
  showText?: boolean;
  subtitle?: string | null;
  className?: string;
  clearspaceGuide?: boolean;
  customOralColor?: string;
  customCaresColor?: string;
}

export function OralCaresLogo({
  size = 'md',
  variant = 'default',
  layout = 'horizontal',
  showText = true,
  subtitle = 'Enterprise OS',
  className = '',
  clearspaceGuide = false,
  customOralColor,
  customCaresColor,
}: LogoProps) {
  // Map variant to child components
  const monogramVariant: MonogramVariant =
    variant === 'white' ? 'monochrome-light' : variant;

  const wordmarkVariant: WordmarkVariant =
    variant === 'inverted' ? 'monochrome-dark' : variant;

  const gapConfig: Record<LogoSize, { horizontal: string; vertical: string }> = {
    xs: { horizontal: 'gap-2', vertical: 'gap-1.5' },
    sm: { horizontal: 'gap-2.5', vertical: 'gap-2' },
    md: { horizontal: 'gap-3', vertical: 'gap-2.5' },
    lg: { horizontal: 'gap-3.5', vertical: 'gap-3' },
    xl: { horizontal: 'gap-4', vertical: 'gap-3.5' },
    '2xl': { horizontal: 'gap-5', vertical: 'gap-4' },
  };

  const gap = layout === 'vertical' ? gapConfig[size].vertical : gapConfig[size].horizontal;
  const layoutClasses = layout === 'vertical' ? 'flex-col items-center text-center' : 'flex-row items-center text-left';

  const logoNode = (
    <div className={`flex ${layoutClasses} ${gap} select-none ${className}`}>
      <OralCaresMonogram size={size} variant={monogramVariant} />
      {showText && (
        <OralCaresWordmark
          size={size}
          variant={wordmarkVariant}
          subtitle={subtitle}
          customOralColor={customOralColor}
          customCaresColor={customCaresColor}
        />
      )}
    </div>
  );

  if (!clearspaceGuide) {
    return logoNode;
  }

  // Clearspace overlay representation (X-box margins around lockup)
  return (
    <div className="relative inline-flex p-4 border border-dashed border-sky-400/40 bg-sky-50/20 rounded-xl items-center justify-center">
      {/* Corner indicators */}
      <span className="absolute top-1 left-1.5 text-[9px] font-mono text-sky-500 font-bold uppercase select-none">0.5X</span>
      <span className="absolute top-1 right-1.5 text-[9px] font-mono text-sky-500 font-bold uppercase select-none">0.5X</span>
      <span className="absolute bottom-1 left-1.5 text-[9px] font-mono text-sky-500 font-bold uppercase select-none">0.5X</span>
      <span className="absolute bottom-1 right-1.5 text-[9px] font-mono text-sky-500 font-bold uppercase select-none">0.5X</span>
      {logoNode}
    </div>
  );
}

