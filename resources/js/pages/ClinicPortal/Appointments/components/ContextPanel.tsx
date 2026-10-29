import type { ReactNode } from 'react';
import { CalendarPlus, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/clinic/ui/Spinner';
import { shortDate, timeOf } from '../lib/dates';
import { initials, providerTone, STATUS_STYLE } from '../lib/status';
import type { BookingDraft, CalendarContext, CalendarProps, Kpis, StatusValue } from '../lib/types';

const PROVIDER_STATE: Record<string, { dot: string; text: string }> = {
  available: { dot: 'bg-emerald-500', text: 'text-emerald-700' },
  busy: { dot: 'bg-violet-500', text: 'text-violet-700' },
  off_shift: { dot: 'bg-slate-300', text: 'text-clinic-secondary' },
  on_leave: { dot: 'bg-amber-500', text: 'text-amber-800' },
  closed: { dot: 'bg-slate-300', text: 'text-clinic-secondary' },
};

const SUMMARY: { key: keyof Kpis; label: string; status: StatusValue | null }[] = [
  { key: 'today', label: 'Booked', status: null },
  { key: 'confirmed', label: 'Confirmed', status: 'confirmed' },
  { key: 'arrived', label: 'Arrived', status: 'arrived' },
  { key: 'waiting', label: 'Waiting', status: 'waiting' },
  { key: 'completed', label: 'Completed', status: 'completed' },
  { key: 'cancelled', label: 'Cancelled', status: 'cancelled' },
  { key: 'no_show', label: 'No-show', status: 'no_show' },
];

/**
 * Beside the calendar: the two booking actions, who can be booked and when,
 * and today's figures. Provider availability loads after the calendar.
 */
export function ContextPanel({
  context,
  kpis,
  can,
  activeStatus,
  showingToday,
  onBook,
  onFindNext,
  onPickStatus,
}: {
  context: CalendarContext | undefined;
  kpis: Kpis;
  can: CalendarProps['can'];
  activeStatus: StatusValue | null;
  showingToday: boolean;
  onBook: (draft: BookingDraft) => void;
  onFindNext: () => void;
  onPickStatus: (status: StatusValue | null) => void;
}) {
  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-1">
      <div className="grid gap-4">
        {can.create && (
          <Card title="Quick actions">
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => onBook({})}
                className="flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-[#1769AA] px-3 py-2.5 text-[12.5px] font-semibold text-white hover:bg-[#135A93] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
              >
                <CalendarPlus className="h-4 w-4 shrink-0" aria-hidden="true" /> Book
              </button>
              <button
                type="button"
                onClick={onFindNext}
                aria-label="Find next available"
                className="flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-clinic-inset px-3 py-2.5 text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
              >
                <Search className="h-4 w-4 shrink-0" aria-hidden="true" /> Next free
              </button>
            </div>
          </Card>
        )}

        <Card title="Today">
          <ul className="grid grid-cols-2 gap-1.5">
            {SUMMARY.map((item) => {
              const active = item.status === null ? showingToday && activeStatus === null : activeStatus === item.status;
              const dot = item.status ? STATUS_STYLE[item.status].dot : 'bg-clinic-ink';

              return (
                <li key={item.key} className={item.key === 'today' ? 'col-span-2' : undefined}>
                  <button
                    type="button"
                    aria-pressed={active}
                    onClick={() => onPickStatus(active ? null : item.status)}
                    className={cn(
                      'flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                      active ? 'bg-cx-nav-active font-semibold text-cx-nav-teal-strong ring-1 ring-inset ring-cx-nav-teal/40' : 'bg-clinic-canvas text-clinic-ink hover:bg-clinic-inset',
                    )}
                  >
                    <span className="flex min-w-0 items-center gap-2">
                      <span className={cn('h-2 w-2 shrink-0 rounded-full', active && item.status === null ? 'bg-white' : dot)} aria-hidden="true" />
                      <span className={cn('truncate text-[12px] font-semibold', active ? 'text-white/85' : 'text-clinic-secondary')}>{item.label}</span>
                    </span>
                    <span className="text-[16px] font-bold tabular-nums">{kpis[item.key]}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </Card>
      </div>

      <Card title="Provider availability" aside={context ? `${context.providers.length}` : undefined}>
        {!context ? (
          <div role="status" aria-label="Loading" className="space-y-2">
            <Skeleton className="h-11 w-full" />
            <Skeleton className="h-11 w-full" />
            <Skeleton className="h-11 w-2/3" />
          </div>
        ) : context.providers.length === 0 ? (
          <p className="rounded-xl bg-clinic-canvas px-3 py-2.5 text-[12.5px] text-clinic-secondary">
            No doctors or therapists work at this branch yet. Add one in Staff.
          </p>
        ) : (
          <ul className="-mx-1 max-h-[420px] space-y-0.5 overflow-y-auto">
            {context.providers.map((provider) => {
              const tone = PROVIDER_STATE[provider.state] ?? PROVIDER_STATE.off_shift;

              return (
                <li key={provider.id} className="flex items-center gap-2.5 rounded-xl px-1 py-1.5">
                  <span className="relative shrink-0">
                    <span className={cn('flex h-9 w-9 items-center justify-center rounded-full text-[11px] font-bold', providerTone(provider.id))}>
                      {initials(provider.name)}
                    </span>
                    <span className={cn('absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-white', tone.dot)} aria-hidden="true" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-semibold text-clinic-ink">{provider.name}</span>
                    <span className={cn('block truncate text-[11.5px] font-medium', tone.text)}>
                      {provider.label} · {provider.booked_today} today
                    </span>
                  </span>
                  {provider.next_slot ? (
                    can.create ? (
                      <button
                        type="button"
                        onClick={() => onBook({ start: provider.next_slot!, providerId: provider.id })}
                        aria-label={`Book ${provider.name} at their next free time, ${slotLabel(provider.next_slot)}`}
                        className="min-h-11 shrink-0 rounded-full bg-emerald-50 px-2.5 text-[11.5px] font-bold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 sm:min-h-8"
                      >
                        {slotLabel(provider.next_slot)}
                      </button>
                    ) : (
                      <span className="shrink-0 text-[11.5px] font-semibold text-clinic-secondary">{slotLabel(provider.next_slot)}</span>
                    )
                  ) : (
                    <span className="shrink-0 text-[11.5px] text-clinic-secondary">Fully booked</span>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Card>
    </div>
  );
}

function slotLabel(start: string): string {
  return `${shortDate(start.slice(0, 10)).split(' ').slice(0, 2).join(' ')}, ${timeOf(start)}`;
}

function Card({ title, aside, children }: { title: string; aside?: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="rounded-3xl border border-white/70 bg-white/[0.93] p-4 backdrop-blur-sm">
      <div className="mb-2.5 flex items-center justify-between gap-2">
        <h2 className="text-[14px] font-bold tracking-tight text-clinic-ink">{title}</h2>
        {aside && <span className="rounded-full bg-clinic-inset px-2 py-0.5 text-[11px] font-bold tabular-nums text-clinic-ink">{aside}</span>}
      </div>
      {children}
    </section>
  );
}
