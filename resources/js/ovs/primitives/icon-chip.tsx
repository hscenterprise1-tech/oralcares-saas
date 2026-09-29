import React from 'react';
import { cn } from '@/lib/utils';

export type IconChipTone = 'teal' | 'indigo' | 'blue' | 'emerald' | 'amber' | 'rose' | 'purple' | 'slate' | 'neutral' | 'brand';
export type IconChipSize = 'xs' | 'sm' | 'md' | 'lg';

export interface IconChipProps extends React.HTMLAttributes<HTMLDivElement> {
  tone?: IconChipTone;
  size?: IconChipSize;
  icon?: React.ReactNode;
}

export const IconChip: React.FC<IconChipProps> = ({
  tone = 'indigo',
  size = 'md',
  icon,
  className,
  children,
  ...props
}) => {
  // Wide 3-stop gradients (400 → 500 → 700) instead of a flat 2-step shade,
  // plus a soft inset top highlight for a glossy, dimensional premium finish.
  const toneClasses: Record<IconChipTone, string> = {
    teal: 'bg-gradient-to-br from-teal-400 via-teal-500 to-teal-700 text-white shadow-[0_3px_10px_rgba(13,148,136,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-teal-300/40',
    indigo: 'bg-gradient-to-br from-indigo-400 via-indigo-500 to-indigo-700 text-white shadow-[0_3px_10px_rgba(99,102,241,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-indigo-300/40',
    brand: 'bg-gradient-to-br from-indigo-400 via-indigo-500 to-indigo-700 text-white shadow-[0_3px_10px_rgba(99,102,241,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-indigo-300/40',
    blue: 'bg-gradient-to-br from-blue-400 via-blue-500 to-blue-700 text-white shadow-[0_3px_10px_rgba(59,130,246,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-blue-300/40',
    emerald: 'bg-gradient-to-br from-emerald-400 via-emerald-500 to-emerald-700 text-white shadow-[0_3px_10px_rgba(16,185,129,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-emerald-300/40',
    amber: 'bg-gradient-to-br from-amber-400 via-amber-500 to-amber-700 text-white shadow-[0_3px_10px_rgba(245,158,11,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-amber-300/40',
    rose: 'bg-gradient-to-br from-rose-400 via-rose-500 to-rose-700 text-white shadow-[0_3px_10px_rgba(244,63,94,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-rose-300/40',
    purple: 'bg-gradient-to-br from-purple-400 via-purple-500 to-purple-700 text-white shadow-[0_3px_10px_rgba(168,85,247,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-purple-300/40',
    slate: 'bg-gradient-to-br from-slate-500 via-slate-600 to-slate-800 text-white shadow-[0_3px_10px_rgba(71,85,105,0.3),inset_0_1px_0_rgba(255,255,255,0.15)] border-slate-400/40',
    neutral: 'bg-gradient-to-br from-slate-500 via-slate-600 to-slate-800 text-white shadow-[0_3px_10px_rgba(71,85,105,0.3),inset_0_1px_0_rgba(255,255,255,0.15)] border-slate-400/40',
  };

  const sizeClasses: Record<IconChipSize, string> = {
    xs: 'w-6 h-6 rounded-md text-[10px]',
    sm: 'w-7 h-7 rounded-lg text-xs',
    md: 'w-[38px] h-[38px] rounded-xl text-sm',
    lg: 'w-11 h-11 rounded-2xl text-base',
  };

  return (
    <div
      className={cn(
        'inline-flex items-center justify-center border font-bold shrink-0 transition-transform select-none shadow-ovs-keyline',
        toneClasses[tone],
        sizeClasses[size],
        className
      )}
      {...props}
    >
      {icon || children}
    </div>
  );
};
export default IconChip;
