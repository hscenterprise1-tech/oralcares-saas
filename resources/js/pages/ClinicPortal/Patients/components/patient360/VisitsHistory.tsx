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

const TONES: Record<Tone, string> = {
  teal: 'bg-cx-m-teal-bg text-cx-teal-ink',
  blue: 'bg-cx-m-blue-bg text-cx-blue-ink',
  amber: 'bg-cx-amber-bg text-cx-amber-ink',
  slate: 'bg-cx-slate-bg text-cx-slate-ink',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
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
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id="visits-heading" className="text-[15px] font-semibold leading-[22px] text-cx-text">
          Visits &amp; History
        </h2>
        <div className="flex flex-wrap gap-1.5">
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

      <ol className={cn('grid gap-2.5', loading && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>
        {data.data.map((row) => (
          <HistoryItem key={`${row.kind}-${row.id}`} row={row} />
        ))}
      </ol>

      {data.meta.last_page > 1 && (
        <nav aria-label="Pages of visit history" className="mt-3.5 grid grid-cols-2 items-center gap-3 md:grid-cols-[auto_1fr_auto]">
          <p className="col-span-2 text-center text-[13px] text-cx-text-2 md:col-span-1 md:col-start-2 md:row-start-1">
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
    <span className={cn('inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[12.5px] font-semibold', TONES[tone])}>
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {children}
    </span>
  );
}

function Status({ label, tone }: { label: string; tone: Tone }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2 text-[12px] font-semibold', TONES[tone])}>{label}</span>;
}

/** "24", "Oct", "2026": the day block that leads each row. */
function DayBlock({ date, booking }: { date: string | null; booking: boolean }) {
  const day = date ? new Date(`${date}T00:00:00`) : null;
  const valid = day && !Number.isNaN(day.getTime());

  return (
    <div
      className={cn(
        'flex h-[58px] w-[54px] shrink-0 flex-col items-center justify-center rounded-[12px] text-center leading-none',
        booking ? 'border border-dashed border-cx-line-strong bg-white text-cx-text-2' : 'bg-gradient-to-br from-[#E3F6F4] to-[#E6F0FD] text-cx-teal-ink',
      )}
    >
      {valid ? (
        <>
          <span className="text-[19px] font-bold tabular-nums">{day.getDate()}</span>
          <span className="mt-1 text-[11px] font-semibold uppercase tracking-[0.04em]">{day.toLocaleDateString('en-IN', { month: 'short' })}</span>
          <span className="mt-0.5 text-[10.5px] tabular-nums opacity-80">{day.getFullYear()}</span>
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
      <Card as="article" className={cn('flex gap-3.5 p-3.5 sm:p-4', booking && 'border-dashed bg-[#FCFDFE] shadow-none')}>
        <DayBlock date={row.date} booking={booking} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <p className="min-w-0 text-[14.5px] font-semibold leading-5 text-cx-text">
              {booking ? 'Booking' : 'Visit'}
              {row.time_label && <span className="font-normal text-cx-text-2"> · {row.time_label}</span>}
              {row.kind === 'visit' && row.registration_type && <span className="font-normal text-cx-text-2"> · {row.registration_type === 'new' ? 'New case' : 'Repeat'}</span>}
            </p>
            <Status label={label} tone={tone} />
          </div>

          {facts.length > 0 && (
            <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] leading-5 text-cx-text-2">
              {row.practitioner && (
                <span className="inline-flex items-center gap-1">
                  <Stethoscope className="h-3.5 w-3.5 text-cx-m-teal" aria-hidden="true" />
                  {row.practitioner}
                </span>
              )}
              {row.branch && (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-3.5 w-3.5 text-cx-m-purple" aria-hidden="true" />
                  {row.branch}
                </span>
              )}
            </p>
          )}

          {row.kind === 'visit' && (
            <div className="mt-1.5 space-y-0.5 text-[13px] leading-5 text-cx-text-2">
              {(row.case_number || row.visit_type) && <p>{[row.case_number ? `Case ${row.case_number}` : null, row.visit_type].filter(Boolean).join(' · ')}</p>}
              {row.reason && <p className="[overflow-wrap:anywhere]">Reason: {row.reason}</p>}
              {row.booked_for && <p>Came for a booking at {row.booked_for}</p>}
              {row.consultation && (
                <p className="inline-flex items-center gap-1.5 text-cx-text">
                  <ClipboardList className="h-3.5 w-3.5 text-cx-m-purple" aria-hidden="true" />
                  Consultation {row.consultation.number ?? ''} · {CONSULTATION_STATUS[row.consultation.status] ?? row.consultation.status}
                </p>
              )}
            </div>
          )}
          {row.kind === 'booking' && row.type && <p className="mt-1 text-[13px] text-cx-text-2">{row.type}</p>}

          {row.calendar_url && (
            <Link
              href={row.calendar_url}
              className="mt-2 inline-flex min-h-9 items-center gap-1 rounded-sm text-[13px] font-semibold text-cx-blue-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
            >
              Open this day in Appointments <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          )}
        </div>
      </Card>
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
