import React, { useId } from 'react';

export type MonogramSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';
export type MonogramVariant = 'default' | 'monochrome-dark' | 'monochrome-light' | 'inverted' | 'outline';

export interface MonogramProps {
  size?: MonogramSize;
  variant?: MonogramVariant;
  className?: string;
}

export function OralCaresMonogram({
  size = 'md',
  variant = 'default',
  className = '',
}: MonogramProps) {
  const gradientId = useId().replace(/:/g, '_');

  const sizeClasses: Record<MonogramSize, { container: string; radius: string; padding: string }> = {
    xs: { container: 'w-5 h-5', radius: 'rounded-md', padding: 'p-0.5' },
    sm: { container: 'w-7 h-7', radius: 'rounded-lg', padding: 'p-1' },
    md: { container: 'w-9 h-9', radius: 'rounded-xl', padding: 'p-1.5' },
    lg: { container: 'w-11 h-11', radius: 'rounded-xl', padding: 'p-2' },
    xl: { container: 'w-14 h-14', radius: 'rounded-2xl', padding: 'p-2.5' },
    '2xl': { container: 'w-20 h-20', radius: 'rounded-3xl', padding: 'p-3.5' },
  };

  const { container, radius, padding } = sizeClasses[size] || sizeClasses.md;

  // Variant styling configurations
  const variantContainerClasses: Record<MonogramVariant, string> = {
    default: 'bg-[#0A2540] border border-[#0A2540]/30 shadow-xs text-white',
    'monochrome-dark': 'bg-[#0A2540] border border-[#0A2540]/40 shadow-xs text-white',
    'monochrome-light': 'bg-white/10 backdrop-blur-xs border border-white/20 text-white shadow-xs',
    inverted: 'bg-white border border-[#E2E8F0] shadow-2xs text-[#0A2540]',
    outline: 'bg-transparent border border-current text-current',
  };

  return (
    <div
      className={`${container} ${radius} ${padding} ${variantContainerClasses[variant]} flex items-center justify-center shrink-0 relative overflow-hidden select-none ${className}`}
      aria-label="OralCares Brand Emblem"
    >
      <svg
        viewBox="0 0 40 40"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className="w-full h-full"
      >
        <defs>
          <linearGradient id={`ocGrad_${gradientId}`} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#17B6C8" />
            <stop offset="50%" stopColor="#1463A5" />
            <stop offset="100%" stopColor="#00E5FF" />
          </linearGradient>
          <linearGradient id={`ocCyanGrad_${gradientId}`} x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stopColor="#17B6C8" />
            <stop offset="100%" stopColor="#00E5FF" />
          </linearGradient>
        </defs>

        {variant === 'default' ? (
          <>
            {/* Outer Shield Ring in Vibrant Cyan & Mid Blue */}
            <path
              d="M20 4C11.163 4 4 11.163 4 20C4 28.837 11.163 36 20 36C28.837 36 36 28.837 36 20"
              stroke={`url(#ocGrad_${gradientId})`}
              strokeWidth="4"
              strokeLinecap="round"
            />
            {/* Inner Tooth Check Emblem in Bright White & Cyan */}
            <path
              d="M14 20L18.5 24.5L27 13"
              stroke="#FFFFFF"
              strokeWidth="4"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="M14 20L18.5 24.5L27 13"
              stroke={`url(#ocCyanGrad_${gradientId})`}
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              opacity="0.9"
            />
          </>
        ) : variant === 'monochrome-dark' || variant === 'monochrome-light' ? (
          <>
            <path
              d="M20 4C11.163 4 4 11.163 4 20C4 28.837 11.163 36 20 36C28.837 36 36 28.837 36 20"
              stroke="currentColor"
              strokeWidth="4"
              strokeLinecap="round"
              strokeOpacity="0.9"
            />
            <path
              d="M14 20L18.5 24.5L27 13"
              stroke="currentColor"
              strokeWidth="4"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </>
        ) : variant === 'inverted' ? (
          <>
            <path
              d="M20 4C11.163 4 4 11.163 4 20C4 28.837 11.163 36 20 36C28.837 36 36 28.837 36 20"
              stroke="#0A2540"
              strokeWidth="4"
              strokeLinecap="round"
            />
            <path
              d="M14 20L18.5 24.5L27 13"
              stroke="#17B6C8"
              strokeWidth="4"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </>
        ) : (
          /* outline */
          <>
            <path
              d="M20 4C11.163 4 4 11.163 4 20C4 28.837 11.163 36 20 36C28.837 36 36 28.837 36 20"
              stroke="currentColor"
              strokeWidth="3.5"
              strokeLinecap="round"
            />
            <path
              d="M14 20L18.5 24.5L27 13"
              stroke="currentColor"
              strokeWidth="3.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </>
        )}
      </svg>
    </div>
  );
}

