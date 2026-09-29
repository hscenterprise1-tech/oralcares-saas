export type EventState =
  | 'scheduled'
  | 'confirmed'
  | 'in_progress'
  | 'completed'
  | 'cancelled'
  | 'no_show'
  | 'blocked';

export type ResourceType = 'chair' | 'doctor' | 'room' | 'presenter' | 'equipment';

export type ResourceAvailability = 'available' | 'busy' | 'break' | 'maintenance' | 'offline';

export interface SchedulerResource {
  id: string;
  title: string;
  subtitle?: string;
  type?: ResourceType;
  capacity?: number;
  status?: ResourceAvailability;
  avatarUrl?: string;
  eventCount?: number;
  branchName?: string;
  [key: string]: any;
}

export interface SchedulerEvent {
  id: string;
  title: string;
  start: string | Date;
  end?: string | Date;
  resourceId?: string;
  state?: EventState;
  patientName?: string;
  doctorName?: string;
  procedureName?: string;
  chairName?: string;
  branchName?: string;
  amountFormatted?: string;
  color?: string;
  backgroundColor?: string;
  borderColor?: string;
  textColor?: string;
  notes?: string;
  metadata?: Record<string, any>;
  [key: string]: any;
}

export type SchedulerViewMode =
  | 'timeGridDay'
  | 'timeGridWeek'
  | 'dayGridMonth'
  | 'listWeek'
  | 'resourceTimelineDay'
  | 'resourceTimelineWeek';

export interface SlotSelectInfo {
  start: Date;
  end: Date;
  resourceId?: string;
  allDay: boolean;
}

export interface EventClickInfo {
  id: string;
  event: SchedulerEvent;
  rawEvent?: any;
}

export interface EventRescheduleInfo {
  event: SchedulerEvent;
  oldEvent: any;
  delta: any;
}
