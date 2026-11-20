import { CalendarCheck, FileText, FolderHeart, History, LayoutDashboard, Stethoscope, Wallet, X, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Patient360Zone } from '../types';

/** Each zone's icon and colour family (spec §5): meaningful, not decoration. */
export const ZONE_LOOK: Record<Patient360Zone['key'], { icon: LucideIcon; chip: string; ring: string; ink: string }> = {
  overview: { icon: LayoutDashboard, chip: 'from-[#2CC6B4] to-[#0E9F87]', ring: 'after:bg-[#0E9F87]', ink: 'text-cx-teal-ink' },
  clinical: { icon: Stethoscope, chip: 'from-[#4F9BF0] to-[#2B6FD8]', ring: 'after:bg-[#2B6FD8]', ink: 'text-cx-blue-ink' },
  cases: { icon: FolderHeart, chip: 'from-[#9B7BF2] to-[#6B48DE]', ring: 'after:bg-[#6B48DE]', ink: 'text-cx-violet-ink' },
  visits: { icon: CalendarCheck, chip: 'from-[#2CC6B4] to-[#0E9F87]', ring: 'after:bg-[#0E9F87]', ink: 'text-cx-teal-ink' },
  docs: { icon: FileText, chip: 'from-[#4F9BF0] to-[#2B6FD8]', ring: 'after:bg-[#2B6FD8]', ink: 'text-cx-blue-ink' },
  billing: { icon: Wallet, chip: 'from-[#5FD9A6] to-[#12A877]', ring: 'after:bg-[#12A877]', ink: 'text-cx-green-ink' },
  timeline: { icon: History, chip: 'from-[#9B7BF2] to-[#6B48DE]', ring: 'after:bg-[#6B48DE]', ink: 'text-cx-violet-ink' },
};

/**
 * The seven zones as icon tiles in one row (sharing the width on desktop, a
 * sideways scroller on tablet and phone); the open zone is a raised white tile. The selected case, when there is one, is a quiet
 * context chip here: it changes no zone's records (spec §4).
 */
export function ZoneBar({
  zones,
  active,
  onSelect,
  selectedCase,
  onClearCase,
}: {
  zones: Patient360Zone[];
  active: string;
  onSelect: (key: Patient360Zone['key']) => void;
  selectedCase: { title: string } | null;
  onClearCase: () => void;
}) {
  return (
    <nav aria-label="Patient record" className="rounded-[20px] bg-white/70 p-1.5 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.8)] backdrop-blur">
      <ul className="flex gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {zones.map((zone) => {
          const look = ZONE_LOOK[zone.key];
          const Icon = look.icon;
          const current = zone.key === active;

          return (
            <li key={zone.key} className="shrink-0 xl:flex-1">
              <button
                type="button"
                aria-current={current ? 'page' : undefined}
                onClick={() => onSelect(zone.key)}
                className={cn(
                  'flex h-12 w-full items-center gap-2.5 whitespace-nowrap rounded-[14px] px-3 text-left text-[13.5px] font-semibold transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35',
                  current ? 'bg-white text-cx-nav-ink shadow-cx-card' : 'text-[#5F6B7E] hover:bg-white/80 hover:text-cx-nav-ink',
                )}
              >
                <span
                  aria-hidden="true"
                  className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-[10px]', current ? cn('bg-gradient-to-br text-white shadow-[0_4px_10px_-6px_rgba(30,50,110,0.45)]', look.chip) : cn('bg-cx-slate-bg', look.ink))}
                >
                  <Icon className="h-4 w-4" strokeWidth={1.75} />
                </span>
                {zone.label}
              </button>
            </li>
          );
        })}
      </ul>
      {selectedCase && (
        <p className="mt-1.5 flex items-center gap-2 px-2 pb-0.5 text-[12px] text-cx-text-2">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-cx-violet-bg px-2.5 py-0.5 font-semibold text-cx-violet-ink">
            Case: {selectedCase.title}
            <button type="button" onClick={onClearCase} aria-label="Clear the selected case" className="rounded-full hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
              <X className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </span>
          <span>Highlights the case and picks its workspace; other zones still show all of the patient's records.</span>
        </p>
      )}
    </nav>
  );
}
