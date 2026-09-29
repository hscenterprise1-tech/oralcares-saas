import React from 'react';
import { Skeleton } from './skeleton';
import { cn } from '@/lib/utils';

export function PageHeaderSkeleton() {
  return (
    <div className="flex flex-col lg:flex-row justify-between items-start lg:items-end mb-8 border-b border-slate-200 pb-6 gap-6">
      <div className="w-full lg:flex-1 space-y-3">
        <Skeleton className="h-4 w-24" />
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-4 w-96 max-w-full" />
      </div>
      <div className="flex items-center gap-3 w-full lg:w-auto shrink-0 justify-between lg:justify-end">
        <Skeleton className="h-9 w-32 hidden lg:block" />
        <Skeleton className="h-9 w-9 rounded-full" />
        <Skeleton className="h-9 w-9 rounded-full" />
      </div>
    </div>
  );
}

export function TableSkeleton({ rows = 5, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div className="signature-card bg-white p-4 overflow-hidden">
      <div className="w-full">
        {/* Header */}
        <div className="flex border-b border-slate-100 pb-4 mb-4 gap-4">
          {Array.from({ length: columns }).map((_, i) => (
            <Skeleton key={`th-${i}`} className={cn("h-6", i === 0 ? "w-1/4" : "flex-1")} />
          ))}
        </div>
        
        {/* Rows */}
        <div className="space-y-4">
          {Array.from({ length: rows }).map((_, rIndex) => (
            <div key={`tr-${rIndex}`} className="flex gap-4">
              {Array.from({ length: columns }).map((_, cIndex) => (
                <Skeleton key={`td-${rIndex}-${cIndex}`} className={cn("h-10", cIndex === 0 ? "w-1/4" : "flex-1")} />
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export function DashboardSkeleton() {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="signature-card p-6 bg-white border border-slate-100 h-32 flex flex-col justify-between">
          <div className="flex justify-between items-start">
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-8 w-8 rounded-full" />
          </div>
          <div className="space-y-2">
            <Skeleton className="h-8 w-24" />
            <Skeleton className="h-3 w-32" />
          </div>
        </div>
      ))}
    </div>
  );
}

export function FormSkeleton({ fields = 4 }: { fields?: number }) {
  return (
    <div className="space-y-6 max-w-2xl">
      {Array.from({ length: fields }).map((_, i) => (
        <div key={i} className="space-y-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-10 w-full" />
        </div>
      ))}
      <div className="flex gap-3 pt-4">
        <Skeleton className="h-10 w-24" />
        <Skeleton className="h-10 w-24" />
      </div>
    </div>
  );
}
