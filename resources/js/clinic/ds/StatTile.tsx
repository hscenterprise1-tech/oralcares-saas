import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING, TONE, type DsTone } from './tones';

/**
 * One headline figure. Each category keeps its colour family: a soft wash
 * at the top of the tile, a tinted icon, the value in navy and a short
 * caption. The value re-enters briefly when fresh server data changes it.
 * With `onSelect` the tile is a button that opens the list behind it.
 */
export function StatTile({
  label,
  value,
  caption,
  tone,
  icon: Icon,
  active = false,
  onSelect,
}: {
  label: string;
  value: string;
  caption?: string | null;
  tone: DsTone;
  icon: LucideIcon;
  active?: boolean;
  onSelect?: () => void;
}) {
  const body = (
    <>
      <span className="flex w-full items-start justify-between gap-2">
        <span className="whitespace-nowrap text-[13px] font-semibold leading-5 text-[#475569]">{label}</span>
        <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', TONE[tone].soft, TONE[tone].ink)}>
          <Icon className="h-[18px] w-[18px]" strokeWidth={2} aria-hidden="true" />
        </span>
      </span>
      <span key={value} className="-mt-1 block text-[30px] font-bold leading-9 tracking-tight text-[#173B60] tabular-nums motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-1 motion-safe:duration-300">
        {value}
      </span>
      <span className={cn('mt-0.5 block min-h-5 text-[12.5px] font-medium leading-5', TONE[tone].ink)}>{caption}</span>
    </>
  );
  const shell = cn(
    'relative flex h-full w-full min-w-0 flex-col items-start overflow-hidden rounded-2xl border bg-gradient-to-b to-white px-4 pb-3.5 pt-4 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_20px_-16px_rgba(23,59,96,0.35)]',
    TONE[tone].wash,
    active ? cn('border-transparent ring-2', TONE[tone].ring) : 'border-[#E6EBF1]',
  );

  if (!onSelect) {
    return <div className={shell}>{body}</div>;
  }

  return (
    <button type="button" onClick={onSelect} aria-pressed={active} className={cn(shell, FOCUS_RING, 'motion-safe:transition-[box-shadow,transform] motion-safe:duration-200 hover:-translate-y-px hover:shadow-[0_2px_4px_rgba(15,23,42,0.05),0_14px_28px_-18px_rgba(23,59,96,0.45)]')}>
      {body}
    </button>
  );
}
