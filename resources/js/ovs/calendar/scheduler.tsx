import React, { useEffect, useRef, useState } from 'react';
import { Calendar, CalendarOptions } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import resourceDayGridPlugin from '@fullcalendar/resource-daygrid';
import resourceTimeGridPlugin from '@fullcalendar/resource-timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import { cn } from '@/lib/utils';
import { OVSSegmentedControl } from '@/ovs/navigation';
import {
  SchedulerEvent,
  SchedulerResource,
  SchedulerViewMode,
  SlotSelectInfo,
  EventClickInfo,
  EventRescheduleInfo,
} from './types';
import './scheduler.css';

export interface OVSSchedulerProps {
  events: SchedulerEvent[];
  resources?: SchedulerResource[];
  initialView?: SchedulerViewMode;
  viewMode?: SchedulerViewMode;
  onViewChange?: (view: SchedulerViewMode) => void;
  height?: string | number;
  editable?: boolean;
  selectable?: boolean;
  slotDuration?: string;
  minTime?: string;
  maxTime?: string;
  businessHours?: boolean | any;
  showToolbar?: boolean;
  onSlotSelect?: (info: SlotSelectInfo) => void;
  onEventClick?: (info: EventClickInfo) => void;
  onEventReschedule?: (info: EventRescheduleInfo) => void;
  className?: string;
}

