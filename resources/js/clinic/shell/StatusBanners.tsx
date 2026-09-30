import { useEffect, useState } from 'react';
import { WifiOff, X } from 'lucide-react';
import type { FlashProps, SubscriptionWindowProps } from '../types';
import { Notice } from '../ui/Notice';

function useOnline(): boolean {
  const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);

    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
    };
  }, []);

  return online;
}

/** Says so plainly when the device loses its connection, and goes away when it returns. */
export function OfflineBanner() {
  const online = useOnline();

  if (online) {
    return null;
  }

  return (
    <div
      role="alert"
      className="flex items-center justify-center gap-2 bg-clinic-warning-soft px-4 py-2 text-center text-[12.5px] font-semibold text-clinic-warning"
    >
      <WifiOff className="h-4 w-4 shrink-0" aria-hidden="true" />
      You are offline. Changes cannot be saved until your connection returns.
    </div>
  );
}

/**
 * The plan's end, said before it arrives: the last week of a trial, and the
 * grace period after a plan ends. A lapsed plan never reaches here — the
 * server stops the request first.
 */
export function PlanBanner({ window: planWindow }: { window?: SubscriptionWindowProps }) {
  if (!planWindow || planWindow.days_remaining === null) {
    return null;
  }

  const days = planWindow.days_remaining;
  const dayText = days === 1 ? '1 day' : `${days} days`;

  if (planWindow.state === 'grace') {
    return (
      <div
        role="alert"
        className="bg-clinic-danger-soft px-4 py-2 text-center text-[12.5px] font-semibold text-clinic-danger"
      >
        Your clinic&apos;s plan has ended. Access continues for {dayText}; contact your account manager to keep it.
      </div>
    );
  }

  if (planWindow.state === 'trial' && days <= 7) {
    return (
      <div
        role="status"
        className="bg-clinic-brand-soft px-4 py-2 text-center text-[12.5px] font-semibold text-clinic-brand-strong"
      >
        {days === 0 ? 'Your trial ends today.' : `Your trial ends in ${dayText}.`}
      </div>
    );
  }

  return null;
}

/** The server's one-off confirmation or warning, dismissible. */
export function FlashNotices({ flash }: { flash: FlashProps | undefined }) {
  const [dismissed, setDismissed] = useState<string | null>(null);
  const entries: { tone: 'success' | 'warning' | 'danger' | 'info'; text: string }[] = [];

  if (flash?.success) entries.push({ tone: 'success', text: flash.success });
  if (flash?.error) entries.push({ tone: 'danger', text: flash.error });
  if (flash?.warning) entries.push({ tone: 'warning', text: flash.warning });
  if (flash?.message) entries.push({ tone: 'info', text: flash.message });

  const signature = entries.map((entry) => entry.text).join('|');

  useEffect(() => setDismissed(null), [signature]);

  if (entries.length === 0 || dismissed === signature) {
    return null;
  }

  return (
    <div className="mb-5 space-y-2">
      {entries.map((entry, index) => (
        <Notice
          key={entry.text}
          tone={entry.tone}
          action={
            index === 0 ? (
              <button
                type="button"
                onClick={() => setDismissed(signature)}
                aria-label="Dismiss message"
                className="flex h-8 w-8 items-center justify-center rounded-full text-clinic-secondary hover:bg-black/5 hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            ) : undefined
          }
        >
          {entry.text}
        </Notice>
      ))}
    </div>
  );
}
