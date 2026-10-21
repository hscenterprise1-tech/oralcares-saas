import { cn } from '@/lib/utils';

/*
 * A person's initials on a colour derived from their name: the same patient
 * is the same colour on every screen, which makes them recognisable at a
 * glance in a long list. Each pair of tint and text passes WCAG AA.
 */
const PALETTE = [
  'bg-cyan-100 text-cyan-900',
  'bg-indigo-100 text-indigo-900',
  'bg-emerald-100 text-emerald-900',
  'bg-amber-100 text-amber-900',
  'bg-rose-100 text-rose-900',
  'bg-sky-100 text-sky-900',
  'bg-violet-100 text-violet-900',
  'bg-teal-100 text-teal-900',
] as const;

function hash(value: string): number {
  let result = 0;
  for (let index = 0; index < value.length; index++) {
    result = (result * 31 + value.charCodeAt(index)) >>> 0;
  }

  return result;
}

export function personInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) {
    return '?';
  }

  return ((words[0]?.[0] ?? '') + (words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

const SIZES = { sm: 'h-8 w-8 text-[11px]', md: 'h-10 w-10 text-[13px]', lg: 'h-14 w-14 text-[17px]' } as const;

/** Decorative by default: the name next to it is what screen readers read. */
export function PersonAvatar({ name, size = 'md', className }: { name: string; size?: keyof typeof SIZES; className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'inline-flex shrink-0 select-none items-center justify-center rounded-full font-bold tracking-tight',
        PALETTE[hash(name.trim().toLowerCase()) % PALETTE.length],
        SIZES[size],
        className,
      )}
    >
      {personInitials(name)}
    </span>
  );
}
