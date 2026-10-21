import { AlertOctagon, AlertTriangle, Pill, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { SafetyFlag } from './types';

const SEVERITY = {
  critical: { label: 'Critical', classes: 'border-rose-300 bg-rose-50 text-rose-800', icon: AlertOctagon },
  high: { label: 'High', classes: 'border-amber-300 bg-amber-50 text-amber-900', icon: AlertTriangle },
  moderate: { label: 'Moderate', classes: 'border-clinic-border bg-clinic-inset text-clinic-ink', icon: ShieldAlert },
  low: { label: 'Low', classes: 'border-clinic-border bg-clinic-inset text-clinic-ink', icon: ShieldAlert },
} as const;

const KIND = { allergy: 'Allergy', condition: 'Condition', alert: 'Alert' } as const;

/**
 * Safety flags as the server sends them to this role: an icon, the kind and
 * the severity in words, so nothing depends on colour alone.
 */
export function SafetyFlags({ flags, compact = false, className }: { flags: SafetyFlag[]; compact?: boolean; className?: string }) {
  if (flags.length === 0) {
    return null;
  }

  return (
    <ul aria-label="Safety flags" className={cn('flex flex-wrap gap-1.5', className)}>
      {flags.map((flag) => {
        const tone = SEVERITY[flag.severity] ?? SEVERITY.moderate;
        const Icon = flag.kind === 'allergy' ? Pill : tone.icon;

        return (
          <li
            key={`${flag.kind}-${flag.label}`}
            className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-semibold', compact ? 'text-[11px]' : 'text-[12px]', tone.classes)}
          >
            <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>
              {flag.kind === 'allergy' ? `Allergy: ${flag.label}` : flag.label}
              <span className="sr-only">
                {' '}
                ({KIND[flag.kind]}, {tone.label.toLowerCase()} severity)
              </span>
            </span>
            {!compact && <span className="font-medium opacity-80">· {tone.label}</span>}
          </li>
        );
      })}
    </ul>
  );
}
