import * as React from 'react';
import { cn } from '@/lib/utils';

function Skeleton({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('animate-pulse rounded-lg bg-slate-100 border border-slate-200/40', className)}
      {...props}
    />
  );
}

export { Skeleton };
