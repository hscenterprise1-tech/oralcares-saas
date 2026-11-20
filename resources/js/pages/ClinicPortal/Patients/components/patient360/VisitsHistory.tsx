import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CalendarCheck2, CalendarX2, ChevronLeft, ChevronRight, ClipboardList, MapPin, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Skeleton } from '@/clinic/ui/Spinner';
import { EmptyState, ErrorState, PermissionState } from '@/clinic/ui/states';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import type { HistoryRow, VisitsPage } from './types';

/*
 * Visits & History: every visit the patient made and every booking they
 * never came for, newest day first, from `Patient360Sections::visits`.
 * A booking is drawn differently from a visit (dashed, its own label), so
 * nobody reads a missed or cancelled booking as a visit. Details open only
 * where a real page exists: the day in Appointments; consultations are named
 * to clinical roles but not linked until their screen is built.
 */

type Tone = 'teal' | 'blue' | 'amber' | 'slate' | 'rose';

const TONES: Record<Tone, { badge: string; iconBg: string }> = {
  teal: {
    badge: 'bg-[#F0FDFA] text-[#0F766E] border border-[#99F6E4]',
    iconBg: 'bg-gradient-to-br from-[#14B8A6] to-[#0F766E] text-white shadow-sm shadow-teal-500/25',
  },
  blue: {
    badge: 'bg-[#EFF6FF] text-[#1E40AF] border border-[#BFDBFE]',
    iconBg: 'bg-gradient-to-br from-[#3B82F6] to-[#1D4ED8] text-white shadow-sm shadow-blue-500/25',
  },
  amber: {
    badge: 'bg-[#FEFCE8] text-[#B45309] border border-[#FDE68A]',
    iconBg: 'bg-gradient-to-br from-[#F59E0B] to-[#B45309] text-white shadow-sm shadow-amber-500/25',
  },
  slate: {
    badge: 'bg-slate-100 text-slate-700 border border-slate-200',
    iconBg: 'bg-gradient-to-br from-slate-600 to-slate-800 text-white shadow-sm',
  },
  rose: {
    badge: 'bg-[#FFF1F2] text-[#9F1239] border border-[#FECDD3]',
    iconBg: 'bg-gradient-to-br from-[#F472B6] to-[#BE185D] text-white shadow-sm shadow-pink-500/25',
  },
};

/** What each status is called, and its colour. A visit only registered or cancelled is not attendance. */
const VISIT_STATUS: Record<string, [string, Tone]> = {
  checked_in: ['Checked in', 'teal'],
  in_queue: ['Checked in', 'teal'],
  waiting: ['Checked in', 'teal'],
  with_doctor: ['With doctor', 'blue'],
  in_progress: ['With doctor', 'blue'],
  completed: ['Completed', 'teal'],
  registered: ['Registered, not checked in', 'slate'],
  cancelled: ['Visit cancelled', 'slate'],
};

const BOOKING_STATUS: Record<string, [string, Tone]> = {
  missed: ['Missed', 'amber'],
  not_attended: ['Not attended', 'amber'],
  cancelled: ['Cancelled', 'slate'],
  no_visit_recorded: ['Marked done, no visit recorded', 'slate'],
};

const CONSULTATION_STATUS: Record<string, string> = { open: 'in progress', signed: 'signed', voided: 'voided' };

