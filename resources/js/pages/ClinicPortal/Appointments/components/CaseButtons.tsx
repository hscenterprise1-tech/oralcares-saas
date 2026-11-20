import { RotateCcw, UserPlus } from 'lucide-react';
import { DsButton } from '@/clinic/ds';
import { cn } from '@/lib/utils';

/**
 * The walk-in starts: New Case and Repeat Case, each opening the one
 * canonical intake (`ReceptionIntakeDialog`). Uses the approved DsButton design.
 */
export function CaseButtons({ canRegister, onNewCase, onRepeatCase }: {
  canRegister: boolean;
  onNewCase: () => void;
  onRepeatCase: () => void;
}) {
  return (
    <div role="group" aria-label="Start a case" className="grid grid-cols-2 gap-2.5 sm:flex sm:shrink-0">
      {canRegister && (
        <DsButton variant="primary" size="lg" icon={UserPlus} onClick={onNewCase}>
          New Case
        </DsButton>
      )}
      <DsButton
        variant="secondary"
        size="lg"
        icon={RotateCcw}
        onClick={onRepeatCase}
        className={cn('shadow-[0_1px_2px_rgba(15,23,42,0.06)]', !canRegister && 'col-span-2')}
      >
        Repeat Case
      </DsButton>
    </div>
  );
}
