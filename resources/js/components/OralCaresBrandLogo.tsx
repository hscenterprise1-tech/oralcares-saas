import React from 'react';
import { OralCaresLogo, LogoSize } from '@/components/branding/OralCaresLogo';

export interface OralCaresBrandLogoProps {
  size?: 'sm' | 'md' | 'lg';
  showText?: boolean;
  className?: string;
  subtitle?: string;
}

export function OralCaresBrandLogo({
  size = 'md',
  showText = true,
  className = '',
  subtitle = 'Enterprise OS',
}: OralCaresBrandLogoProps) {
  return (
    <OralCaresLogo
      size={size as LogoSize}
      showText={showText}
      subtitle={subtitle}
      className={className}
    />
  );
}

