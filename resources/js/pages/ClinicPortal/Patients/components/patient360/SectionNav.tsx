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
    <nav aria-label="Patient record" className="overflow-x-auto rounded-xl bg-[#EEF2F7] p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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
                  'inline-flex h-9 items-center whitespace-nowrap rounded-lg px-3.5 text-[13.5px] font-semibold motion-safe:transition-colors [@media(pointer:coarse)]:h-11',
                  FOCUS_RING,
                  current ? 'bg-white text-[#173B60] shadow-[0_1px_3px_rgba(15,23,42,0.12)]' : 'text-[#64748B] hover:text-[#173B60]',
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
