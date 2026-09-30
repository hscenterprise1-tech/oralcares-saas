import type { ReactNode } from 'react';
import { AlertTriangle, CircleCheck, Info, XCircle, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type Tone = 'info' | 'success' | 'warning' | 'danger';

const TONES: Record<Tone, { icon: LucideIcon; classes: string; role: 'status' | 'alert' }> = {
  info: { icon: Info, classes: 'border-clinic-border bg-clinic-inset text-clinic-ink', role: 'status' },
  success: {
    icon: CircleCheck,
    classes: 'border-clinic-success/20 bg-clinic-success-soft text-clinic-success',
    role: 'status',
  },
  warning: {
    icon: AlertTriangle,
    classes: 'border-clinic-warning/20 bg-clinic-warning-soft text-clinic-warning',
    role: 'alert',
  },
  danger: { icon: XCircle, classes: 'border-clinic-danger/20 bg-clinic-danger-soft text-clinic-danger', role: 'alert' },
};

/** A message about the page as a whole, not about one field. */
export function Notice({
  tone,
  title,
  children,
  action,
  className,
}: {
  tone: Tone;
  title?: string;
  children?: ReactNode;
  /** A control at the end of the first line, e.g. a dismiss button. */
  action?: ReactNode;
  className?: string;
}) {
  const { icon: Icon, classes, role } = TONES[tone];

  return (
    <div role={role} className={cn('flex items-start gap-3 rounded-2xl border px-4 py-3', classes, className)}>
      <Icon className="mt-[3px] h-4 w-4 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1 text-[13px] leading-relaxed">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={cn(title && 'mt-0.5', 'text-clinic-ink/85')}>{children}</div>}
      </div>
      {/* -my-1.5 keeps a 32px control centred on the 21px first line without growing the row. */}
      {action && <div className="-my-1.5 -mr-2 shrink-0">{action}</div>}
    </div>
  );
}
