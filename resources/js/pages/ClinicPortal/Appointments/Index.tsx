import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarPlus, ChevronLeft, ChevronRight, Search, Settings2, SlidersHorizontal, Stethoscope, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinic } from '@/clinic/context';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Notice } from '@/clinic/ui/Notice';
import { BookingDialog } from './components/BookingDialog';
import { CaseButtons } from './components/CaseButtons';
import { CancelDialog, MoveScopeDialog, RescheduleDialog } from './components/ChangeDialogs';
import { ContextPanel } from './components/ContextPanel';
import { DayList } from './components/DayList';
import { DetailDialog } from './components/DetailDialog';
import { ReceptionIntakeDialog, type IntakeMode, type PatientIdentity } from '@/pages/Clinic/Reception/intake/ReceptionIntakeDialog';
import { activeFilterCount, FiltersDialog } from './components/FiltersDialog';
import { FindNextDialog } from './components/FindNextDialog';
import { LeaveReviewDialog } from './components/LeaveReviewDialog';
import { MonthGrid } from './components/MonthGrid';
import { SettingsDialog } from './components/SettingsDialog';
import { breakFrom, TimeGrid, type MoveRequest } from './components/TimeGrid';
import { daysBetween, dayNumber, rangeLabel, shiftDate, toMinutes, weekdayShort } from './lib/dates';
import { visit } from './lib/navigation';
import { STATUS_STYLE } from './lib/status';
import type { AppointmentItem, BookingDraft, CalendarProps, CalendarView, Filters, StatusValue } from './lib/types';

const VIEWS: { value: CalendarView; label: string }[] = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
];

const LEGEND: StatusValue[] = ['scheduled', 'confirmed', 'arrived', 'waiting', 'in_consultation', 'completed', 'cancelled', 'no_show'];

const CONTROL =
  'border border-white/70 bg-white/[0.93] backdrop-blur-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong';

function useIsPhone(): boolean {
  const query = '(max-width: 639px)';
  const [matches, setMatches] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);

  useEffect(() => {
    const list = window.matchMedia(query);
    const update = () => setMatches(list.matches);
    list.addEventListener('change', update);

    return () => list.removeEventListener('change', update);
  }, []);

  return matches;
}

/**
 * The branch's appointment calendar: date and time first, with every
 * authorised doctor's and therapist's appointments together. Who an
 * appointment is with is written inside it; narrowing to one provider is a
 * filter. Everything comes from the server for the visible range, and the
 * URL holds the view, date and filters.
 */
