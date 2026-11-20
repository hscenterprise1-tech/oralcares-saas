import React from 'react';
import { cn } from '@/lib/utils';

export type Tone = 'blue' | 'teal' | 'violet' | 'amber' | 'rose' | 'mint' | 'slate' | 'indigo';

export const DASHBOARD_TONES: Record<Tone, { chip: string; soft: string; ink: string }> = {
  blue: { chip: 'from-[#4F9BF0] to-[#2B6FD8]', soft: 'bg-cx-blue-bg', ink: 'text-cx-blue-ink' },
  teal: { chip: 'from-[#2CC6B4] to-[#0E9F87]', soft: 'bg-cx-teal-bg', ink: 'text-cx-teal-ink' },
  violet: { chip: 'from-[#9B7BF2] to-[#6B48DE]', soft: 'bg-cx-violet-bg', ink: 'text-cx-violet-ink' },
  indigo: { chip: 'from-[#6366F1] to-[#4F46E5]', soft: 'bg-indigo-50', ink: 'text-indigo-700' },
  amber: { chip: 'from-[#F7B451] to-[#E9821A]', soft: 'bg-cx-amber-bg', ink: 'text-cx-amber-ink' },
  rose: { chip: 'from-[#F58A94] to-[#E5484D]', soft: 'bg-cx-rose-bg', ink: 'text-cx-rose-ink' },
  mint: { chip: 'from-[#5FD9A6] to-[#12A877]', soft: 'bg-cx-green-bg', ink: 'text-cx-green-ink' },
  slate: { chip: 'from-[#94A3B8] to-[#64748B]', soft: 'bg-cx-slate-bg', ink: 'text-cx-slate-ink' },
};

interface IconChipProps {
  icon: React.ComponentType<{ className?: string; strokeWidth?: number }>;
  tone: Tone;
  size?: 'xs' | 'sm' | 'md';
  variant?: 'solid' | 'soft';
  className?: string;
}

export function IconChip({
  icon: Icon,
  tone,
  size = 'md',
  variant = 'solid',
  className,
}: IconChipProps) {
  // The Owner Dashboard's own tiles (`CommandCenter/sections.tsx` IconChip): 36px for a section, 32px inside one.
  const sizeClasses =
    size === 'md'
      ? 'h-9 w-9 rounded-[10px]'
      : size === 'sm'
        ? 'h-8 w-8 rounded-sm'
        : 'h-6 w-6 rounded-[6px]';

  const iconSizeClasses =
    size === 'md'
      ? 'h-[18px] w-[18px]'
      : size === 'sm'
        ? 'h-4 w-4'
        : 'h-3.5 w-3.5';

  const toneConfig = DASHBOARD_TONES[tone] || DASHBOARD_TONES.violet;

  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 place-items-center',
        variant === 'solid'
          ? cn('bg-gradient-to-br text-white shadow-[0_4px_10px_-6px_rgba(30,50,110,0.45)]', toneConfig.chip)
          : cn(toneConfig.soft, toneConfig.ink),
        sizeClasses,
        className
      )}
    >
      <Icon className={iconSizeClasses} strokeWidth={1.75} />
    </span>
  );
}
