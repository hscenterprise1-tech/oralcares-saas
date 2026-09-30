import { CalendarDays, ClipboardList, Clock, UserPlus, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCan, useClinic, useClinicShared } from '@/clinic/context';
import { PageHeader, Panel } from '@/clinic/page/PageHeader';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { BranchSwitcher, branchLabel } from '@/clinic/shell/BranchSwitcher';
import type { ClinicPermission } from '@/clinic/types';
import { StatePanel } from '@/clinic/ui/StatePanel';

interface Metrics {
  totalAppointments: number;
  scheduledAppointments: number;
  checkedInAppointments: number;
  completedAppointments: number;
  waitingCount: number;
  inConsultationCount: number;
  patientsRegisteredToday: number;
  todaysVisitsCount: number;
}

interface UpcomingAppointment {
  id: number;
  patient_name: string;
  practitioner_name: string;
  scheduled_at: string;
  status: string | null;
  type: string;
}

interface QueueEntry {
  id: number;
  case_number: string;
  status: string;
  patient_name: string;
  doctor_name: string | null;
}

interface HomeProps {
  metrics: Metrics;
  upcomingAppointments: UpcomingAppointment[];
  queue: QueueEntry[];
  myDay: { today_appointments: number; patients_in_queue: number; total_assigned: number } | null;
}

function greeting(hour: number): string {
  if (hour < 12) {
    return 'Good morning';
  }

  return hour < 17 ? 'Good afternoon' : 'Good evening';
}

function humanize(value: string | null): string {
  return value ? value.replace(/[_-]+/g, ' ').replace(/^\w/, (first) => first.toUpperCase()) : '—';
}

/**
 * The portal's landing page: today at this branch, in figures this user is
 * allowed to see. Each figure is shown only with the permission for the
 * module it comes from (presentation only; the modules check for themselves).
 */
