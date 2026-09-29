import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { CrmCard, CrmPageHead, CrmSelect } from '@/pages/Company/design';
import { ClinicShell } from '../components/ClinicShell';
import { shiftDateKey, toDateKey } from './dateMath';
import { AgendaList } from './components/AgendaList';
import { CalendarHeader } from './components/CalendarHeader';
import { DayView } from './components/DayView';
import { DoctorFilter } from './components/DoctorFilter';
import { EventPopover, type PopoverAnchorRect } from './components/EventPopover';
import { MobileDateStrip } from './components/MobileDateStrip';
import { MonthView } from './components/MonthView';
import { NewAppointmentModal } from './components/NewAppointmentModal';
import { StatusLegend } from './components/StatusLegend';
import { WeekView } from './components/WeekView';
import { AppointmentRow, AppointmentsIndexProps, CalendarView } from './types';

/*
 * Day/Week/Month appointment calendar — custom-built (no FullCalendar; see
 * `TimeGrid.tsx`/`gridLayout.ts` for why). One rule holds the whole page
 * together: the URL (`date`/`view`/`practitioner_ids`/`room_id`) is the
 * only place "what's showing" lives. Every control that changes the view —
 * Prev/Today/Next, the date-picker, the Day/Week/Month toggle, a month
 * cell, the mobile date strip — calls `navigate()` and nothing else ever
 * touches `date`/`view`. No component below this one keeps its own copy;
 * that's what a previous version's Month view did (a local `useState`),
 * which is exactly how the "date resets" bug happened.
 */

