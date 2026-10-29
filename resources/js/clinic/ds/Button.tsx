import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Loader2, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING } from './tones';

type Variant = 'primary' | 'accent' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

const VARIANT: Record<Variant, string> = {
  primary: 'bg-[#1769AA] text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] hover:bg-[#135A93]',
  accent: 'bg-[#173B60] text-white hover:bg-[#12304F]',
  secondary: 'border border-[#D5DDE7] bg-white text-[#173B60] hover:border-[#1769AA]/50 hover:bg-[#F5F8FC]',
  ghost: 'text-[#475569] hover:bg-[#EEF3F8] hover:text-[#173B60]',
  danger: 'bg-[#B42318] text-white hover:bg-[#9A1E14]',
};

/* 44px on touch screens whatever the size. */
const SIZE: Record<Size, string> = {
  sm: 'h-9 gap-1.5 rounded-[10px] px-3 text-[13px]',
  md: 'h-10 gap-2 rounded-[10px] px-4 text-[14px]',
  lg: 'h-11 gap-2 rounded-xl px-5 text-[14px]',
};

export function dsButtonClasses(variant: Variant = 'secondary', size: Size = 'md', className?: string): string {
  return cn(
    'inline-flex shrink-0 select-none items-center justify-center whitespace-nowrap font-semibold',
    'motion-safe:transition-[background-color,border-color,color,filter,transform] motion-safe:duration-150 motion-safe:active:scale-[0.98]',
    'disabled:pointer-events-none disabled:opacity-50 [@media(pointer:coarse)]:min-h-11',
    FOCUS_RING,
    VARIANT[variant],
    SIZE[size],
    className,
  );
}

export interface DsButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  icon?: LucideIcon;
  /** Locks the button and says what is happening; the label stays for screen readers. */
  loading?: boolean;
  children?: ReactNode;
}

/**
 * The redesign's button. `loading` only ever reflects a request that is
 * really running; it is never used to fake progress.
 */
export const DsButton = forwardRef<HTMLButtonElement, DsButtonProps>(function DsButton(
  { variant = 'secondary', size = 'md', icon: Icon, loading = false, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || loading} aria-busy={loading || undefined} className={dsButtonClasses(variant, size, className)} {...rest}>
      {loading ? <Loader2 className="h-4 w-4 motion-safe:animate-spin" aria-hidden="true" /> : Icon ? <Icon className="h-4 w-4" aria-hidden="true" /> : null}
      {children}
    </button>
  );
});

/** A link styled as a button, for a page the server gave a URL to. */
export function DsButtonLink({ href, variant = 'secondary', size = 'md', icon: Icon, className, children }: { href: string; variant?: Variant; size?: Size; icon?: LucideIcon; className?: string; children: ReactNode }) {
  return (
    <Link href={href} className={dsButtonClasses(variant, size, className)}>
      {Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
      {children}
    </Link>
  );
}
