import { cn } from '@/lib/utils';

/**
 * A placeholder block while a deferred prop or a JSON read is on its way.
 * Decorative only: the region that is loading says so itself (`aria-busy`).
 */
export function Skeleton({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cn('block rounded-md bg-cx-slate-bg motion-safe:animate-pulse', className)} />;
}

/** Lines of text-height skeletons, the last one shorter, as a paragraph reads. */
export function SkeletonLines({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <span aria-hidden="true" className={cn('block space-y-2', className)}>
      {Array.from({ length: lines }, (_, index) => (
        <Skeleton key={index} className={cn('h-3', index === lines - 1 ? 'w-2/3' : 'w-full')} />
      ))}
    </span>
  );
}
