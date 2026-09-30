import * as React from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface SpinnerProps extends React.HTMLAttributes<HTMLDivElement> {
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  color?: 'teal' | 'slate' | 'white';
}

export function Spinner({ size = 'md', color = 'teal', className, ...props }: SpinnerProps) {
  const sizeMap = {
    xs: 'w-3.5 h-3.5',
    sm: 'w-4 h-4',
    md: 'w-5 h-5',
    lg: 'w-7 h-7',
    xl: 'w-9 h-9',
  };

  const colorMap = {
    teal: 'text-teal-600',
    slate: 'text-slate-700',
    white: 'text-white',
  };

  return (
    <div
      role="status"
      className={cn('inline-flex items-center justify-center', colorMap[color], className)}
      {...props}
    >
      <Loader2 className={cn('animate-spin', sizeMap[size])} />
      <span className="sr-only">Loading…</span>
    </div>
  );
}
