import React from 'react';
import { AlertTriangle, CircleAlert, CircleCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

type AuthNoticeTone = 'error' | 'warning' | 'success';

const TONES: Record<AuthNoticeTone, { icon: typeof CircleAlert; box: string; icon_: string; text: string }> = {
  error: {
    icon: CircleAlert,
    box: 'border-clinic-accent-coral/30 bg-clinic-accent-coral-soft',
    icon_: 'text-clinic-accent-coral',
    text: 'text-clinic-accent-coral-text',
  },
  warning: {
    icon: AlertTriangle,
    box: 'border-clinic-warning-border bg-clinic-warning-soft',
    icon_: 'text-clinic-warning',
    text: 'text-clinic-warning',
  },
  success: {
    icon: CircleCheck,
    box: 'border-clinic-success-border bg-clinic-success-soft',
    icon_: 'text-clinic-success',
    text: 'text-clinic-success',
  },
};

/**
 * A form-level message on the clinic pre-auth pages — a refused sign-in, a
 * plan seat limit, a session that ended, a reset link on its way. Announced
 * as it appears (`role`), because the person has just pressed submit and is
 * not looking for it.
 *
 * `error` is for "this attempt failed"; `warning` for context that is not the
 * person's fault, such as a session that timed out; `success` for a request
 * that went through.
 */
export function AuthNotice({ tone, children }: { tone: AuthNoticeTone; children: React.ReactNode }) {
  const style = TONES[tone];
  const Icon = style.icon;

  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('flex items-start gap-3 rounded-[16px] border px-4 py-3', style.box)}
    >
      <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', style.icon_)} aria-hidden="true" />
      <p className={cn('text-[13px] leading-relaxed', style.text)}>{children}</p>
    </div>
  );
}
