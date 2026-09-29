import React from 'react';
import { usePage } from '@inertiajs/react';
import {
  CalendarClock,
  Clock,
  IndianRupee,
  Stethoscope,
  UserCheck,
  UserPlus,
  Users,
} from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmDataState,
  CrmPageHead,
  CrmSection,
  CrmStatusPill,
  CrmValue,
  inr,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicShell } from './components/ClinicShell';
import { CLINIC_HUE } from './components/nav';

/*
 * The clinic's own operational dashboard — today only, nothing historical.
 * A receptionist opening this at 9am needs "what's on today and who's
 * waiting", not a trend line; that view belongs on a future Reports surface.
 *
 * Built directly against `ClinicDashboardController::index()`'s payload,
 * computed synchronously (no deferred props on this controller yet), so
 * there is no client-side loading state to design for here — the page
 * either renders with today's numbers or the request never reaches it
 * (`RequireClinicContext` rejects a session with no resolved clinic before
 * this component exists). Each list section still gets its own empty state,
 * since "no appointments today" and "the list hasn't loaded" must not look
 * the same.
 */

interface DashboardMetrics {
  totalAppointments: number;
  scheduledAppointments: number;
  checkedInAppointments: number;
  completedAppointments: number;
  waitingCount: number;
  inConsultationCount: number;
  patientsRegisteredToday: number;
  todaysVisitsCount: number;
  collectionsToday: number;
}

interface UpcomingAppointment {
  id: number;
  patient_name: string;
  practitioner_name: string;
  scheduled_at: string;
  status: string;
  type: string;
}

interface QueueItem {
  id: number;
  case_number: string;
  position: number;
  priority: string;
  status: string;
  patient_name: string;
  doctor_name: string | null;
  called_at: string | null;
}

interface Practitioner {
  id: number;
  name: string;
  specialty: string;
  qualifications: string | null;
  is_active: boolean;
}

interface MyDay {
  today_appointments: number;
  patients_in_queue: number;
  total_assigned: number;
}

interface DashboardProps {
  metrics: DashboardMetrics;
  upcomingAppointments: UpcomingAppointment[];
  queue: QueueItem[];
  practitioners: Practitioner[];
  myDay: MyDay | null;
}

interface AuthPageProps {
  auth?: { user?: { name?: string } };
  [key: string]: unknown;
}

/**
 * Computed from the browser's own clock, not the server's — this is a
 * greeting, not data, so there's nothing to gain from a round trip and a
 * clinic's local time is whatever its staff's laptops already show.
 */
function timeAwareGreeting(name: string): string {
  const hour = new Date().getHours();

  if (hour >= 5 && hour < 12) return `Good morning, ${name}`;
  if (hour >= 12 && hour < 17) return `Good afternoon, ${name}`;
  if (hour >= 17 && hour < 21) return `Good evening, ${name}`;

  return `Welcome back, ${name}`;
}

const APPOINTMENT_STATUS_TONE: Record<string, CrmStatusTone> = {
  scheduled: 'blue',
  checked_in: 'warn',
  completed: 'good',
  cancelled: 'neutral',
  no_show: 'bad',
};

const QUEUE_STATUS_TONE: Record<string, CrmStatusTone> = {
  waiting: 'warn',
  in_consultation: 'blue',
  called: 'blue',
};

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  hue,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: React.ReactNode;
  hint?: string;
  hue: string;
}) {
  return (
    <CrmCard className="flex min-h-[124px] flex-col sm:min-h-[168px]" frostHue={hue}>
      <div className="mb-2 sm:mb-4">
        <CrmCardIcon icon={Icon as never} hue={hue} />
      </div>
      <p className="text-[12px] leading-snug text-ovs-crm-text-secondary sm:text-[13px]">{label}</p>
      <div className="mt-auto pt-2 sm:pt-3">
        <CrmValue value={value} size="major" />
        {hint && <p className="mt-1 text-[11px] leading-snug text-ovs-crm-text-muted sm:text-[11.5px]">{hint}</p>}
      </div>
    </CrmCard>
  );
}

