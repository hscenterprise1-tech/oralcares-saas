import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { TONE, type DsTone } from './tones';

/** A short status or label. Always carries text: colour is never the only signal. */
export function Pill({ tone = 'slate', icon: Icon, children, className }: { tone?: DsTone; icon?: LucideIcon; children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[12px] font-semibold leading-5', TONE[tone].soft, TONE[tone].ink, className)}>
      {Icon && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
      {children}
    </span>
  );
}
