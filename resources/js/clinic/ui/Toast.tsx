import { useSyncExternalStore } from 'react';
import * as ToastPrimitive from '@radix-ui/react-toast';
import { AlertTriangle, CircleCheck, Info, X, XCircle, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type Tone = 'success' | 'info' | 'warning' | 'danger';

interface ToastItem {
  id: number;
  tone: Tone;
  title: string;
  description?: string;
}

/*
 * Brief confirmation after an action ("Consent recorded"). A module-level
 * store read with useSyncExternalStore: no context provider, no global state
 * library, and only the viewport re-renders when a toast comes or goes.
 * Errors that need reading or acting on belong in the page (Notice), not here.
 */
let items: ToastItem[] = [];
let nextId = 1;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listener) => listener());
}

function push(tone: Tone, title: string, description?: string): void {
  // At most three on screen; the oldest gives way.
  items = [...items.slice(-2), { id: nextId++, tone, title, description }];
  emit();
}

function dismiss(id: number): void {
  items = items.filter((item) => item.id !== id);
  emit();
}

export const toast = {
  success: (title: string, description?: string) => push('success', title, description),
  info: (title: string, description?: string) => push('info', title, description),
  warning: (title: string, description?: string) => push('warning', title, description),
  error: (title: string, description?: string) => push('danger', title, description),
};

const TONES: Record<Tone, { icon: LucideIcon; iconClass: string }> = {
  success: { icon: CircleCheck, iconClass: 'text-clinic-success' },
  info: { icon: Info, iconClass: 'text-clinic-brand-strong' },
  warning: { icon: AlertTriangle, iconClass: 'text-clinic-warning' },
  danger: { icon: XCircle, iconClass: 'text-clinic-danger' },
};

/** Rendered once, by the portal shell. */
export function ToastViewport() {
  const current = useSyncExternalStore(
    (listener) => {
      listeners.add(listener);

      return () => listeners.delete(listener);
    },
    () => items,
    () => items,
  );

  return (
    <ToastPrimitive.Provider swipeDirection="down" duration={5000} label="Notification">
      {current.map((item) => {
        const { icon: Icon, iconClass } = TONES[item.tone];

        return (
          <ToastPrimitive.Root
            key={item.id}
            type={item.tone === 'danger' ? 'foreground' : 'background'}
            onOpenChange={(open) => !open && dismiss(item.id)}
            className={cn(
              'pointer-events-auto flex w-full items-start gap-3 rounded-2xl border border-clinic-border bg-clinic-surface px-4 py-3 shadow-[0_16px_40px_-16px_rgba(16,24,40,0.35)]',
              'motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0 motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0',
              'data-[swipe=move]:translate-y-[var(--radix-toast-swipe-move-y)]',
            )}
          >
            <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', iconClass)} aria-hidden="true" />
            <div className="min-w-0 flex-1">
              <ToastPrimitive.Title className="text-[13px] font-semibold text-clinic-ink">{item.title}</ToastPrimitive.Title>
              {item.description && (
                <ToastPrimitive.Description className="mt-0.5 text-[12.5px] text-clinic-secondary">
                  {item.description}
                </ToastPrimitive.Description>
              )}
            </div>
            <ToastPrimitive.Close
              aria-label="Dismiss"
              className="-mr-1.5 -mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </ToastPrimitive.Close>
          </ToastPrimitive.Root>
        );
      })}
      <ToastPrimitive.Viewport className="pointer-events-none fixed inset-x-0 bottom-0 z-[70] mx-auto flex w-full max-w-[420px] flex-col gap-2 p-4 outline-none sm:bottom-4 sm:left-auto sm:right-4 sm:mx-0" />
    </ToastPrimitive.Provider>
  );
}
