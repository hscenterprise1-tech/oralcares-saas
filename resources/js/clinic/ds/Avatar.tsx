import { cn } from '@/lib/utils';

/* Tint and text pairs that pass WCAG AA; a person keeps the same one on every screen. */
const PALETTE = ['bg-[#EAF2FA] text-[#1769AA]', 'bg-[#E8EEF5] text-[#173B60]', 'bg-[#E6F4F1] text-[#0F6B64]', 'bg-[#EEF1F6] text-[#3B4F6B]'] as const;

const TITLES = new Set(['dr', 'mr', 'mrs', 'ms', 'miss', 'prof']);

/** Initials from the name's words, leaving out titles ("Dr.") and anything without a letter ("(QA)"). */
export function initialsOf(name: string): string {
  const words = name
    .split(/\s+/)
    .map((word) => word.replace(/[^\p{L}]/gu, ''))
    .filter((word) => word !== '' && !TITLES.has(word.toLowerCase()));
  if (words.length === 0) {
    return '?';
  }

  return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
}

function hash(value: string): number {
  let result = 0;
  for (let index = 0; index < value.length; index++) {
    result = (result * 31 + value.charCodeAt(index)) >>> 0;
  }

  return result;
}

const SIZE = { sm: 'h-8 w-8 text-[12px]', md: 'h-10 w-10 text-[14px]' } as const;

/** Decorative: the name beside it is what a screen reader reads. */
export function Avatar({ name, size = 'md', className }: { name: string; size?: keyof typeof SIZE; className?: string }) {
  return (
    <span aria-hidden="true" className={cn('flex shrink-0 select-none items-center justify-center rounded-full font-semibold', SIZE[size], PALETTE[hash(name) % PALETTE.length], className)}>
      {initialsOf(name)}
    </span>
  );
}
