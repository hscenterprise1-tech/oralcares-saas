import { cn } from '@/lib/utils';
import { FOCUS_RING } from '@/clinic/ds';
import type { Patient360Section } from './types';

/**
 * Patient 360's sections, in the order the server chose for this member's
 * role. Only sections whose screen is built are listed (`built`, from
 * `ClinicFrontend::PATIENT_360_SECTIONS`); the rest join as they land. The
 * front desk's segmented style: a soft track with the open section raised
 * in white; the track scrolls sideways on narrow screens rather than wrapping.
 */
export function SectionNav({ sections, active, onSelect }: { sections: Patient360Section[]; active: string; onSelect: (key: string) => void }) {
  const built = sections.filter((section) => section.built);

  return (
    <nav
      aria-label="Patient record"
      className="inline-flex max-w-full overflow-x-auto rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
      <ul className="flex min-w-max gap-1">
        {built.map((section) => {
          const current = section.key === active;

          return (
            <li key={section.key}>
              <button
                type="button"
                aria-current={current ? 'page' : undefined}
                onClick={() => onSelect(section.key)}
                className={cn(
                  'inline-flex h-8 items-center whitespace-nowrap rounded-full px-4 text-[13px] font-semibold transition-colors [@media(pointer:coarse)]:h-10',
                  FOCUS_RING,
                  current
                    ? 'bg-[#E7F0FD] text-[#1769AA] shadow-xs'
                    : 'text-[#5F6B7E] hover:text-[#173B60]',
                )}
              >
                {section.label}
              </button>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
