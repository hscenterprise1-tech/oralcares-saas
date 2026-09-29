import React from 'react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The one place a clinic page's flash message renders.
 *
 * Nothing in the clinic portal read `flash.*` before this — every
 * `back()->with('error', ...)` (seat-limit messages, duplicate-slug errors,
 * "cannot delete the main branch", etc.) was arriving in every response and
 * being silently dropped. Mounted once in `ClinicShell` so every page behind
 * it gets this for free, the same way `flash.success` reaching Inertia was
 * the fix on the Company side once, just never built out into a visible UI
 * there either.
 *
 * Errors persist until dismissed — "contact your account manager" is
 * something to act on, not a toast that should vanish before it's read.
 * Success/warning auto-clear, matching the one existing precedent
 * (`Deals/Index.tsx`'s local 4s toast).
 */

interface FlashPageProps {
  flash?: {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    message?: string | null;
  };
  [key: string]: unknown;
}

type FlashKind = 'error' | 'warning' | 'success';

const STYLES: Record<FlashKind, { bg: string; text: string; icon: typeof AlertTriangle; role: 'alert' | 'status'; autoDismissMs: number | null }> = {
  error: {
    bg: 'bg-ovs-crm-accent-coral-soft',
    text: 'text-ovs-crm-accent-coral-text',
    icon: AlertTriangle,
    role: 'alert',
    autoDismissMs: null,
  },
  warning: {
    bg: 'bg-ovs-crm-accent-yellow-soft',
    text: 'text-ovs-crm-accent-yellow-text',
    icon: AlertTriangle,
    role: 'status',
    autoDismissMs: 6000,
  },
  success: {
    bg: 'bg-ovs-crm-accent-green-soft',
    text: 'text-ovs-crm-accent-green-text',
    icon: CheckCircle2,
    role: 'status',
    autoDismissMs: 4000,
  },
};

export function ClinicFlashBanner() {
  const { props } = usePage<FlashPageProps>();
  const flash = props.flash;

  // Error outranks warning outranks success — only one message shows at a
  // time, and a blocking problem should never be upstaged by a confirmation.
  const kind: FlashKind | null = flash?.error ? 'error' : flash?.warning ? 'warning' : flash?.success ? 'success' : null;
  const text = kind ? flash?.[kind] : null;

  const [dismissed, setDismissed] = React.useState(false);

  // A fresh flash — including the same text arriving again on a later
  // request — always starts visible. Laravel's session flash clears after
  // one read, so props.flash genuinely changes between requests even when
  // the message text repeats.
  React.useEffect(() => {
    setDismissed(false);
  }, [kind, text]);

  React.useEffect(() => {
    if (!kind || dismissed) return;
    const ms = STYLES[kind].autoDismissMs;
    if (ms === null) return;

    const id = window.setTimeout(() => setDismissed(true), ms);

    return () => window.clearTimeout(id);
  }, [kind, text, dismissed]);

  if (! kind || ! text || dismissed) return null;

  const style = STYLES[kind];
  const Icon = style.icon;

  return (
    <div
      role={style.role}
      aria-live={style.role === 'alert' ? 'assertive' : 'polite'}
      className={cn('mb-4 flex items-start gap-3 rounded-[16px] px-4 py-3', style.bg)}
    >
      <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', style.text)} aria-hidden="true" />
      <p className={cn('flex-1 text-[13px] leading-relaxed', style.text)}>{text}</p>
      <button
        type="button"
        onClick={() => setDismissed(true)}
        aria-label="Dismiss"
        className={cn('shrink-0 rounded-full p-0.5 transition-opacity hover:opacity-70', style.text)}
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  );
}
