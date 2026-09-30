import React from 'react';
import { cn } from '@/lib/utils';

export function ChartSkeleton({ className }: { className?: string }) {
  return (
    <div className={cn("p-6 bg-white rounded-xl border border-[#E5EAF0] shadow-[0_1px_2px_rgba(15,23,42,0.02)] flex flex-col h-[300px]", className)}>
      <div className="flex justify-between items-center mb-6">
        <div className="h-5 bg-slate-200 rounded animate-pulse w-1/4" />
        <div className="h-6 bg-slate-100 rounded animate-pulse w-24" />
      </div>
      <div className="flex-1 flex items-end gap-2">
        {Array.from({ length: 12 }).map((_, i) => (
          <div 
            key={i} 
            className="flex-1 bg-slate-100 rounded-t animate-pulse" 
            style={{ height: `${Math.max(20, Math.random() * 100)}%`, opacity: 0.5 + (Math.random() * 0.5) }}
          />
        ))}
      </div>
    </div>
  );
}
