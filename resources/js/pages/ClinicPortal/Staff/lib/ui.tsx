import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { DocumentState } from './types';

/** Staff status → label and colour. */
export const STATUS: Record<string, { label: string; chip: string; dot: string }> = {
  active: { label: 'Active', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500' },
  verified: { label: 'Active', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500' },
  invited: { label: 'Invited', chip: 'bg-sky-50 text-sky-700 ring-sky-200', dot: 'bg-sky-500' },
  pending_invitation: { label: 'Pending invitation', chip: 'bg-sky-50 text-sky-700 ring-sky-200', dot: 'bg-sky-400' },
  inactive: { label: 'Inactive', chip: 'bg-slate-100 text-slate-700 ring-slate-200', dot: 'bg-slate-400' },
  suspended: { label: 'Suspended', chip: 'bg-amber-50 text-amber-800 ring-amber-200', dot: 'bg-amber-500' },
  resigned: { label: 'Archived', chip: 'bg-slate-100 text-slate-600 ring-slate-200', dot: 'bg-slate-300' },
};

export function statusOf(status: string | null) {
  return STATUS[status ?? 'active'] ?? STATUS.active;
}

/** Document state → label and colour. */
export const DOC_STATE: Record<DocumentState, { label: string; chip: string }> = {
  missing: { label: 'Not uploaded', chip: 'bg-slate-100 text-slate-700 ring-slate-200' },
  pending_verification: { label: 'Pending verification', chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  verified: { label: 'Verified', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  rejected: { label: 'Rejected', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  expired: { label: 'Expired', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  expiring_soon: { label: 'Expires soon', chip: 'bg-amber-50 text-amber-800 ring-amber-200' },
};

export function Chip({ className, children }: { className: string; children: ReactNode }) {
  return <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11.5px] font-bold ring-1', className)}>{children}</span>;
}

const TONES = [
  'bg-sky-100 text-sky-800',
  'bg-violet-100 text-violet-800',
  'bg-emerald-100 text-emerald-800',
  'bg-amber-100 text-amber-900',
  'bg-rose-100 text-rose-800',
  'bg-teal-100 text-teal-800',
  'bg-indigo-100 text-indigo-800',
  'bg-orange-100 text-orange-900',
];

export function initialsOf(name: string): string {
  const words = name.replace(/^(dr|mr|mrs|ms|prof)\.?\s+/i, '').trim().split(/\s+/).filter(Boolean);

  return ((words[0]?.[0] ?? '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
}

/** A staff photo, or their initials in a stable colour. */
export function Avatar({ name, url, id, size = 40 }: { name: string; url: string | null; id: number; size?: number }) {
  return url ? (
    <img src={url} alt="" width={size} height={size} className="shrink-0 rounded-full object-cover ring-2 ring-white" style={{ width: size, height: size }} />
  ) : (
    <span
      aria-hidden="true"
      className={cn('flex shrink-0 items-center justify-center rounded-full font-bold ring-2 ring-white', TONES[id % TONES.length])}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.36) }}
    >
      {initialsOf(name)}
    </span>
  );
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "15 Mar 2027" from a YYYY-MM-DD (no timezone shift). */
export function formatDate(value: string | null): string {
  if (!value) {
    return '';
  }
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);

  return `${day} ${MONTHS[month - 1]} ${year}`;
}

/** "3 days ago" / "2 Oct 2026" for an ISO timestamp. */
export function relativeTime(value: string | null): string {
  if (!value) {
    return '';
  }
  const then = new Date(value).getTime();
  const minutes = Math.round((Date.now() - then) / 60000);
  if (minutes < 1) {
    return 'just now';
  }
  if (minutes < 60) {
    return `${minutes} min ago`;
  }
  if (minutes < 60 * 24) {
    return `${Math.round(minutes / 60)} h ago`;
  }
  if (minutes < 60 * 24 * 14) {
    return `${Math.round(minutes / (60 * 24))} days ago`;
  }

  return formatDate(value.slice(0, 10));
}

export function Section({ title, aside, children, className }: { title: string; aside?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section aria-label={title} className={cn('rounded-3xl border border-white/70 bg-white/[0.93] p-4 backdrop-blur-sm sm:p-5', className)}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-[15px] font-bold tracking-tight text-clinic-ink">{title}</h2>
        {aside}
      </div>
      {children}
    </section>
  );
}

export function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11.5px] font-semibold text-clinic-secondary">{label}</dt>
      <dd className="mt-0.5 break-words text-[13px] font-medium text-clinic-ink">{value || <span className="text-clinic-muted">—</span>}</dd>
    </div>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="rounded-2xl bg-clinic-canvas px-3.5 py-3 text-[12.5px] text-clinic-secondary">{children}</p>;
}
