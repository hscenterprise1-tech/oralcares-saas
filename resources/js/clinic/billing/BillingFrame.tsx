import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Banknote, CircleDollarSign, Hourglass, Receipt, Undo2, Wallet, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { KpiCard } from '@/pages/ClinicPortal/CommandCenter/sections';
import { Button } from '../ui/Button';
import { LinkTabs } from '../ui/Tabs';
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

  return <LinkTabs label="Billing sections" className="mb-4" tabs={tabs.map((tab) => ({ key: tab.key, label: tab.label, href: tab.href, active: tab.key === current }))} />;
}

const FIGURE_TONE = { brand: 'blue', blue: 'blue', green: 'mint', amber: 'amber', red: 'rose', violet: 'violet', slate: 'slate', ink: 'slate' } as const;

/** The icon for a figure, from what it counts. */
function figureIcon(label: string): LucideIcon {
  const key = label.toLowerCase();

  return key.includes('collect') || key.includes('paid') ? Wallet : key.includes('refund') ? Undo2 : key.includes('outstanding') || key.includes('waiting') || key.includes('still') ? Hourglass : key.includes('spent') || key.includes('billed') ? Receipt : key.includes('discount') || key.includes('adjust') ? Banknote : CircleDollarSign;
}

/** One headline figure, on the same soft-wash card as the Front Desk and Command Center: a money amount or a count, its label, and what it counts. */
export function Figure({ label, value, money = true, tone = 'slate', currency = 'INR', note, period = 'In this list' }: {
  label: string;
  value: number;
  money?: boolean;
  tone?: Tone;
  currency?: string;
  note?: string;
  /** What time or set the figure covers, said under its label. */
  period?: string;
}) {
  const shown = money ? formatMoney(value, currency) : formatNumber(value);

  return <KpiCard tone={FIGURE_TONE[tone]} icon={figureIcon(label)} label={label} period={period} description={label} value={shown} note={note ?? ''} href={null} />;
}

/** A filter bar: the controls in a wrapping row, the apply and clear buttons at the end. */
export function FilterBar({ children, onApply, onClear, dirty }: { children: ReactNode; onApply: () => void; onClear: () => void; dirty: boolean }) {
  return (
    <form
      role="search"
      className="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-cx-line/80 bg-white p-4 shadow-cx-card"
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
