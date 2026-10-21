import { Plus, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The walk-in starts: New Case and Repeat Case, each opening the one
 * canonical intake (`ReceptionIntakeDialog`). The group, the buttons and
 * their classes are the approved New Case / Repeat Case design (first on the
 * Reception header, removed in the 2026-10-11 frontend reset): frozen, so do
 * not restyle them.
 */
export function CaseButtons({ canRegister, onNewCase, onRepeatCase }: {
  canRegister: boolean;
  onNewCase: () => void;
  onRepeatCase: () => void;
}) {
  return (
    <div role="group" aria-label="Start a case" className="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
      {canRegister && (
        <button
          type="button"
          onClick={onNewCase}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-clinic-ink px-5 text-[14px] font-semibold text-white motion-safe:transition-colors hover:bg-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-2 active:bg-black/90"
        >
          <Plus className="h-4 w-4" aria-hidden="true" /> New Case
        </button>
      )}
      <button
        type="button"
        onClick={onRepeatCase}
        className={cn(
          'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-clinic-border bg-white px-5 text-[14px] font-semibold text-clinic-ink motion-safe:transition-colors hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-2',
          !canRegister && 'col-span-2',
        )}
      >
        <RotateCcw className="h-4 w-4" aria-hidden="true" /> Repeat Case
      </button>
    </div>
  );
}