export default function ClinicDashboard({ metrics, upcomingAppointments, queue, practitioners, myDay }: DashboardProps) {
  const hasPermission = useClinicPermission();
  const canViewBilling = hasPermission('billing.view');

  const { props } = usePage<AuthPageProps>();
  const userName = props.auth?.user?.name;
  const greeting = userName ? timeAwareGreeting(userName) : 'Today';

  return (
    <ClinicShell title="Dashboard">
      <CrmPageHead eyebrow="Clinic" title={greeting} description="Here's what's happening at your branch today." />

      <div className="space-y-4 pb-16">
        {myDay && (
          <CrmSection title="My day" description="Patients currently assigned to you." icon={UserCheck} hue={CLINIC_HUE.dashboard}>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 sm:gap-4">
              <StatCard
                icon={CalendarClock}
                hue={CLINIC_HUE.appointments}
                label="My appointments today"
                value={myDay.today_appointments > 0 ? myDay.today_appointments : '—'}
                hint={myDay.today_appointments === 0 ? 'Nothing booked with you today' : undefined}
              />
              <StatCard
                icon={Clock}
                hue={CLINIC_HUE.staff}
                label="My patients in queue"
                value={myDay.patients_in_queue > 0 ? myDay.patients_in_queue : '—'}
                hint={myDay.patients_in_queue === 0 ? 'None of your patients are waiting' : undefined}
              />
              <StatCard
                icon={Users}
                hue={CLINIC_HUE.patients}
                label="Total patients assigned"
                value={myDay.total_assigned > 0 ? myDay.total_assigned : '—'}
                hint="Across all statuses"
              />
            </div>
          </CrmSection>
        )}

        <div className={canViewBilling ? 'grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4' : 'grid grid-cols-1 gap-2 sm:grid-cols-3 sm:gap-4'}>
          <StatCard
            icon={CalendarClock}
            hue={CLINIC_HUE.appointments}
            label="Appointments today"
            value={metrics.totalAppointments > 0 ? metrics.totalAppointments : '—'}
            hint={
              metrics.totalAppointments === 0
                ? 'Nothing booked for today'
                : `${metrics.checkedInAppointments} checked in · ${metrics.completedAppointments} completed`
            }
          />

          <StatCard
            icon={Clock}
            hue={CLINIC_HUE.staff}
            label="In the queue"
            value={metrics.waitingCount + metrics.inConsultationCount > 0 ? metrics.waitingCount + metrics.inConsultationCount : '—'}
            hint={
              metrics.waitingCount + metrics.inConsultationCount === 0
                ? 'Nobody waiting right now'
                : `${metrics.waitingCount} waiting · ${metrics.inConsultationCount} in consultation`
            }
          />

          <StatCard
            icon={UserPlus}
            hue={CLINIC_HUE.patients}
            label="New patients today"
            value={metrics.patientsRegisteredToday > 0 ? metrics.patientsRegisteredToday : '—'}
            hint={`${metrics.todaysVisitsCount} visit${metrics.todaysVisitsCount === 1 ? '' : 's'} recorded today`}
          />

          {canViewBilling && (
            <StatCard
              icon={IndianRupee}
              hue={CLINIC_HUE.billing}
              label="Collected today"
              value={metrics.collectionsToday > 0 ? inr(metrics.collectionsToday, { compact: true }) : '—'}
              hint={metrics.collectionsToday === 0 ? 'No payments recorded yet' : 'Across all bills raised today'}
            />
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <CrmSection
            title="Upcoming appointments"
            description="Today's schedule, in order."
            icon={CalendarClock}
            hue={CLINIC_HUE.appointments}
            href="/clinic/appointments"
            openLabel="Open appointments"
            className="lg:col-span-7"
          >
            {upcomingAppointments.length === 0 ? (
              <CrmDataState
                kind="empty"
                compact
                icon={CalendarClock}
                title="Nothing booked for today"
                description="Appointments scheduled for today will appear here as they're booked."
              />
            ) : (
              <div className="-mx-2 space-y-0.5">
                {upcomingAppointments.map((appt) => (
                  <div
                    key={appt.id}
                    className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-[12px] px-2 py-2.5 transition hover:bg-ovs-crm-surface-inset"
                  >
                    <span className="w-16 shrink-0 text-[12.5px] font-medium tabular-nums text-ovs-crm-text-secondary">
                      {appt.scheduled_at}
                    </span>
                    <span className="min-w-[160px] flex-1">
                      <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                        {appt.patient_name}
                      </span>
                      <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                        {appt.practitioner_name} · {appt.type}
                      </span>
                    </span>
                    <CrmStatusPill tone={APPOINTMENT_STATUS_TONE[appt.status] ?? 'neutral'}>
                      {appt.status.replace(/_/g, ' ')}
                    </CrmStatusPill>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>

          <CrmSection
            title="Today's queue"
            description="Waiting and in consultation, by position."
            icon={Clock}
            hue={CLINIC_HUE.staff}
            href="/clinic/reception"
            openLabel="Open reception"
            className="lg:col-span-5"
          >
            {queue.length === 0 ? (
              <CrmDataState
                kind="empty"
                compact
                icon={Clock}
                title="Queue is empty"
                description="Checked-in and walk-in patients appear here."
              />
            ) : (
              <div className="-mx-2 space-y-0.5">
                {queue.map((item) => (
                  <div
                    key={item.id}
                    className="flex items-center gap-3 rounded-[12px] px-2 py-2.5 transition hover:bg-ovs-crm-surface-inset"
                  >
                    <span className="w-7 shrink-0 text-center text-[12.5px] font-medium tabular-nums text-ovs-crm-text-muted">
                      {item.position}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                        {item.patient_name}
                      </span>
                      {item.doctor_name && (
                        <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">{item.doctor_name}</span>
                      )}
                    </span>
                    <CrmStatusPill tone={QUEUE_STATUS_TONE[item.status] ?? 'neutral'}>
                      {item.status.replace(/_/g, ' ')}
                    </CrmStatusPill>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>
        </div>

        <CrmSection
          title="Active practitioners"
          description="On duty at this branch."
          icon={Stethoscope}
          hue={CLINIC_HUE.patients}
        >
          {practitioners.length === 0 ? (
            <CrmDataState
              kind="empty"
              compact
              icon={Users}
              title="No active practitioners"
              description="Doctors and practitioners added to this clinic appear here."
            />
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {practitioners.map((p) => (
                <div key={p.id} className="rounded-[16px] border border-ovs-crm-line/70 bg-white px-3.5 py-3">
                  <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">{p.name}</p>
                  <p className="mt-0.5 truncate text-[11.5px] text-ovs-crm-text-muted">
                    {p.specialty}
                    {p.qualifications ? ` · ${p.qualifications}` : ''}
                  </p>
                </div>
              ))}
            </div>
          )}
        </CrmSection>
      </div>
    </ClinicShell>
  );
}
