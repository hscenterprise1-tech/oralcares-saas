import React from 'react';
import { cn } from '@/lib/utils';

export function TableSkeleton({ rows = 5, columns = 4, className }: { rows?: number, columns?: number, className?: string }) {
  return (
    <div className={cn("w-full bg-white rounded-xl border border-[#E5EAF0] shadow-sm overflow-hidden", className)}>
      <div className="flex items-center gap-4 border-b border-[#E5EAF0] bg-slate-50/50 p-4">
        {Array.from({ length: columns }).map((_, i) => (
          <div key={i} className="h-4 bg-slate-200 rounded animate-pulse w-full max-w-[120px]" />
        ))}
      </div>
      <div className="flex flex-col">
        {Array.from({ length: rows }).map((_, rowIndex) => (
          <div key={rowIndex} className="flex items-center gap-4 border-b border-[#E5EAF0] last:border-0 p-4">
            {Array.from({ length: columns }).map((_, colIndex) => (
              <div 
                key={colIndex} 
                className={cn(
                  "h-4 bg-slate-100 rounded animate-pulse w-full",
                  colIndex === 0 ? "max-w-[200px]" : "max-w-[100px]"
                )} 
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