export function VisitsHistory({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<VisitsPage | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const [loading, setLoading] = useState(true);
  const controller = useRef<AbortController | null>(null);

  const load = useCallback(
    (target: number) => {
      controller.current?.abort();
      const abort = new AbortController();
      controller.current = abort;
      setLoading(true);
      setError(null);
      requestJson<VisitsPage>('GET', endpoint, { query: { page: target }, signal: abort.signal })
        .then((result) => {
          setData(result);
          setPage(target);
        })
        .catch((failure) => {
          if (errorKind(failure) !== 'aborted') {
            setError(errorKind(failure));
          }
        })
        .finally(() => {
          if (controller.current === abort) {
            setLoading(false);
          }
        });
    },
    [endpoint],
  );

  useEffect(() => {
    load(1);

    return () => controller.current?.abort();
  }, [load]);

  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title="Visit history could not be loaded" description={ERROR_COPY[error]} onRetry={() => load(page)} />;
  }
  if (!data) {
    return <HistorySkeleton />;
  }
  if (data.meta.total === 0) {
    return <EmptyState title="No visits yet" description="Visits appear here once the patient is checked in, and bookings they did not come for are listed too." />;
  }

  return (
    <section aria-labelledby="visits-heading" aria-busy={loading || undefined}>
      <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
        <h2 id="visits-heading" className="text-[16px] font-bold leading-[22px] text-slate-900">
          Visits &amp; History
        </h2>
        <div className="flex flex-wrap gap-2">
          <Count tone="teal" icon={CalendarCheck2}>
            {data.counts.visits} {data.counts.visits === 1 ? 'visit' : 'visits'}
          </Count>
          {data.counts.not_attended > 0 && (
            <Count tone="amber" icon={CalendarX2}>
              {data.counts.not_attended} {data.counts.not_attended === 1 ? 'booking' : 'bookings'} not attended
            </Count>
          )}
        </div>
      </div>

      <ol className={cn('grid gap-3', loading && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>
        {data.data.map((row) => (
          <HistoryItem key={`${row.kind}-${row.id}`} row={row} />
        ))}
      </ol>

      {data.meta.last_page > 1 && (
        <nav aria-label="Pages of visit history" className="mt-4 grid grid-cols-2 items-center gap-3 md:grid-cols-[auto_1fr_auto]">
          <p className="col-span-2 text-center text-[13px] font-medium text-slate-500 md:col-span-1 md:col-start-2 md:row-start-1">
            Page {data.meta.page} of {data.meta.last_page}
          </p>
          <Button icon={ChevronLeft} disabled={page <= 1 || loading} onClick={() => load(page - 1)} className="md:col-start-1 md:row-start-1">
            Newer
          </Button>
          <Button disabled={page >= data.meta.last_page || loading} onClick={() => load(page + 1)} className="md:col-start-3 md:row-start-1">
            <span className="inline-flex items-center gap-1.5">
              Older <ChevronRight className="h-4 w-4" aria-hidden="true" />
            </span>
          </Button>
        </nav>
      )}
    </section>
  );
}

function Count({ tone, icon: Icon, children }: { tone: Tone; icon: typeof CalendarCheck2; children: ReactNode }) {
  return (
    <span className={cn('inline-flex h-7 items-center gap-1.5 rounded-full px-3 text-[12px] font-bold border', TONES[tone].badge)}>
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {children}
    </span>
  );
}

function Status({ label, tone }: { label: string; tone: Tone }) {
  return (
    <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[11.5px] font-bold border', TONES[tone].badge)}>
      {label}
    </span>
  );
}

/** "24", "Oct", "2026": the day block that leads each row. */
function DayBlock({ date, booking }: { date: string | null; booking: boolean }) {
  const day = date ? new Date(`${date}T00:00:00`) : null;
  const valid = day && !Number.isNaN(day.getTime());

  return (
    <div
      className={cn(
        'flex h-[60px] w-[56px] shrink-0 flex-col items-center justify-center rounded-2xl text-center leading-none transition-transform',
        booking
          ? 'border-2 border-dashed border-slate-200 bg-slate-50 text-slate-500'
          : 'bg-gradient-to-br from-[#EFF6FF] to-[#DBEAFE] border border-blue-200/80 text-blue-700 shadow-xs',
      )}
    >
      {valid ? (
        <>
          <span className={cn('text-[20px] font-black tabular-nums', booking ? 'text-slate-700' : 'text-blue-800')}>{day.getDate()}</span>
          <span className={cn('mt-0.5 text-[10.5px] font-extrabold uppercase tracking-wider', booking ? 'text-slate-500' : 'text-blue-600')}>{day.toLocaleDateString('en-IN', { month: 'short' })}</span>
          <span className={cn('text-[9.5px] tabular-nums font-bold opacity-80', booking ? 'text-slate-400' : 'text-blue-500')}>{day.getFullYear()}</span>
        </>
      ) : (
        <span className="text-[12px]">—</span>
      )}
    </div>
  );
}

function HistoryItem({ row }: { row: HistoryRow }) {
  const booking = row.kind === 'booking';
  const [label, tone] = (booking ? BOOKING_STATUS[row.status] : VISIT_STATUS[row.status]) ?? [row.status, 'slate'];
  const facts = [row.practitioner, row.branch].filter(Boolean) as string[];

  return (
    <li>
      <article className={cn('flex gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_2px_12px_rgba(15,23,42,0.04)] hover:shadow-[0_4px_18px_rgba(15,23,42,0.06)] transition-all duration-200', booking && 'border-dashed bg-slate-50/50 shadow-none')}>
        <DayBlock date={row.date} booking={booking} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <p className="min-w-0 text-[15px] font-bold leading-5 text-slate-900">
              {booking ? 'Booking' : 'Visit'}
              {row.time_label && <span className="font-normal text-slate-500"> · {row.time_label}</span>}
              {row.kind === 'visit' && row.registration_type && (
                <span className="ml-1.5 inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700 border border-blue-200/60">
                  {row.registration_type === 'new' ? 'New case' : 'Repeat'}
                </span>
              )}
            </p>
            <Status label={label} tone={tone} />
          </div>

          {facts.length > 0 && (
            <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] leading-5 text-slate-600">
              {row.practitioner && (
                <span className="inline-flex items-center gap-1 font-medium">
                  <Stethoscope className="h-3.5 w-3.5 text-teal-600" aria-hidden="true" />
                  Dr. {row.practitioner}
                </span>
              )}
              {row.branch && (
                <span className="inline-flex items-center gap-1 text-slate-500">
                  <MapPin className="h-3.5 w-3.5 text-purple-500" aria-hidden="true" />
                  {row.branch}
                </span>
              )}
            </p>
          )}

          {row.kind === 'visit' && (
            <div className="mt-2 space-y-1 text-[13px] leading-5 text-slate-600">
              {(row.case_number || row.visit_type) && <p className="font-medium text-slate-700">{[row.case_number ? `Case #${row.case_number}` : null, row.visit_type].filter(Boolean).join(' · ')}</p>}
              {row.reason && <p className="[overflow-wrap:anywhere] text-slate-600"><span className="font-semibold text-slate-700">Reason:</span> {row.reason}</p>}
              {row.booked_for && <p className="text-slate-500">Came for a booking at {row.booked_for}</p>}
              {row.consultation && (
                <p className="inline-flex items-center gap-1.5 font-bold text-purple-700 bg-purple-50 border border-purple-200/80 rounded-lg px-2.5 py-1">
                  <ClipboardList className="h-4 w-4 text-purple-600" aria-hidden="true" />
                  Consultation {row.consultation.number ?? ''} · {CONSULTATION_STATUS[row.consultation.status] ?? row.consultation.status}
                </p>
              )}
            </div>
          )}
          {row.kind === 'booking' && row.type && <p className="mt-1 text-[13px] text-slate-600">{row.type}</p>}

          {row.calendar_url && (
            <Link
              href={row.calendar_url}
              className="mt-2.5 inline-flex min-h-8 items-center gap-1 rounded-lg text-[13px] font-bold text-blue-600 hover:text-blue-800 hover:underline transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            >
              Open this day in Appointments <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          )}
        </div>
      </article>
    </li>
  );
}

function HistorySkeleton() {
  return (
    <div role="status" aria-label="Loading visit history" className="grid gap-2.5">
      {[0, 1, 2, 3].map((row) => (
        <Card key={row} as="div" className="flex gap-3.5 p-4">
          <Skeleton className="h-[58px] w-[54px] rounded-[12px]" />
          <div className="flex-1 space-y-2 pt-1">
            <Skeleton className="h-4 w-1/3" />
            <Skeleton className="h-3.5 w-1/2" />
          </div>
        </Card>
      ))}
    </div>
  );
}
