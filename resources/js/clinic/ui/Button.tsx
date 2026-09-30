import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Spinner } from './Spinner';

type Tone = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

const TONE: Record<Tone, string> = {
  primary: 'bg-clinic-ink text-white hover:bg-clinic-ink-soft',
  secondary:
    'border border-clinic-border bg-clinic-surface text-clinic-ink hover:border-clinic-border-strong hover:bg-clinic-inset',
  ghost: 'text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
  danger: 'bg-clinic-danger text-white hover:brightness-95',
};

/* Every size meets the 44px touch target on touch screens (see `touch`). */
const SIZE: Record<Size, string> = {
  sm: 'h-9 gap-1.5 px-3.5 text-[12.5px]',
  md: 'h-10 gap-2 px-4 text-[13px]',
  lg: 'h-11 gap-2 px-5 text-[13.5px]',
};

export function buttonClasses(tone: Tone = 'secondary', size: Size = 'md', className?: string): string {
  return cn(
    'inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold',
    'motion-safe:transition-colors motion-safe:duration-150',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-2 focus-visible:ring-offset-clinic-canvas',
    'disabled:pointer-events-none disabled:opacity-50',
    '[@media(pointer:coarse)]:min-h-11',
    TONE[tone],
    SIZE[size],
    className,
  );
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  tone?: Tone;
  size?: Size;
  icon?: LucideIcon;
  /** Disables the button and says it is working, for screen readers too. */
  loading?: boolean;
  loadingLabel?: string;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    tone = 'secondary',
    size = 'md',
    icon: Icon,
    loading = false,
    loadingLabel = 'Working…',
    disabled,
    className,
    children,
    type = 'button',
    ...rest
  },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={buttonClasses(tone, size, className)}
      {...rest}
    >
      {loading ? <Spinner className="h-4 w-4" /> : Icon ? <Icon className="h-4 w-4" aria-hidden="true" /> : null}
      <span>{loading ? loadingLabel : children}</span>
    </button>
  );
});

export function ButtonLink({
  href,
  tone = 'secondary',
  size = 'md',
  icon: Icon,
  className,
  children,
}: {
  href: string;
  tone?: Tone;
  size?: Size;
  icon?: LucideIcon;
  className?: string;
  children: ReactNode;
}) {
  return (
    <Link href={href} className={buttonClasses(tone, size, className)}>
      {Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
      <span>{children}</span>
    </Link>
  );
}
