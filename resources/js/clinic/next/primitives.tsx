import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, Loader2, Lock, SearchX, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS, INK, LABEL, SIGNAL, specialtySignal, type Signal } from './tokens';

/* ------------------------------------------------------------------ actions */

type Variant = 'primary' | 'quiet' | 'outline';
type Size = 'sm' | 'md' | 'lg';

const VARIANT: Record<Variant, string> = {
  primary: 'bg-[#0E6B54] text-white hover:bg-[#0A5643] disabled:bg-[#9FB8AE]',
  outline: 'border border-[#CFC8BA] bg-white text-[#13201B] hover:border-[#13201B]/40 hover:bg-[#FBFAF6]',
  quiet: 'text-[#0E6B54] hover:bg-[#E3F1EB]',
};

const SIZE: Record<Size, string> = {
  sm: 'h-8 gap-1.5 px-2.5 text-[13px]',
  md: 'h-10 gap-2 px-3.5 text-[14px]',
  lg: 'h-12 gap-2 px-5 text-[15px]',
};

export function actionClasses(variant: Variant = 'outline', size: Size = 'md', className?: string): string {
  return cn(
    'inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-[6px] font-semibold motion-safe:transition-colors disabled:cursor-not-allowed [@media(pointer:coarse)]:min-h-11',
    FOCUS,
    VARIANT[variant],
    SIZE[size],
    className,
  );
}

export interface ActionProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  icon?: LucideIcon;
  /** Shows a spinner and keeps the button from being pressed twice; only for a request actually running. */
  busy?: boolean;
}

export const Action = forwardRef<HTMLButtonElement, ActionProps>(function Action(
  { variant = 'outline', size = 'md', icon: Icon, busy = false, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || busy} aria-busy={busy || undefined} className={actionClasses(variant, size, className)} {...rest}>
      {busy ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : Icon ? <Icon className="h-4 w-4" aria-hidden="true" /> : null}
      {children}
    </button>
  );
});

export function ActionLink({ href, variant = 'outline', size = 'md', icon: Icon, className, children }: { href: string; variant?: Variant; size?: Size; icon?: LucideIcon; className?: string; children: ReactNode }) {
  return (
    <Link href={href} className={actionClasses(variant, size, className)}>
      {Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
      {children}
    </Link>
  );
}

/* ------------------------------------------------------------------ marks */

/** A short status. Always text; colour only reinforces it. */
export function Tag({ signal = 'neutral', icon: Icon, children, className }: { signal?: Signal; icon?: LucideIcon; children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-[4px] px-1.5 text-[12px] font-semibold', SIGNAL[signal].soft, SIGNAL[signal].ink, className)}>
      {Icon && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
      {children}
    </span>
  );
}

/**
 * A specialty, the same everywhere: a square in the specialty's accent and its
 * name. `compact` keeps only the square and a two-letter code for tight rows
 * (the full name stays readable to screen readers and on hover).
 */
export function SpecialtyMark({ specialty, label, compact = false }: { specialty: string; label: string; compact?: boolean }) {
  const signal = SIGNAL[specialtySignal(specialty)];

  return (
    <span title={label} className={cn('inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-[4px] pr-1.5 text-[12px] font-semibold', signal.soft, signal.ink, compact ? 'pl-1' : 'pl-1.5')}>
      <span aria-hidden="true" className={cn('h-2.5 w-2.5 rounded-[2px]', signal.solid)} />
      {compact ? (
        <>
          <span aria-hidden="true">{label.slice(0, 2).toUpperCase()}</span>
          <span className="sr-only">{label}</span>
        </>
      ) : (
        label
      )}
    </span>
  );
}

/** A person's monogram: two letters on a quiet square, decorative (the name beside it is what is read). */
export function Monogram({ name, size = 'md', muted = false }: { name: string; size?: 'sm' | 'md' | 'lg'; muted?: boolean }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 select-none place-items-center rounded-[6px] font-semibold tracking-[0.02em]',
        size === 'sm' && 'h-8 w-8 text-[12px]',
        size === 'md' && 'h-10 w-10 text-[13px]',
        size === 'lg' && 'h-14 w-14 rounded-[8px] text-[18px]',
        muted ? 'bg-[#EFECE5] text-[#6B7671]' : 'bg-[#13201B] text-[#E9F4EF]',
      )}
    >
      {monogramOf(name)}
    </span>
  );
}

