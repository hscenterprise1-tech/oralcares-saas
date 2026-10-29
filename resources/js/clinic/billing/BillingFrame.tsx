import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Button } from '../ui/Button';
import { cxTone } from '../ui/tones';
import { formatMoney, formatNumber } from '../ui/visuals';
import type { Tone } from '../types';

/** A Billing section the server says this member may open. */
export interface BillingTab {
  key: string;
  label: string;
  href: string;
}

/** Laravel's page-based paginator, as it arrives. */
export interface PagedList<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/**
 * The Billing area's sections, as tabs inside the one Billing destination.
 * Only the sections the server offers are shown; the current one is marked.
 */
export function BillingTabs({ tabs, current }: { tabs: BillingTab[]; current: string }) {
  if (tabs.length <= 1) {
    return null;
  }

  return (
    <nav aria-label="Billing sections" className="-mx-1 mb-5 overflow-x-auto px-1">
      <ul className="flex min-w-max gap-1 border-b border-cx-line">
        {tabs.map((tab) => (
          <li key={tab.key}>
            <Link
              href={tab.href}
              aria-current={tab.key === current ? 'page' : undefined}
              className={cn(
                '-mb-px inline-flex min-h-11 items-center whitespace-nowrap border-b-2 px-3.5 text-[13.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal',
                tab.key === current ? 'border-cx-nav-teal text-cx-nav-teal-strong' : 'border-transparent text-cx-nav-muted hover:border-cx-nav-line hover:text-cx-nav-ink',
              )}
            >
              {tab.label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** One headline figure: a money amount or a count, its label, and what it counts. */
export function Figure({ label, value, money = true, tone = 'slate', currency = 'INR', note }: {
  label: string;
  value: number;
  money?: boolean;
  tone?: Tone;
  currency?: string;
  note?: string;
}) {
  const colours = cxTone(tone);

  return (
    <div className={cn('rounded-2xl px-4 py-3', colours.bg)}>
      <p className="text-[19px] font-semibold leading-tight text-cx-text tabular-nums">{money ? formatMoney(value, currency) : formatNumber(value)}</p>
      <p className={cn('mt-0.5 text-[12px] font-semibold', colours.ink)}>{label}</p>
      {note && <p className="mt-1 text-[11.5px] leading-snug text-cx-text-3">{note}</p>}
    </div>
  );
}

/** A filter bar: the controls in a wrapping row, the apply and clear buttons at the end. */
export function FilterBar({ children, onApply, onClear, dirty }: { children: ReactNode; onApply: () => void; onClear: () => void; dirty: boolean }) {
  return (
    <form
      role="search"
      className="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-cx-line bg-cx-surface p-4"
      onSubmit={(event) => {
        event.preventDefault();
        onApply();
      }}
    >
      {children}
      <div className="flex gap-2">
        <Button type="submit" tone="primary" size="sm">
          Apply
        </Button>
        {dirty && (
          <Button type="button" size="sm" tone="ghost" onClick={onClear}>
            Clear
          </Button>
        )}
      </div>
    </form>
  );
}

/** Newer / older pages of a server-paged list, keeping its filters. */
export function Pager({ page, query }: { page: PagedList<unknown>; query: Record<string, string | number | null | undefined> }) {
  if (page.last_page <= 1) {
    return null;
  }
  const go = (to: number) => router.get(window.location.pathname, clean({ ...query, page: to }), { preserveScroll: true, preserveState: true });

  return (
    <nav aria-label="Pages" className="mt-4 flex flex-wrap items-center justify-between gap-2 text-[13px] text-cx-nav-muted">
      <span>
        {page.from}–{page.to} of {formatNumber(page.total)}
      </span>
      <span className="flex gap-2">
        <Button size="sm" disabled={page.current_page <= 1} onClick={() => go(page.current_page - 1)}>
          Previous
        </Button>
        <Button size="sm" disabled={page.current_page >= page.last_page} onClick={() => go(page.current_page + 1)}>
          Next
        </Button>
      </span>
    </nav>
  );
}

/** A query without empty values, for a clean URL. */
export function clean(query: Record<string, string | number | null | undefined>): Record<string, string | number> {
  return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')) as Record<string, string | number>;
}

/** A moment at the clinic, short: "22 Oct 2026, 3:05 pm". */
export function when(iso: string | null): string {
  if (!iso) {
    return '';
  }

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
}

/** A calendar day: "22 Oct 2026". */
export function day(isoDate: string | null): string {
  if (!isoDate) {
    return '';
  }

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${isoDate.slice(0, 10)}T00:00:00`));
}

/** A fresh request key, made when a form opens: the server records the action once however often it is sent. */
export function requestKey(prefix: string): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

  return `${prefix}-${random}`.slice(0, 80);
}
