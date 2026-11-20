import { Activity, ArrowRight, Smile, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { shortDate } from '../../format';
import { CASE_STATUS } from '../CasesSection';
import type { ActiveCase } from '../types';

const MODULE = {
  dental: { icon: Smile, tile: 'bg-cx-blue-bg text-cx-blue-ink', wash: 'from-[#EEF5FF] to-[#E2ECFD]' },
  physio: { icon: Activity, tile: 'bg-cx-violet-bg text-cx-violet-ink', wash: 'from-[#F4F0FF] to-[#EAE3FD]' },
  general: { icon: Stethoscope, tile: 'bg-cx-slate-bg text-cx-slate-ink', wash: 'from-[#F4F6FA] to-[#EBEFF5]' },
} as const;

/**
 * The persistent Active Cases area (spec §4): open cases of the modules the
 * clinic offers, from the server. Each card shows what the member may see —
 * a non-clinical member gets title, specialty, status, lead and date only
 * (the server sends no summary), so no progress line is drawn. Selecting a
 * card is client-side context only.
 */
export function ActiveCases({ cases, selected, onSelect, onOpen }: { cases: ActiveCase[]; selected: number | null; onSelect: (id: number | null) => void; onOpen: (id: number) => void }) {
  if (cases.length === 0) {
    return null;
  }

  return (
    <section aria-label="Active cases">
      <p className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-cx-text-2">Active cases</p>
      <ul className="flex snap-x gap-3 overflow-x-auto pb-1 [scrollbar-width:thin]">
        {cases.map((item) => {
          const look = MODULE[item.module] ?? MODULE.general;
          const Icon = look.icon;
          const isSelected = selected === item.id;
          const status = CASE_STATUS[item.status] ?? CASE_STATUS.active;

          return (
            <li key={item.id} className="w-[290px] shrink-0 snap-start">
              <div
                className={cn(
                  'flex h-full flex-col rounded-[20px] bg-white p-4 shadow-cx-card ring-1 ring-inset transition',
                  isSelected ? cn('bg-gradient-to-br ring-[#1769AA]/30', look.wash) : 'ring-transparent',
                )}
              >
                <button
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => onSelect(isSelected ? null : item.id)}
                  className="flex flex-1 items-start gap-3 rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35"
                >
                  <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-[12px]', look.tile)} aria-hidden="true">
                    <Icon className="h-5 w-5" strokeWidth={1.75} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5 text-[12px] font-semibold text-cx-text-2">
                      {item.specialty}
                      <span className={cn('rounded-full px-2 py-0.5 text-[11px]', status.tone)}>{status.label}</span>
                    </span>
                    <span className="mt-0.5 block text-[14px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">{item.title}</span>
                    <span className="mt-0.5 block text-[12px] text-cx-text-2">{[item.lead ? `Lead: ${item.lead}` : null, item.opened_at ? `since ${shortDate(item.opened_at)}` : null].filter(Boolean).join(' · ')}</span>
                    {item.summary.map((line) => (
                      <span key={line} className="mt-1 block text-[12px] font-medium text-cx-text [overflow-wrap:anywhere]">
                        {line}
                      </span>
                    ))}
                  </span>
                </button>
                <button
                  type="button"
                  onClick={() => onOpen(item.id)}
                  className="mt-3 inline-flex items-center gap-1 self-start rounded-full text-[12.5px] font-semibold text-cx-primary hover:text-cx-primary-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35"
                >
                  Open case <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