export default function Home({ metrics, upcomingAppointments, queue, myDay }: HomeProps) {
  const clinic = useClinic();
  const { auth, portalLinks } = useClinicShared();
  const can = useCan();
  const firstName = auth.user?.name.split(' ')[0] ?? '';
  const today = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(
    new Date(),
  );

  const allStats: { label: string; value: number; detail: string; icon: LucideIcon; permission: ClinicPermission }[] = [
    {
      label: 'Appointments today',
      value: metrics.totalAppointments,
      detail: `${metrics.checkedInAppointments} checked in · ${metrics.completedAppointments} completed`,
      icon: CalendarDays,
      permission: 'appointment.view',
    },
    {
      label: 'In the queue',
      value: metrics.waitingCount,
      detail: `${metrics.inConsultationCount} in consultation`,
      icon: Clock,
      permission: 'queue.manage',
    },
    {
      label: 'Visits today',
      value: metrics.todaysVisitsCount,
      detail: clinic?.active_branch ? 'Recorded at this branch' : 'Recorded across your branches',
      icon: ClipboardList,
      permission: 'visit.view',
    },
    {
      label: 'New patients today',
      value: metrics.patientsRegisteredToday,
      detail: 'Registered across the clinic',
      icon: UserPlus,
      permission: 'patient.view',
    },
  ];
  const stats = allStats.filter((stat) => can(stat.permission));

  /*
   * A member placed at several branches, with none chosen yet. Their roles are
   * held per branch, so until they choose one nothing is open to them; saying
   * "your role does not include this" would be untrue.
   */
  const mustChooseBranch =
    clinic !== null &&
    portalLinks !== null &&
    clinic.active_branch === null &&
    clinic.accessible_branch_count > 1 &&
    !clinic.is_owner &&
    !clinic.has_all_branches_access;

  const title = firstName ? `${greeting(new Date().getHours())}, ${firstName}` : greeting(new Date().getHours());

  if (mustChooseBranch && clinic && portalLinks) {
    return (
      <ClinicPortalShell title="Dashboard">
        <PageHeader eyebrow={today} title={title} />
        <Panel className="mx-auto max-w-lg">
          <StatePanel
            kind="empty"
            title="Choose the branch you are working at"
            description={`You work at ${clinic.accessible_branch_count} branches. Your access depends on the branch, so choose one to see its day.`}
            action={
              <div className="w-72 max-w-full">
                <BranchSwitcher clinic={clinic} links={portalLinks} variant="block" />
              </div>
            }
          />
        </Panel>
      </ClinicPortalShell>
    );
  }

  return (
    <ClinicPortalShell title="Dashboard">
      <PageHeader
        eyebrow={today}
        title={title}
        description={
          clinic ? `Here is today at ${clinic.active_branch ? branchLabel(clinic) : 'all your branches'}.` : undefined
        }
      />

      {myDay && (
        <Panel className="mb-5 border-clinic-brand/30 bg-clinic-brand-soft/50" title="Your day">
          <dl className="grid grid-cols-3 gap-3 px-5 pb-5 pt-2 sm:px-6">
            {[
              ['Your appointments', myDay.today_appointments],
              ['Your patients waiting', myDay.patients_in_queue],
              ['Assigned to you', myDay.total_assigned],
            ].map(([label, value]) => (
              <div key={label}>
                <dt className="text-[11.5px] font-semibold text-clinic-secondary">{label}</dt>
                <dd className="mt-0.5 text-[28px] font-bold leading-none tracking-tight text-clinic-ink">{value}</dd>
              </div>
            ))}
          </dl>
        </Panel>
      )}

      {stats.length > 0 ? (
        <dl className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {stats.map(({ label, value, detail, icon: Icon }) => (
            <div key={label} className="rounded-3xl border border-clinic-border bg-clinic-surface p-5">
              <div className="flex items-center justify-between gap-2">
                <dt className="text-[12.5px] font-semibold text-clinic-secondary">{label}</dt>
                <Icon className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
              </div>
              <dd className="mt-3">
                <span className="block text-[34px] font-bold leading-none tracking-tight text-clinic-ink">{value}</span>
                <span className="mt-2 block text-[12px] text-clinic-secondary">{detail}</span>
              </dd>
            </div>
          ))}
        </dl>
      ) : (
        !myDay && (
          <Panel className="mb-5">
            <StatePanel
              kind="empty"
              title="Welcome to your clinic"
              description="Your role does not include today's figures. Use the navigation above to open the areas you work in."
            />
          </Panel>
        )
      )}

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
        {can('appointment.view') && (
          <Panel title="Next appointments">
            {upcomingAppointments.length === 0 ? (
              <StatePanel
                compact
                kind="empty"
                title="No appointments today"
                description="Booked appointments for today will be listed here."
              />
            ) : (
              <ul className="divide-y divide-clinic-border px-2 pb-2">
                {upcomingAppointments.map((appointment) => (
                  <li key={appointment.id} className="flex items-center gap-4 px-3 py-3">
                    <span className="w-16 shrink-0 text-[13px] font-bold tabular-nums text-clinic-ink">
                      {appointment.scheduled_at}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] font-semibold text-clinic-ink">
                        {appointment.patient_name}
                      </span>
                      <span className="block truncate text-[12px] text-clinic-secondary">
                        {appointment.type} · {appointment.practitioner_name}
                      </span>
                    </span>
                    <span className="shrink-0 rounded-full bg-clinic-inset px-2.5 py-1 text-[11.5px] font-semibold text-clinic-ink">
                      {humanize(appointment.status)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        )}

        {can('queue.manage') && (
          <Panel title="Queue now">
            {queue.length === 0 ? (
              <StatePanel
                compact
                kind="empty"
                title="No one is waiting"
                description="Checked-in patients appear here in queue order."
              />
            ) : (
              <ul className="divide-y divide-clinic-border px-2 pb-2">
                {queue.map((entry) => (
                  <li key={entry.id} className="flex items-center gap-4 px-3 py-3">
                    <span className="w-16 shrink-0 text-[12px] font-bold tabular-nums text-clinic-secondary">
                      {entry.case_number}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] font-semibold text-clinic-ink">
                        {entry.patient_name}
                      </span>
                      <span className="block truncate text-[12px] text-clinic-secondary">
                        {entry.doctor_name ?? 'No practitioner yet'}
                      </span>
                    </span>
                    <span className="flex shrink-0 items-center gap-1.5 text-[11.5px] font-semibold text-clinic-ink">
                      <Users className="h-3.5 w-3.5 text-clinic-secondary" aria-hidden="true" />
                      {humanize(entry.status)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        )}
      </div>
    </ClinicPortalShell>
  );
}
