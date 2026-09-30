import React from 'react';
import { cn } from '@/lib/utils';

export function CardSkeleton({ className }: { className?: string }) {
  return (
    <div className={cn("p-6 bg-white rounded-xl border border-[#E5EAF0] shadow-[0_1px_2px_rgba(15,23,42,0.02)] flex flex-col gap-4", className)}>
      <div className="flex justify-between items-center">
        <div className="h-5 bg-slate-200 rounded animate-pulse w-1/3" />
        <div className="h-8 w-8 bg-slate-100 rounded-full animate-pulse" />
      </div>
      <div className="h-10 bg-slate-100 rounded animate-pulse w-1/2 mt-2" />
      <div className="h-4 bg-slate-50 rounded animate-pulse w-2/3" />
    </div>
  );
}