export default function AppointmentsIndex({
  date,
  view,
  headerTitle,
  headerSubtitle,
  practitioners,
  allPractitioners,
  appointments,
  leaveEmployeeIds,
  operatingHours,
  rooms,
  filters,
}: AppointmentsIndexProps) {
  const [modalOpen, setModalOpen] = useState(false);
  const [modalDefaults, setModalDefaults] = useState<{ time?: string; date?: string }>({});
  const [popover, setPopover] = useState<{ appointment: AppointmentRow; anchorRect: PopoverAnchorRect } | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusUpdating, setStatusUpdating] = useState(false);

  const navigate = (
    nextDate: string,
    nextView: CalendarView = view,
    nextPractitionerIds = filters.practitioner_ids,
    nextRoomId = filters.room_id
  ) => {
    // A full Inertia visit, not a partial `only: [...]` reload — `date`,
    // `view`, `headerTitle`, `rangeStart`, `leaveEmployeeIds` etc. all
    // depend on the same date/view, so refreshing just `appointments`
    // would leave the rest stale and reintroduce a variant of the same
    // "URL and what's on screen disagree" bug this rebuild removes.
    router.get(
      route('clinic.appointments.index'),
      {
        date: nextDate,
        view: nextView,
        practitioner_ids: nextPractitionerIds.length > 0 ? nextPractitionerIds : undefined,
        room_id: nextRoomId || undefined,
      },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const shiftDate = (direction: 1 | -1) => navigate(shiftDateKey(date, view, direction));

  const changeView = (nextView: CalendarView) => navigate(date, nextView);

  // An empty `practitioner_ids` filter means "show every doctor" (both
  // here and in the backend query), not "none selected". Toggling one off
  // from that all-selected state has to expand to "everyone except this
  // one" first — resolving against the empty array directly would instead
  // turn "deselect one doctor" into "select only this doctor".
  const toggleDoctor = (id: number) => {
    const allIds = allPractitioners.map((p) => p.id);
    const current = filters.practitioner_ids.length > 0 ? filters.practitioner_ids : allIds;
    const next = current.includes(id) ? current.filter((p) => p !== id) : [...current, id];
    navigate(date, view, next.length === allIds.length ? [] : next);
  };

  const filterByRoom = (roomId: string) => {
    navigate(date, view, filters.practitioner_ids, roomId === 'all' ? null : roomId);
  };

  const openNewAppointment = (time?: string, dateOverride?: string) => {
    setModalDefaults({ time, date: dateOverride });
    setModalOpen(true);
  };

  const openEventPopover = (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => setPopover({ appointment, anchorRect });

  const setStatus = (status: string) => {
    if (!popover) return;
    setStatusUpdating(true);
    router.patch(
      route('clinic.appointments.status', popover.appointment.id),
      { status },
      { preserveScroll: true, onSuccess: () => setPopover(null), onFinish: () => setStatusUpdating(false) }
    );
  };

  const filteredAppointments = searchQuery.trim()
    ? appointments.filter((a) => a.patient_name.toLowerCase().includes(searchQuery.trim().toLowerCase()))
    : appointments;

  const leaveDoctors = practitioners.filter((p) => leaveEmployeeIds.includes(p.id));
  const doctorName = popover ? allPractitioners.find((p) => p.id === popover.appointment.practitioner_id)?.name : undefined;

  return (
    <ClinicShell title="Appointments">
      <CrmPageHead eyebrow="Clinic" title="Appointments" />

      <div className="space-y-4 pb-16">
        <CrmCard className="space-y-3">
          <CalendarHeader
            date={date}
            view={view}
            headerTitle={headerTitle}
            headerSubtitle={headerSubtitle}
            searchQuery={searchQuery}
            onDateChange={(d) => navigate(d)}
            onViewChange={changeView}
            onShift={shiftDate}
            onToday={() => navigate(toDateKey(new Date()))}
            onSearchChange={setSearchQuery}
            onNewAppointment={() => openNewAppointment()}
          />
          <StatusLegend />
        </CrmCard>

        {(allPractitioners.length > 0 || rooms.length > 0) && (
          <div className="flex flex-wrap items-center gap-2 px-1">
            <DoctorFilter practitioners={allPractitioners} selectedIds={filters.practitioner_ids} onToggle={toggleDoctor} />

            {rooms.length > 0 && (
              <div className="w-40">
                <CrmSelect
                  value={filters.room_id ? String(filters.room_id) : 'all'}
                  onValueChange={filterByRoom}
                  options={[{ value: 'all', label: 'All rooms' }, ...rooms.map((r) => ({ value: String(r.id), label: r.name }))]}
                />
              </div>
            )}
          </div>
        )}

        {allPractitioners.length === 0 ? (
          <CrmCard className="flex flex-col items-center gap-3 py-16 text-center">
            <Users className="h-8 w-8 text-ovs-crm-text-muted" aria-hidden="true" />
            <p className="text-[13.5px] font-medium text-ovs-crm-text-primary">No active doctors to schedule against yet</p>
            <p className="max-w-xs text-[12.5px] text-ovs-crm-text-muted">Add a practitioner from Staff before booking appointments.</p>
          </CrmCard>
        ) : view === 'month' ? (
          <MonthView
            date={date}
            appointments={filteredAppointments}
            practitioners={allPractitioners}
            leaveEmployeeIds={leaveEmployeeIds}
            onMobileDateSelect={(d) => navigate(d, 'month')}
            onDesktopDayOpen={(d) => navigate(d, 'day')}
            onEventClick={openEventPopover}
          />
        ) : view === 'week' ? (
          <>
            <div className="space-y-3 md:hidden">
              <MobileDateStrip date={date} onSelect={(d) => navigate(d, 'week')} />
              <AgendaList
                appointments={filteredAppointments.filter((a) => a.scheduled_at.slice(0, 10) === date)}
                practitioners={practitioners}
                leaveEmployeeIds={leaveEmployeeIds}
                onEventClick={openEventPopover}
              />
            </div>
            <div className="hidden md:block">
              <WeekView
                date={date}
                appointments={filteredAppointments}
                practitioners={practitioners}
                leaveDoctors={leaveDoctors}
                onSlotSelect={(dayKey, time) => openNewAppointment(time, dayKey)}
                onEventClick={openEventPopover}
              />
            </div>
          </>
        ) : (
          <>
            <div className="md:hidden">
              <AgendaList appointments={filteredAppointments} practitioners={practitioners} leaveEmployeeIds={leaveEmployeeIds} onEventClick={openEventPopover} />
            </div>
            <div className="hidden md:block">
              <DayView
                date={date}
                appointments={filteredAppointments}
                practitioners={practitioners}
                leaveDoctors={leaveDoctors}
                onSlotSelect={(time) => openNewAppointment(time)}
                onEventClick={openEventPopover}
              />
            </div>
          </>
        )}
      </div>

      <NewAppointmentModal
        key={`${modalDefaults.time ?? 'none'}-${modalDefaults.date ?? 'none'}`}
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        date={modalDefaults.date ?? date}
        defaultTime={modalDefaults.time}
        practitioners={allPractitioners}
        rooms={rooms}
      />

      <EventPopover
        appointment={popover?.appointment ?? null}
        anchorRect={popover?.anchorRect ?? null}
        doctorName={doctorName}
        processing={statusUpdating}
        onClose={() => setPopover(null)}
        onStatusChange={setStatus}
      />
    </ClinicShell>
  );
}
