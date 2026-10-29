import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { TONE, type DsTone } from './tones';

/** What is empty, and the next step when there is one. */
export function EmptyNote({ icon: Icon, tone = 'slate', title, children, action, className }: { icon?: LucideIcon; tone?: DsTone; title: string; children?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col items-center px-6 py-8 text-center', className)}>
      {Icon && (
        <span className={cn('mb-3 flex h-11 w-11 items-center justify-center rounded-2xl', TONE[tone].soft, TONE[tone].ink)}>
          <Icon className="h-5 w-5" aria-hidden="true" />
        </span>
      )}
      <p className="text-[14px] font-semibold text-cx-text">{title}</p>
      {children && <p className="mt-1 max-w-[42ch] text-[13px] leading-5 text-[#64748B]">{children}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

/** A grey placeholder the shape of what is loading, so nothing jumps when it arrives. */
export function Shimmer({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cn('block rounded-lg bg-cx-slate-bg motion-safe:animate-pulse', className)} />;
}

/** An inline message: a refusal from the server, a warning, a note. */
export function InlineNotice({ tone = 'rose', children, action }: { tone?: DsTone; children: ReactNode; action?: ReactNode }) {
  return (
    <div role={tone === 'rose' ? 'alert' : 'status'} className={cn('flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3 text-[13px] font-medium', TONE[tone].soft, TONE[tone].ink)}>
      <span className="min-w-0">{children}</span>
      {action}
    </div>
  );
}