/** Two initials, skipping titles ("Dr.", "Mr.") and anything that is not a letter. */
export function monogramOf(name: string): string {
  const words = name
    .split(/\s+/)
    .map((word) => word.replace(/[^\p{L}]/gu, ''))
    .filter((word) => word.length > 0 && !/^(dr|mr|mrs|ms|miss|prof)$/i.test(word));

  return ((words[0]?.[0] ?? '') + (words.length > 1 ? (words[words.length - 1][0] ?? '') : (words[0]?.[1] ?? ''))).toUpperCase() || '·';
}

export function Kbd({ children }: { children: ReactNode }) {
  return <kbd className="inline-flex h-5 min-w-5 items-center justify-center rounded-[4px] border border-[#CFC8BA] bg-white px-1 font-sans text-[11px] font-semibold text-[#4A5752]">{children}</kbd>;
}

/** Progress through a course of care: done of total, as a bar and in words. */
export function CourseProgress({ done, total, unit, signal = 'brand' }: { done: number; total: number | null; unit: string; signal?: Signal }) {
  const share = total ? Math.min(1, done / total) : null;

  return (
    <div className="min-w-0">
      <p className="text-[12.5px] tabular-nums text-[#4A5752]">{total ? `${done} of ${total} ${unit}` : `${done} ${unit} so far`}</p>
      {share !== null && (
        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-[#EFECE5]" role="progressbar" aria-valuemin={0} aria-valuemax={total ?? 0} aria-valuenow={done} aria-label={`${done} of ${total} ${unit}`}>
          <div className={cn('h-full rounded-full', SIGNAL[signal].solid)} style={{ width: `${Math.round(share * 100)}%` }} />
        </div>
      )}
    </div>
  );
}

/** A labelled value in a fact list. */
export function Fact({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={cn('min-w-0', className)}>
      <dt className={LABEL}>{label}</dt>
      <dd className={cn('mt-1 min-w-0 text-[14px] leading-5', INK.text)}>{children}</dd>
    </div>
  );
}

/* ------------------------------------------------------------------ states */

type StateKind = 'empty' | 'filtered' | 'error' | 'denied';

const STATE_ICON: Record<StateKind, LucideIcon> = { empty: SearchX, filtered: SearchX, error: AlertTriangle, denied: Lock };

/**
 * A screen's empty, no-match, failed or not-allowed state: what happened in
 * one line, what to do in another, and at most one way forward.
 */
export function StateBlock({ kind, title, children, action }: { kind: StateKind; title: string; children?: ReactNode; action?: ReactNode }) {
  const Icon = STATE_ICON[kind];

  return (
    <div role={kind === 'error' ? 'alert' : undefined} className="flex flex-col items-start gap-3 px-5 py-10 sm:px-8 sm:py-14">
      <span className={cn('grid h-10 w-10 place-items-center rounded-[6px]', kind === 'error' ? cn(SIGNAL.danger.soft, SIGNAL.danger.ink) : 'bg-[#EFECE5] text-[#4A5752]')}>
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <div className="max-w-md">
        <p className={cn('text-[16px] font-semibold', INK.text)}>{title}</p>
        {children && <p className={cn('mt-1 text-[14px] leading-[21px]', INK.muted)}>{children}</p>}
      </div>
      {action}
    </div>
  );
}

/** A placeholder line while rows load; the list keeps its shape. */
export function SkeletonBar({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cn('block rounded-[3px] bg-[#EAE6DC] motion-safe:animate-pulse', className)} />;
}
