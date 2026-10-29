import { useState, type ReactNode } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { personInitials } from '@/clinic/ui/PersonAvatar';

/*
 * The patient's visual identity, the same in the directory and in Patient
 * 360: the gradient avatar tile, the lifecycle badge and the masked mobile
 * number. Only the server decides what is sent; these only present it.
 */

/** The avatar tints: teal, cyan, blue, violet, mint — the same patient, the same colour everywhere. */
const AVATAR_TONES = [
  'from-[#D2F5EE] to-[#B9ECE2] text-[#0A6D62]',
  'from-[#D6F1FB] to-[#BFE6F7] text-[#0B6585]',
  'from-[#DEE8FE] to-[#C9DAFC] text-[#1D55B0]',
  'from-[#E9E3FE] to-[#D9CFFC] text-[#5235B5]',
  'from-[#D8F5E6] to-[#C0EDD6] text-[#11704A]',
] as const;

const AVATAR_SIZES = {
  md: 'h-11 w-11 rounded-[14px] text-[14px]',
  lg: 'h-14 w-14 rounded-[16px] text-[18px] sm:h-16 sm:w-16 sm:rounded-[18px] sm:text-[20px]',
  xl: 'h-[68px] w-[68px] rounded-[20px] text-[22px] sm:h-[84px] sm:w-[84px] sm:rounded-[24px] sm:text-[27px]',
  /** Patient 360's header: a round portrait-sized avatar (there is no patient photo yet). */
  portrait: 'h-[60px] w-[60px] rounded-full text-[20px] sm:h-[76px] sm:w-[76px] sm:text-[25px]',
} as const;

/** The lifecycle states worth noticing. A current patient carries no badge. */
const LIFECYCLE: Record<string, { label: string; className: string }> = {
  deceased: { label: 'Deceased', className: 'border-cx-slate-bg bg-cx-slate-bg text-cx-slate-ink' },
  merged: { label: 'Merged', className: 'border-cx-m-purple-ring bg-cx-m-purple-bg text-cx-violet-ink' },
};

function tone(name: string): string {
  let hash = 0;
  for (let index = 0; index < name.length; index++) {
    hash = (hash * 31 + name.charCodeAt(index)) >>> 0;
  }

  return AVATAR_TONES[hash % AVATAR_TONES.length];
}

/** Decorative: the name beside it is what screen readers read. A merged record is shown quietly. */
export function PatientAvatar({ name, size = 'md', quiet = false }: { name: string; size?: keyof typeof AVATAR_SIZES; quiet?: boolean }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 select-none place-items-center bg-gradient-to-br font-semibold leading-none shadow-[inset_0_0_0_1px_rgba(255,255,255,0.6)]',
        AVATAR_SIZES[size],
        quiet ? 'from-[#EEF1F5] to-[#E2E7EE] text-cx-slate-ink' : tone(name.trim().toLowerCase()),
      )}
    >
      {personInitials(name)}
    </span>
  );
}

export function LifecycleBadge({ status }: { status: string }) {
  const lifecycle = LIFECYCLE[status];
  if (!lifecycle) {
    return null;
  }

  return (
    <Badge className={lifecycle.className}>{lifecycle.label}</Badge>
  );
}

export function Badge({ className, children }: { className: string; children: ReactNode }) {
  return (
    <span className={cn('inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-sm border px-2 text-[12px] font-semibold leading-4', className)}>
      <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
      {children}
    </span>
  );
}

/**
 * The mobile number, masked by default. The server sends the full number
 * only to members whose work is contacting patients; for them it can be
 * shown, for everyone else there is nothing to reveal.
 */
export function MaskedMobile({ name, phone, lastDigits }: { name: string; phone: string | null; lastDigits: string | null }) {
  const [shown, setShown] = useState(false);

  if (!lastDigits) {
    return <span className="block truncate text-[14px] leading-5 text-cx-text-2">No mobile</span>;
  }

  return (
    <span className="flex min-w-0 items-center gap-1">
      <span className="truncate text-[14px] font-medium leading-5 tabular-nums text-cx-text">
        {shown && phone ? (
          phone
        ) : (
          <>
            <span className="sr-only">Mobile ending </span>
            {/* A shorter mask until wide screens, where the fact shares a narrow column. */}
            <span aria-hidden="true" className="font-normal tracking-[0.06em] text-cx-text-3">
              •••<span className="hidden xl:inline">•••</span>{' '}
            </span>
            {lastDigits}
          </>
        )}
      </span>
      {phone && (
        <button
          type="button"
          onClick={() => setShown((value) => !value)}
          aria-pressed={shown}
          aria-label={shown ? `Hide ${name}’s mobile number` : `Show ${name}’s mobile number`}
          className="relative z-10 -my-2 grid h-8 w-8 shrink-0 place-items-center rounded-sm text-cx-text-3 hover:bg-white/80 hover:text-cx-m-blue focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan [@media(pointer:coarse)]:h-11 [@media(pointer:coarse)]:w-11"
        >
          {shown ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
        </button>
      )}
    </span>
  );
}