export function OVSScheduler({
  events,
  resources,
  initialView,
  viewMode: controlledViewMode,
  onViewChange,
  height = 'auto',
  editable = true,
  selectable = true,
  slotDuration = '00:30:00',
  minTime = '08:00:00',
  maxTime = '20:00:00',
  businessHours = true,
  showToolbar = true,
  onSlotSelect,
  onEventClick,
  onEventReschedule,
  className = '',
}: OVSSchedulerProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const calendarRef = useRef<Calendar | null>(null);

  const hasResources = Array.isArray(resources) && resources.length > 0;
  const defaultInitial = initialView || (hasResources ? 'resourceTimelineDay' : 'timeGridWeek');

  const [activeView, setActiveView] = useState<SchedulerViewMode>(
    controlledViewMode || defaultInitial
  );

  const currentView = controlledViewMode || activeView;

  const handleViewChange = (newView: SchedulerViewMode) => {
    setActiveView(newView);
    if (onViewChange) onViewChange(newView);
    if (calendarRef.current) {
      calendarRef.current.changeView(newView);
    }
  };

  // Convert raw events to FullCalendar event format with semantic styling
  const formattedEvents = React.useMemo(() => {
    return events.map((evt) => {
      const stateStyles = {
        confirmed: { bg: '#ECFDF5', border: '#6EE7B7', text: '#064E3B' },
        in_progress: { bg: '#F0FDFA', border: '#5EEAD4', text: '#134E4A' },
        scheduled: { bg: '#F8FAFC', border: '#CBD5E1', text: '#0F172A' },
        completed: { bg: '#F1F5F9', border: '#CBD5E1', text: '#475569' },
        cancelled: { bg: '#FFF1F2', border: '#FECDD3', text: '#881337' },
        no_show: { bg: '#FFFBEB', border: '#FDE68A', text: '#78350F' },
        blocked: { bg: '#F1F5F9', border: '#94A3B8', text: '#475569' },
      }[evt.state || 'scheduled'];

      return {
        id: evt.id,
        title: evt.title,
        start: evt.start,
        end: evt.end,
        resourceId: evt.resourceId,
        backgroundColor: evt.backgroundColor || stateStyles.bg,
        borderColor: evt.borderColor || stateStyles.border,
        textColor: evt.textColor || stateStyles.text,
        extendedProps: {
          originalEvent: evt,
          state: evt.state,
          patientName: evt.patientName,
          doctorName: evt.doctorName,
          procedureName: evt.procedureName,
          chairName: evt.chairName,
          branchName: evt.branchName,
          amountFormatted: evt.amountFormatted,
        },
      };
    });
  }, [events]);

  useEffect(() => {
    if (!containerRef.current) return;

    const calendarOptions: CalendarOptions = {
      plugins: [
        dayGridPlugin,
        timeGridPlugin,
        resourceTimelinePlugin,
        resourceDayGridPlugin,
        resourceTimeGridPlugin,
        interactionPlugin,
        listPlugin,
      ],
      initialView: currentView,
      headerToolbar: false, // We use custom OVS toolbar controls
      resources: hasResources ? resources : undefined,
      events: formattedEvents,
      editable,
      selectable,
      selectMirror: true,
      dayMaxEvents: 2,
      height,
      slotDuration,
      slotMinTime: minTime,
      slotMaxTime: maxTime,
      nowIndicator: true,
      businessHours: businessHours
        ? {
            daysOfWeek: [1, 2, 3, 4, 5, 6], // Mon - Sat
            startTime: '08:30',
            endTime: '19:30',
          }
        : false,
      schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
      select: (selectInfo) => {
        if (onSlotSelect) {
          onSlotSelect({
            start: selectInfo.start,
            end: selectInfo.end,
            resourceId: (selectInfo as any).resource?.id,
            allDay: selectInfo.allDay,
          });
        }
      },
      eventClick: (clickInfo) => {
        if (onEventClick) {
          const original = clickInfo.event.extendedProps.originalEvent || {
            id: clickInfo.event.id,
            title: clickInfo.event.title,
            start: clickInfo.event.startStr,
            end: clickInfo.event.endStr,
          };
          onEventClick({
            id: clickInfo.event.id,
            event: original,
            rawEvent: clickInfo.event,
          });
        }
      },
      eventDrop: (dropInfo) => {
        if (onEventReschedule) {
          const original = dropInfo.event.extendedProps.originalEvent || {
            id: dropInfo.event.id,
            title: dropInfo.event.title,
            start: dropInfo.event.startStr,
            end: dropInfo.event.endStr,
          };
          onEventReschedule({
            event: original,
            oldEvent: dropInfo.oldEvent,
            delta: dropInfo.delta,
          });
        }
      },
      eventResize: (resizeInfo) => {
        if (onEventReschedule) {
          const original = resizeInfo.event.extendedProps.originalEvent || {
            id: resizeInfo.event.id,
            title: resizeInfo.event.title,
            start: resizeInfo.event.startStr,
            end: resizeInfo.event.endStr,
          };
          onEventReschedule({
            event: original,
            oldEvent: resizeInfo.oldEvent,
            delta: (resizeInfo as any).endDelta,
          });
        }
      },
    };

    const calendar = new Calendar(containerRef.current, calendarOptions);
    calendar.render();
    calendarRef.current = calendar;

    return () => {
      calendar.destroy();
      calendarRef.current = null;
    };
  }, [hasResources, minTime, maxTime, slotDuration, height]);

  // Update events & view dynamically
  useEffect(() => {
    if (!calendarRef.current) return;
    calendarRef.current.removeAllEvents();
    formattedEvents.forEach((evt) => calendarRef.current?.addEvent(evt));
  }, [formattedEvents]);

  useEffect(() => {
    if (!calendarRef.current) return;
    if (calendarRef.current.view.type !== currentView) {
      calendarRef.current.changeView(currentView);
    }
  }, [currentView]);

  return (
    <div
      className={cn(
        'ovs-scheduler-wrapper bg-white border border-slate-200/90 rounded-xl shadow-2xs font-sans text-slate-900 overflow-hidden',
        className
      )}
    >
      {/* ── 1. CANONICAL OVS SCHEDULER TOOLBAR ─────────────────────────────── */}
      {showToolbar && (
        <div className="p-3.5 sm:p-4 bg-slate-50/80 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          {/* Navigation Controls (Prev, Today, Next) */}
          <div className="flex items-center gap-1.5">
            <button
              type="button"
              onClick={() => calendarRef.current?.prev()}
              className="h-7 w-7 rounded-md bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 hover:text-slate-900 flex items-center justify-center text-xs font-bold transition-colors cursor-pointer select-none"
              title="Previous"
            >
              ‹
            </button>
            <button
              type="button"
              onClick={() => calendarRef.current?.today()}
              className="h-7 px-2.5 rounded-md bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 hover:text-slate-900 flex items-center justify-center text-xs font-semibold transition-colors cursor-pointer select-none"
            >
              Today
            </button>
            <button
              type="button"
              onClick={() => calendarRef.current?.next()}
              className="h-7 w-7 rounded-md bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 hover:text-slate-900 flex items-center justify-center text-xs font-bold transition-colors cursor-pointer select-none"
              title="Next"
            >
              ›
            </button>
          </div>

          {/* View Switching via Canonical OVSSegmentedControl */}
          <div className="self-start sm:self-auto overflow-x-auto">
            {hasResources ? (
              <OVSSegmentedControl
                value={currentView}
                onChange={(v) => handleViewChange(v as SchedulerViewMode)}
                options={[
                  { value: 'resourceTimelineDay', label: 'Day Timeline' },
                  { value: 'resourceTimelineWeek', label: 'Week Timeline' },
                  { value: 'dayGridMonth', label: 'Month' },
                  { value: 'listWeek', label: 'Agenda' },
                ]}
                size="sm"
              />
            ) : (
              <OVSSegmentedControl
                value={currentView}
                onChange={(v) => handleViewChange(v as SchedulerViewMode)}
                options={[
                  { value: 'timeGridDay', label: 'Day' },
                  { value: 'timeGridWeek', label: 'Week' },
                  { value: 'dayGridMonth', label: 'Month' },
                  { value: 'listWeek', label: 'Agenda' },
                ]}
                size="sm"
              />
            )}
          </div>
        </div>
      )}

      {/* ── 2. CALENDAR CANVAS CONTAINER ──────────────────────────────────── */}
      <div className="ovs-scheduler-container p-3 sm:p-4">
        <div ref={containerRef} />
      </div>
    </div>
  );
}

export const Scheduler = OVSScheduler;

/**
 * Convenient Preset for CRM Sales, Demos & Follow-up Meetings
 */
export function CRMScheduler(props: Omit<OVSSchedulerProps, 'resources'>) {
  return (
    <OVSScheduler
      {...props}
      initialView={props.initialView || 'timeGridWeek'}
    />
  );
}

/**
 * Convenient Preset for Clinic / HMS Multi-Chair & Doctor Resource Schedules
 */
export function ClinicScheduler(
  props: OVSSchedulerProps & { resources: SchedulerResource[] }
) {
  return (
    <OVSScheduler
      {...props}
      initialView={props.initialView || 'resourceTimelineDay'}
    />
  );
}