export default function AppointmentsIndex(props: CalendarProps) {
  const { view, date, today, now, range, filters, appointments, providers, settings, can, links } = props;
  const clinic = useClinic();
  const isPhone = useIsPhone();
  const [search, setSearch] = useState(filters.q);
  const [booking, setBooking] = useState<BookingDraft | null>(null);
  const [selected, setSelected] = useState<AppointmentItem | null>(null);
  const [intake, setIntake] = useState<{ mode: IntakeMode; patient: PatientIdentity | null; appointmentId: number | null } | null>(null);
  const [rescheduling, setRescheduling] = useState<AppointmentItem | null>(null);
  const [scopedMove, setScopedMove] = useState<MoveRequest | null>(null);
  const [cancelling, setCancelling] = useState<AppointmentItem | null>(null);
  const [findNext, setFindNext] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const [moveError, setMoveError] = useState<string | null>(null);
  const [phoneDay, setPhoneDay] = useState(today >= range.start && today <= range.end ? today : range.start);
  const firstSearch = useRef(true);

  useEffect(() => setPhoneDay(today >= range.start && today <= range.end ? today : range.start), [range.start, range.end, today]);

  // Opened from leave review with `?appointment=ID`: show that appointment.
  // Opened from a "New appointment" quick action with `?book=1`: start a booking.
  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const wanted = Number(query.get('appointment'));
    if (wanted) {
      setSelected(appointments.find((appointment) => appointment.id === wanted) ?? null);
    } else if (query.get('book') === '1' && can.create) {
      setBooking({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const go = (next: Partial<{ view: CalendarView; date: string; filters: Filters }>) =>
    visit(links.index, { view: next.view ?? view, date: next.date ?? date, filters: next.filters ?? filters });

  // Server-side search, a moment after typing stops.
  useEffect(() => {
    if (firstSearch.current) {
      firstSearch.current = false;

      return;
    }
    const timer = window.setTimeout(() => {
      if (search.trim() !== filters.q) {
        visit(links.index, { view, date, filters: { ...filters, q: search.trim() } }, { replace: true });
      }
    }, 400);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  // Keep the open detail in step with fresh data after an action.
  useEffect(() => {
    if (selected) {
      setSelected(appointments.find((appointment) => appointment.id === selected.id) ?? props.context?.onLeave.find((a) => a.id === selected.id) ?? null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appointments]);

  const dates = useMemo(() => (view === 'day' ? [date] : daysBetween(range.start, range.end)), [view, date, range.start, range.end]);
  const openBooking = (draft: BookingDraft) => {
    setSelected(null);
    setFindNext(false);
    setBooking(draft);
  };

  const move = (request: MoveRequest) => {
    if (request.appointment.series_id) {
      setScopedMove(request);

      return;
    }
    setMoveError(null);
    router.patch(
      `${links.index}/${request.appointment.id}/reschedule`,
      { scheduled_at: request.start.replace('T', ' '), duration_minutes: request.duration, scope: 'this' },
      {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => setMoveError(Object.values(errors)[0] ?? 'This appointment could not be moved.'),
      },
    );
  };

  const statusFilter = filters.statuses.length === 1 ? filters.statuses[0] : null;
  const filterCount = activeFilterCount(filters);
  const onLeave = props.context?.onLeave ?? [];
  const providerFilter = filters.providers.length === 1 ? String(filters.providers[0]) : filters.providers.length > 1 ? 'some' : '';
  const dayStart = toMinutes(settings.day_start);

  return (
    <ClinicPortalShell title="Appointments">
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-0">
          <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.08em] text-clinic-secondary">Appointments</p>
          <h1 className="text-[22px] font-bold leading-tight tracking-tight text-cx-nav-ink sm:text-[24px]">
            {rangeLabel(view, range.start, range.end, date)}
          </h1>
        </div>
        {/*
          The page's actions stay together at the right, wrapping as one group. Below sm the
          group dissolves: the walk-in pair takes its own full-width row, as on the Reception header.
        */}
        <div className="max-sm:contents sm:ml-auto sm:flex sm:flex-wrap sm:items-center sm:justify-end sm:gap-2">
          {can.check_in && (
            <div className="order-last w-full sm:order-none sm:w-auto">
              <CaseButtons
                canRegister={can.register_patient}
                onNewCase={() => setIntake({ mode: 'new', patient: null, appointmentId: null })}
                onRepeatCase={() => setIntake({ mode: 'repeat', patient: null, appointmentId: null })}
              />
            </div>
          )}
          <div className="ml-auto flex items-center gap-2 sm:ml-0">
            {can.settings && (
              <button
                type="button"
                onClick={() => setSettingsOpen(true)}
                aria-label="Appointment settings"
                className={cn(CONTROL, 'flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:text-clinic-ink')}
              >
                <Settings2 className="h-4 w-4" aria-hidden="true" />
              </button>
            )}
            {can.create && (
              <Button tone="primary" size="lg" icon={CalendarPlus} onClick={() => openBooking({})}>
                <span className="sm:hidden">Book</span>
                <span className="hidden sm:inline">Book appointment</span>
              </Button>
            )}
          </div>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className={cn(CONTROL, 'flex items-center gap-0.5 rounded-full p-1')}>
          <button type="button" aria-label="Previous" onClick={() => go({ date: shiftDate(date, view, -1) })} className={NAV_BUTTON}>
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          </button>
          <button
            type="button"
            onClick={() => go({ date: today })}
            className="h-11 rounded-full px-3.5 text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-9"
          >
            Today
          </button>
          <button type="button" aria-label="Next" onClick={() => go({ date: shiftDate(date, view, 1) })} className={NAV_BUTTON}>
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div role="tablist" aria-label="Calendar view" className={cn(CONTROL, 'flex items-center gap-0.5 rounded-full p-1')}>
          {VIEWS.map((option) => (
            <button
              key={option.value}
              type="button"
              role="tab"
              aria-selected={view === option.value}
              onClick={() => go({ view: option.value })}
              className={cn(
                'h-11 rounded-full px-3.5 text-[12.5px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-9 sm:px-4',
                view === option.value ? 'bg-cx-nav-active text-cx-nav-teal-strong ring-1 ring-inset ring-cx-nav-teal/40' : 'text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
              )}
            >
              {option.label}
            </button>
          ))}
        </div>

        <label className="relative flex min-w-0 basis-full items-center sm:w-[230px] sm:basis-auto">
          <span className="sr-only">Provider</span>
          <Stethoscope className="pointer-events-none absolute left-3.5 z-10 h-4 w-4 text-clinic-secondary" aria-hidden="true" />
          <select
            value={providerFilter}
            onChange={(event) =>
              go({ filters: { ...filters, providers: event.target.value && event.target.value !== 'some' ? [Number(event.target.value)] : [] } })
            }
            className={cn(
              CONTROL,
              'h-11 w-full min-w-0 max-w-full cursor-pointer appearance-none truncate rounded-full pl-10 pr-8 text-[12.5px] font-semibold text-clinic-ink',
            )}
          >
            <option value="">All providers</option>
            {providerFilter === 'some' && <option value="some">Several providers</option>}
            {providers.map((provider) => (
              <option key={provider.id} value={provider.id}>
                {provider.name}
                {provider.is_me ? ' (you)' : ''}
              </option>
            ))}
          </select>
          <ChevronRight className="pointer-events-none absolute right-3 h-3.5 w-3.5 rotate-90 text-clinic-secondary" aria-hidden="true" />
        </label>

        <div className="relative order-last min-w-0 basis-full sm:order-none sm:ml-auto sm:w-[260px] sm:basis-auto">
          <Search className="pointer-events-none absolute left-3.5 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Patient, mobile, ID, APT ref, doctor"
            aria-label="Search appointments"
            className="h-11 w-full rounded-full border border-white/70 bg-white/[0.93] pl-10 pr-10 text-[13px] text-clinic-ink backdrop-blur-sm placeholder:text-clinic-muted focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25"
          />
          {search && (
            <button
              type="button"
              aria-label="Clear search"
              onClick={() => setSearch('')}
              className="absolute right-1 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
        </div>

        <button
          type="button"
          onClick={() => setFiltersOpen(true)}
          className={cn(
            'inline-flex h-11 items-center gap-2 rounded-full border px-4 text-[12.5px] font-semibold backdrop-blur-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
            filterCount > 0 ? 'border-cx-nav-teal/50 bg-cx-nav-active text-cx-nav-teal-strong' : 'border-white/70 bg-white/[0.93] text-clinic-ink hover:border-clinic-border-strong',
          )}
        >
          <SlidersHorizontal className="h-4 w-4" aria-hidden="true" />
          Filters
          {filterCount > 0 && <span className="rounded-full bg-white/20 px-1.5 text-[11px] tabular-nums">{filterCount}</span>}
        </button>
      </div>

      {onLeave.length > 0 && (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-amber-200 bg-amber-50/95 px-4 py-2.5">
          <p className="flex items-center gap-2 text-[12.5px] font-semibold text-amber-900">
            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
            {onLeave.length} upcoming {onLeave.length === 1 ? 'appointment is' : 'appointments are'} with a provider on approved leave.
          </p>
          <button
            type="button"
            onClick={() => setLeaveOpen(true)}
            className="min-h-11 rounded-full bg-white px-3.5 text-[12px] font-bold text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100 sm:min-h-8"
          >
            Review
          </button>
        </div>
      )}
      {moveError && (
        <Notice
          tone="danger"
          title="Not moved"
          className="mb-3"
          action={
            <button type="button" aria-label="Dismiss" onClick={() => setMoveError(null)} className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-white/60">
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          }
        >
          {moveError}
        </Notice>
      )}
      {props.truncated && (
        <Notice tone="warning" className="mb-3">
          This range has more appointments than one view shows. Narrow it with Day view or a provider.
        </Notice>
      )}

      <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
        <section aria-label="Calendar" className="min-w-0 overflow-hidden rounded-3xl border border-white/70 bg-white/[0.93] shadow-[0_1px_2px_rgba(16,24,40,0.04)] backdrop-blur-sm">
          {isPhone && view !== 'month' ? (
            <div>
              {view === 'week' && (
                <div className="grid grid-cols-7 gap-1 border-b border-clinic-border p-2">
                  {dates.map((key) => {
                    const count = appointments.filter((appointment) => appointment.start.startsWith(key)).length;

                    return (
                      <button
                        key={key}
                        type="button"
                        aria-pressed={key === phoneDay}
                        onClick={() => setPhoneDay(key)}
                        className={cn(
                          'flex min-h-[56px] flex-col items-center justify-center rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                          key === phoneDay ? 'bg-[#0F7C6E] text-white' : key === today ? 'bg-clinic-brand-soft text-clinic-brand-strong' : 'text-clinic-ink',
                        )}
                      >
                        <span className="text-[10px] font-semibold uppercase opacity-80">{weekdayShort(key).slice(0, 2)}</span>
                        <span className="text-[15px] font-bold tabular-nums">{dayNumber(key)}</span>
                        <span className={cn('mt-0.5 h-1.5 w-1.5 rounded-full', count ? (key === phoneDay ? 'bg-white' : 'bg-blue-500') : 'bg-transparent')} />
                      </button>
                    );
                  })}
                </div>
              )}
              {(() => {
                const shown = view === 'day' ? date : phoneDay;

                return (
                  <DayList
                    date={shown}
                    today={today}
                    day={props.days[shown]}
                    appointments={appointments.filter((appointment) => appointment.start.startsWith(shown))}
                    onSelect={setSelected}
                    onCreate={can.create ? () => openBooking({ start: `${shown}T${settings.day_start}:00` }) : undefined}
                  />
                );
              })()}
            </div>
          ) : view === 'month' ? (
            <MonthGrid
              date={date}
              today={today}
              days={props.days}
              appointments={appointments}
              onOpenDay={(key) => go({ view: 'day', date: key })}
              onSelect={setSelected}
            />
          ) : (
            <TimeGrid
              dates={dates}
              today={today}
              days={props.days}
              dayWindows={props.dayWindows}
              appointments={appointments}
              freeTimes={view === 'day' ? props.freeTimes : undefined}
              slotMinutes={settings.slot_minutes}
              dayStart={dayStart}
              dayEnd={toMinutes(settings.day_end)}
              breakWindow={breakFrom(settings.break_start, settings.break_end)}
              now={now}
              canCreate={can.create}
              canMove={can.manage}
              onSelect={setSelected}
              onCreate={(start) => openBooking({ start })}
              onMove={move}
            />
          )}

          <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-clinic-border px-4 py-2.5">
            {LEGEND.map((status) => (
              <span key={status} className="inline-flex items-center gap-1.5 text-[11px] font-medium text-clinic-secondary">
                <span className={cn('h-2 w-2 rounded-full', STATUS_STYLE[status].dot)} aria-hidden="true" />
                {STATUS_STYLE[status].label}
              </span>
            ))}
            {appointments.length === 0 && (
              <span className="ml-auto text-[11.5px] font-semibold text-clinic-secondary">
                {filters.q || filterCount > 0 || filters.providers.length > 0 ? 'No appointments match.' : 'Nothing booked in this range.'}
              </span>
            )}
          </div>
        </section>

        <aside aria-label="Availability and today" className="min-w-0">
          <ContextPanel
            context={props.context}
            kpis={props.kpis}
            can={can}
            activeStatus={statusFilter}
            showingToday={view === 'day' && date === today}
            onBook={openBooking}
            onFindNext={() => setFindNext(true)}
            onPickStatus={(status) => go({ view: 'day', date: today, filters: { ...filters, statuses: status ? [status] : [] } })}
          />
        </aside>
      </div>

      {can.create && <BookingDialog open={booking !== null} onOpenChange={(open) => !open && setBooking(null)} draft={booking ?? {}} props={props} />}
      <DetailDialog
        appointment={selected}
        onOpenChange={(open) => !open && setSelected(null)}
        props={props}
        onReschedule={(appointment) => {
          setSelected(null);
          setRescheduling(appointment);
        }}
        onCancel={(appointment) => {
          setSelected(null);
          setCancelling(appointment);
        }}
        onCheckIn={(appointment) => {
          setSelected(null);
          setIntake(appointment.patient ? { mode: 'repeat', patient: appointment.patient, appointmentId: null } : { mode: 'new', patient: null, appointmentId: appointment.id });
        }}
      />
      {can.check_in && (
        <ReceptionIntakeDialog
          open={intake !== null}
          mode={intake?.mode ?? 'repeat'}
          initialPatient={intake?.patient ?? null}
          appointmentId={intake?.appointmentId ?? null}
          branchKey={String(props.branch.id)}
          onOpenChange={(open) => {
            if (!open) {
              setIntake(null);
              router.reload();
            }
          }}
        />
      )}
      <RescheduleDialog appointment={rescheduling} props={props} onClose={() => setRescheduling(null)} />
      <MoveScopeDialog move={scopedMove} props={props} onClose={() => setScopedMove(null)} />
      <CancelDialog appointment={cancelling} props={props} onClose={() => setCancelling(null)} />
      {can.create && <FindNextDialog open={findNext} onOpenChange={setFindNext} props={props} onBook={openBooking} />}
      {can.settings && <SettingsDialog open={settingsOpen} onOpenChange={setSettingsOpen} props={props} />}
      <FiltersDialog
        open={filtersOpen}
        onOpenChange={setFiltersOpen}
        props={props}
        onApply={(next) => {
          setFiltersOpen(false);
          go({ filters: { ...next, providers: filters.providers, q: filters.q } });
        }}
      />
      <LeaveReviewDialog
        open={leaveOpen}
        onOpenChange={setLeaveOpen}
        appointments={onLeave}
        canManage={can.manage}
        onOpen={(appointment) => {
          setLeaveOpen(false);
          setSelected(appointment);
        }}
      />
    </ClinicPortalShell>
  );
}

const NAV_BUTTON =
  'flex h-11 w-11 items-center justify-center rounded-full text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-9 sm:w-9';
