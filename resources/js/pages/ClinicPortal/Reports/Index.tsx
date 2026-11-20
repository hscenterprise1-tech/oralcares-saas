import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import {
  Activity,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  BarChart3,
  BriefcaseMedical,
  CalendarClock,
  CalendarRange,
  Check,
  ChevronDown,
  CircleDollarSign,
  Download,
  FileSpreadsheet,
  Hash,
  MessageCircle,
  Search,
  Stethoscope,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { KpiCard } from './../CommandCenter/sections';
import { BTN_SM, DOT, FOCUS, FilterSelect, IconTile, Pill, day, money, type Tone } from '../Patients/components/patient360/v2/ui';

type Format = 'money' | 'number' | 'date';
type ReportItem = { key: string; group: string; label: string; description: string; filters: string[] };
type Filters = { from: string; to: string; branch_id: number | null; specialty: string | null; practitioner_id: number | null; money?: boolean } & { [facet: string]: string | number | boolean | null | undefined };
type Facet = { key: string; label: string; all: string; options: { value: string; label: string }[] };
type Figure = { label: string; value: number | string; format?: Format };
type Result = {
  summary: Figure[];
  columns: { key: string; label: string; format?: Format }[];
  rows: Record<string, string | number | null>[];
  truncated: boolean;
};

/** `ReportController::index`: the server is the contract. */
type Props = {
  reports: ReportItem[];
  /** 'mine': a practitioner's own work, always limited to them on the server. */
  scope: 'clinic' | 'mine';
  /** The current report's own filters (new/repeat, treatment, payment…), with this clinic's choices. */
  facets: Facet[];
  report: string | null;
  filters: Filters;
  result?: Result | null;
  options: { branches: { id: number; name: string }[]; practitioners: { id: number; name: string }[]; specialties: { key: string; label: string }[] };
  exports: { id: number; report: string; status: string; rows: number | null; at: string | null; url: string | null }[];
};

const GROUP_LOOK: Record<string, { icon: LucideIcon; tone: Tone }> = {
  Patients: { icon: Users, tone: 'blue' },
  'Visits & appointments': { icon: BarChart3, tone: 'teal' },
  'Clinical work': { icon: Stethoscope, tone: 'violet' },
  Packages: { icon: Activity, tone: 'amber' },
  Billing: { icon: Wallet, tone: 'mint' },
  'Follow-ups': { icon: CalendarClock, tone: 'rose' },
  'Staff & spending': { icon: BriefcaseMedical, tone: 'slate' },
  WhatsApp: { icon: MessageCircle, tone: 'teal' },
};
/** What each topic answers, in one line, for the report menu. */
const TOPIC_BLURB: Record<string, string> = {
  Patients: 'Who came, new or repeat, their treatments and money',
  'Visits & appointments': 'Daily visits, waiting and bookings',
  'Clinical work': 'Consultations, treatments, procedures and sessions',
  Packages: 'Session packages sold, used and ending',
  Billing: 'Billed, collected, what for, and what is owed',
  'Follow-ups': 'Due, done and missed follow-ups',
  'Staff & spending': 'Payroll and expenses',
  WhatsApp: 'Messages sent, read and failed',
};

const lookOf = (group: string) => GROUP_LOOK[group] ?? { icon: BarChart3, tone: 'slate' as Tone };

const SUMMARY_TONES: Tone[] = ['blue', 'teal', 'violet', 'amber', 'mint', 'rose'];
const NUMERIC: (Format | undefined)[] = ['money', 'number'];

function formatValue(value: string | number | null | undefined, format?: Format): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }
  if (format === 'money') {
    return money(Number(value));
  }
  if (format === 'number') {
    return Number(value).toLocaleString('en-IN', { maximumFractionDigits: 1 });
  }
  if (format === 'date') {
    return day(String(value)) ?? String(value);
  }

  return String(value);
}

/* ---------------------------------------------------------------- dates */

function iso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function presets(today: Date): { key: string; label: string; from: string; to: string }[] {
  const y = today.getFullYear();
  const m = today.getMonth();
  const daysAgo = (n: number) => new Date(y, m, today.getDate() - n);

  return [
    { key: 'today', label: 'Today', from: iso(today), to: iso(today) },
    { key: '7d', label: '7 days', from: iso(daysAgo(6)), to: iso(today) },
    { key: '30d', label: '30 days', from: iso(daysAgo(29)), to: iso(today) },
    { key: 'month', label: 'This month', from: iso(new Date(y, m, 1)), to: iso(today) },
    { key: 'last', label: 'Last month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
  ];
}

function shortRange(from: string, to: string): string {
  const a = day(from) ?? from;
  const b = day(to) ?? to;

  return from === to ? a : `${a} – ${b}`;
}

/* ----------------------------------------------------------------- page */

export default function ReportsIndex(props: Props) {
  return (
    <ClinicPortalShell title="Reports">
      <ReportsPage {...props} />
    </ClinicPortalShell>
  );
}

/**
 * Reports, compact: choose a report from one searchable menu, set the period
 * with one click (or exact dates), and read its figures — each against the
 * period just before — and its rows, sortable and searchable, with totals.
 * Everything is built and filtered on the server (`ClinicReports`).
 */
function ReportsPage({ reports, scope, facets, report, filters, result, options, exports }: Props) {
  const current = reports.find((item) => item.key === report) ?? null;
  const views = current ? reports.filter((item) => item.group === current.group) : [];
  const [custom, setCustom] = useState(false);
  const [dates, setDates] = useState({ from: filters.from, to: filters.to });
  useEffect(() => setDates({ from: filters.from, to: filters.to }), [filters.from, filters.to]);

  const today = useMemo(() => new Date(), []);
  const ranges = useMemo(() => presets(today), [today]);
  const activePreset = ranges.find((range) => range.from === filters.from && range.to === filters.to)?.key ?? null;
  const uses = (filter: string) => current?.filters.includes(filter) ?? false;

  const load = (next: Partial<Filters> & { report?: string }) => {
    // Another report starts from its own facets: the period, branch and specialty carry over, the facets do not.
    const kept = next.report && next.report !== report ? Object.fromEntries(['from', 'to', 'branch_id', 'specialty', 'practitioner_id'].map((key) => [key, filters[key]])) : filters;
    const merged: Record<string, unknown> = { ...kept, ...next, report: next.report ?? report, money: undefined };
    router.get(
      window.location.pathname,
      Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== '' && value !== undefined)) as Record<string, string>,
      { preserveScroll: true, preserveState: true, only: ['report', 'filters', 'facets', 'result'] },
    );
  };
  const facetsOn = facets.filter((facet) => filters[facet.key]).length;
  const exportNow = () => router.post(route('clinic.reports.exports.store'), { report, ...filters }, { preserveScroll: true });

  if (reports.length === 0) {
    return (
      <div className="mx-auto max-w-[720px] rounded-2xl border border-cx-line/80 bg-white shadow-cx-card p-10 text-center">
        <IconTile icon={BarChart3} tone="slate" size="lg" />
        <p className="mt-3 text-[14px] font-semibold text-cx-nav-ink">No reports are available to you here.</p>
      </div>
    );
  }

  return (
    <div className="w-full space-y-4 pb-10">
      {/* Toolbar: the report, its period and filters, export — one compact card. */}
      <section className="rounded-2xl border border-cx-line/80 bg-white shadow-cx-card p-3 sm:p-4">
        <div className="flex flex-wrap items-center gap-3">
          <ReportPicker reports={reports} current={current} onPick={(key) => load({ report: key })} />
          {scope === 'mine' && <Pill tone="violet">Only your work</Pill>}
          <button type="button" className={cn(BTN_SM, 'ml-auto')} onClick={exportNow} disabled={!report}>
            <Download className="h-3.5 w-3.5" aria-hidden="true" /> Export
          </button>
        </div>

        {/* The views of this topic: each one the same subject from another angle. */}
        {views.length > 1 && (
          <div role="tablist" aria-label={`${current?.group} views`} className="mt-3 flex gap-1.5 overflow-x-auto [scrollbar-width:none]">
            {views.map((item) => (
              <button
                key={item.key}
                type="button"
                role="tab"
                aria-selected={item.key === report}
                onClick={() => item.key !== report && load({ report: item.key })}
                className={cn(
                  'h-8 shrink-0 whitespace-nowrap rounded-full border px-3.5 text-[12.5px] font-semibold transition-colors',
                  FOCUS,
                  item.key === report ? 'border-transparent bg-[#1769AA] text-white' : 'border-[#DCE6F3] bg-white text-cx-text-2 hover:border-[#B9C6D8] hover:text-cx-nav-ink',
                )}
              >
                {item.label}
              </button>
            ))}
          </div>
        )}
        {current && <p className="mt-2 text-[12.5px] text-cx-text-2">{current.description}</p>}

        {/* This report's own filters: who and what to include. */}
        {facets.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center gap-2 rounded-2xl bg-[#F6F8FC] p-2">
            <span className="px-1 text-[11.5px] font-semibold uppercase tracking-[0.06em] text-cx-text-2">Show</span>
            {facets.map((facet, index) => (
              <FilterSelect
                key={facet.key}
                label={facet.label}
                value={typeof filters[facet.key] === 'string' ? (filters[facet.key] as string) : ''}
                options={[{ value: '', label: facet.all }, ...facet.options.map((option) => ({ ...option, tone: SUMMARY_TONES[index % SUMMARY_TONES.length] }))]}
                onChange={(value) => load({ [facet.key]: value || null })}
              />
            ))}
            {facetsOn > 0 && (
              <button type="button" onClick={() => load(Object.fromEntries(facets.map((facet) => [facet.key, null])))} className={cn('ml-auto rounded-full px-2 py-1 text-[12px] font-semibold text-[#1769AA] hover:underline', FOCUS)}>
                Clear {facetsOn === 1 ? 'filter' : `${facetsOn} filters`}
              </button>
            )}
          </div>
        )}

        {current && current.filters.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-[#EEF2F6] pt-3">
            {uses('period') && (
              <>
                <div role="radiogroup" aria-label="Period" className="flex flex-wrap gap-1 rounded-full bg-[#F1F5F9] p-1">
                  {ranges.map((range) => (
                    <button
                      key={range.key}
                      type="button"
                      role="radio"
                      aria-checked={activePreset === range.key && !custom}
                      onClick={() => {
                        setCustom(false);
                        load({ from: range.from, to: range.to });
                      }}
                      className={cn('h-7 rounded-full px-3 text-[12px] font-semibold transition-colors', FOCUS, activePreset === range.key && !custom ? 'bg-white text-[#1769AA] shadow-[0_1px_2px_rgba(15,23,42,0.12)]' : 'text-cx-text-2 hover:text-cx-nav-ink')}
                    >
                      {range.label}
                    </button>
                  ))}
                  <button
                    type="button"
                    role="radio"
                    aria-checked={custom || activePreset === null}
                    onClick={() => setCustom(true)}
                    className={cn('inline-flex h-7 items-center gap-1 rounded-full px-3 text-[12px] font-semibold transition-colors', FOCUS, custom || activePreset === null ? 'bg-white text-[#1769AA] shadow-[0_1px_2px_rgba(15,23,42,0.12)]' : 'text-cx-text-2 hover:text-cx-nav-ink')}
                  >
                    <CalendarRange className="h-3.5 w-3.5" aria-hidden="true" /> {activePreset === null && !custom ? shortRange(filters.from, filters.to) : 'Dates'}
                  </button>
                </div>
                {custom && (
                  <form
                    className="flex flex-wrap items-center gap-1.5"
                    onSubmit={(event) => {
                      event.preventDefault();
                      load(dates);
                    }}
                  >
                    <input type="date" aria-label="From" value={dates.from} max={dates.to} onChange={(e) => setDates({ ...dates, from: e.target.value })} className="h-8 rounded-full border border-[#DCE6F3] px-3 text-[12px] text-cx-nav-ink" />
                    <span className="text-[12px] text-cx-text-2">to</span>
                    <input type="date" aria-label="To" value={dates.to} min={dates.from} onChange={(e) => setDates({ ...dates, to: e.target.value })} className="h-8 rounded-full border border-[#DCE6F3] px-3 text-[12px] text-cx-nav-ink" />
                    <button type="submit" className={cn(BTN_SM, 'h-8 border-transparent bg-[#1769AA] text-white hover:bg-[#135A93]')}>
                      Show
                    </button>
                  </form>
                )}
              </>
            )}
            {uses('branch') && options.branches.length > 1 && (
              <FilterSelect
                label="Branch"
                value={filters.branch_id ? String(filters.branch_id) : ''}
                options={[{ value: '', label: 'All branches' }, ...options.branches.map((branch) => ({ value: String(branch.id), label: branch.name, tone: 'blue' as Tone }))]}
                onChange={(value) => load({ branch_id: value ? Number(value) : null })}
              />
            )}
            {uses('specialty') && options.specialties.length > 1 && (
              <FilterSelect
                label="Specialty"
                value={filters.specialty ?? ''}
                options={[{ value: '', label: 'All specialties' }, ...options.specialties.map((specialty) => ({ value: specialty.key, label: specialty.label, tone: 'violet' as Tone }))]}
                onChange={(value) => load({ specialty: value || null })}
              />
            )}
            {uses('practitioner') && options.practitioners.length > 0 && (
              <FilterSelect
                label="Practitioner"
                value={filters.practitioner_id ? String(filters.practitioner_id) : ''}
                options={[{ value: '', label: 'All practitioners' }, ...options.practitioners.map((practitioner) => ({ value: String(practitioner.id), label: practitioner.name, tone: 'teal' as Tone }))]}
                onChange={(value) => load({ practitioner_id: value ? Number(value) : null })}
              />
            )}
            {uses('period') && (
              <span className="ml-auto text-[12px] font-semibold text-cx-nav-ink">{shortRange(filters.from, filters.to)}</span>
            )}
          </div>
        )}
      </section>

      <ReportResult result={result ?? null} />

      {exports.length > 0 && (
        <section aria-label="Your exports" className="flex flex-wrap items-center gap-2 rounded-[18px] border border-[#E6EBF1] bg-white px-4 py-2.5">
          <span className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-cx-nav-ink">
            <FileSpreadsheet className="h-4 w-4 text-[#0E9F87]" aria-hidden="true" /> Exports
          </span>
          {exports.map((item) =>
            item.url ? (
              <a key={item.id} href={item.url} className={cn(BTN_SM, 'h-7')}>
                <Download className="h-3 w-3" aria-hidden="true" /> {item.report}
                {item.rows !== null && <span className="text-cx-text-3">· {item.rows.toLocaleString('en-IN')}</span>}
              </a>
            ) : (
              <Pill key={item.id} tone={item.status === 'failed' ? 'rose' : 'amber'}>
                {item.report} · {item.status === 'failed' ? 'failed' : 'preparing…'}
              </Pill>
            ),
          )}
        </section>
      )}
    </div>
  );
}

/** One menu for every report: grouped, searchable, the current one ticked. */
function ReportPicker({ reports, current, onPick }: { reports: ReportItem[]; current: ReportItem | null; onPick: (key: string) => void }) {
  // One entry per topic; the topic's views are the chips under the title, not more menu rows.
  const topics = Array.from(new Set(reports.map((item) => item.group)));
  const look = current ? lookOf(current.group) : lookOf('');

  return (
    <Menu.Root modal={false}>
      <Menu.Trigger className={cn('group flex min-w-0 items-center gap-2.5 rounded-2xl py-1 pl-1 pr-3 text-left transition-colors hover:bg-[#F6F8FB]', FOCUS)}>
        <IconTile icon={look.icon} tone={look.tone} size="sm" />
        <span className="min-w-0">
          <span className="block text-[11px] font-semibold uppercase tracking-[0.06em] text-cx-text-2">Report</span>
          <span className="flex items-center gap-1 text-[16px] font-semibold leading-5 text-[#1D2E4A]">
            <span className="truncate">{current?.group ?? 'Choose a report'}</span>
            <ChevronDown className="h-4 w-4 shrink-0 text-cx-text-2 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
          </span>
        </span>
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content align="start" sideOffset={6} className="z-50 w-[min(92vw,340px)] rounded-2xl border border-[#E6EBF1] bg-white p-1.5 shadow-[0_18px_40px_-18px_rgba(30,50,110,0.35)]">
          {topics.map((topic) => {
            const topicLook = lookOf(topic);
            const selected = topic === current?.group;

            return (
              <Menu.Item
                key={topic}
                onSelect={() => !selected && onPick(reports.find((item) => item.group === topic)!.key)}
                className={cn('flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 outline-none data-[highlighted]:bg-[#F1F5FA]', selected && 'bg-[#EEF4FB]')}
              >
                <IconTile icon={topicLook.icon} tone={topicLook.tone} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className={cn('block text-[13.5px] font-semibold', selected ? 'text-[#1769AA]' : 'text-cx-nav-ink')}>{topic}</span>
                  <span className="block truncate text-[11.5px] text-cx-text-2">{TOPIC_BLURB[topic] ?? ''}</span>
                </span>
                {selected && <Check className="h-4 w-4 shrink-0 text-[#1769AA]" aria-hidden="true" />}
              </Menu.Item>
            );
          })}
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}

/* --------------------------------------------------------------- result */

function ReportResult({ result }: { result: Result | null }) {
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null);
  useEffect(() => {
    setQuery('');
    setSort(null);
  }, [result]);

  const rows = useMemo(() => {
    if (!result) {
      return [];
    }
    const needle = query.trim().toLowerCase();
    const found = needle ? result.rows.filter((row) => Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(needle))) : result.rows;
    if (!sort) {
      return found;
    }
    const numeric = NUMERIC.includes(result.columns.find((column) => column.key === sort.key)?.format);

    return [...found].sort((a, b) => {
      const x = a[sort.key];
      const y = b[sort.key];
      const order = numeric ? Number(x ?? 0) - Number(y ?? 0) : String(x ?? '').localeCompare(String(y ?? ''), 'en-IN', { numeric: true });

      return sort.dir === 'asc' ? order : -order;
    });
  }, [result, query, sort]);

  if (!result) {
    return null;
  }
  const numericColumns = result.columns.filter((column) => NUMERIC.includes(column.format));
  const totals = Object.fromEntries(numericColumns.map((column) => [column.key, rows.reduce((sum, row) => sum + Number(row[column.key] ?? 0), 0)]));
  const peaks = Object.fromEntries(result.columns.filter((column) => column.format === 'money').map((column) => [column.key, Math.max(1, ...result.rows.map((row) => Number(row[column.key] ?? 0)))]));
  const toggleSort = (key: string) => setSort((now) => (now?.key !== key ? { key, dir: 'desc' } : now.dir === 'desc' ? { key, dir: 'asc' } : null));

  return (
    <div className="space-y-4">
      {result.summary.length > 0 && (
        <section aria-label="Figures" className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">
          {result.summary.map((figure, index) => (
            <KpiCard
              key={figure.label}
              tone={SUMMARY_TONES[index % SUMMARY_TONES.length]}
              icon={figure.format === 'money' ? CircleDollarSign : Hash}
              label={figure.label}
              period="Selected period"
              description={figure.label}
              value={formatValue(figure.value, figure.format)}
              note=""
              href={null}
            />
          ))}
        </section>
      )}

      <section aria-label="Rows" className="overflow-hidden rounded-2xl border border-cx-line/80 bg-white shadow-cx-card">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#EEF2F6] px-3 py-2 sm:px-4">
          <p className="text-[12.5px] text-cx-text-2">
            <span className="font-semibold text-cx-nav-ink">{rows.length.toLocaleString('en-IN')}</span> {rows.length === 1 ? 'row' : 'rows'}
            {query && ` of ${result.rows.length.toLocaleString('en-IN')}`}
            {result.truncated && ' · first rows only, export for all'}
          </p>
          {result.rows.length > 0 && (
            <label className="relative">
              <span className="sr-only">Search rows</span>
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
              <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search rows" className="h-8 w-48 rounded-full bg-[#F1F5F9] pl-8 pr-3 text-[12.5px] text-cx-nav-ink outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35" />
            </label>
          )}
        </div>
        {rows.length === 0 ? (
          <p className="px-5 py-10 text-center text-[13px] text-cx-text-2">{result.rows.length === 0 ? 'Nothing in this period.' : 'No row matches.'}</p>
        ) : (
          <div className="max-h-[70vh] overflow-auto">
            <table className="w-full min-w-[640px] text-left text-[12.5px]">
              <thead className="sticky top-0 z-[1] bg-[#F8FAFC] text-[11.5px] font-semibold text-cx-text-2 shadow-[inset_0_-1px_0_#EEF2F6]">
                <tr>
                  {result.columns.map((column) => {
                    const numeric = NUMERIC.includes(column.format);
                    const sorted = sort?.key === column.key ? sort.dir : null;
                    const SortIcon = sorted === 'asc' ? ArrowUp : sorted === 'desc' ? ArrowDown : ArrowUpDown;

                    return (
                      <th key={column.key} scope="col" aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : 'none'} className={cn('whitespace-nowrap px-3 py-2', numeric && 'text-right')}>
                        <button type="button" onClick={() => toggleSort(column.key)} className={cn('inline-flex items-center gap-1 rounded hover:text-cx-nav-ink', FOCUS, sorted && 'text-[#1769AA]')}>
                          {column.label}
                          <SortIcon className={cn('h-3 w-3', !sorted && 'opacity-40')} aria-hidden="true" />
                        </button>
                      </th>
                    );
                  })}
                </tr>
                {numericColumns.length > 0 && rows.length > 1 && (
                  <tr className="bg-[#EEF4FB] text-[12px] text-cx-nav-ink">
                    {result.columns.map((column, index) => (
                      <th key={column.key} scope="col" className={cn('whitespace-nowrap px-3 py-1.5', NUMERIC.includes(column.format) && 'text-right tabular-nums')}>
                        {column.key in totals ? formatValue(totals[column.key], column.format) : index === 0 ? 'Total' : ''}
                      </th>
                    ))}
                  </tr>
                )}
              </thead>
              <tbody className="divide-y divide-[#F1F4F8]">
                {rows.map((row, index) => (
                  <tr key={index} className="hover:bg-[#FBFCFE]">
                    {result.columns.map((column) => (
                      <td key={column.key} className={cn('px-3 py-1.5 text-cx-nav-ink', NUMERIC.includes(column.format) && 'text-right tabular-nums')}>
                        {column.key in peaks && Number(row[column.key] ?? 0) > 0 ? (
                          <span className="inline-flex items-center justify-end gap-2">
                            <span className="hidden h-1.5 w-14 overflow-hidden rounded-full bg-[#EEF2F7] xl:block" aria-hidden="true">
                              <span className="block h-full rounded-full bg-[#12A877]" style={{ width: `${Math.round((Number(row[column.key]) / peaks[column.key]) * 100)}%` }} />
                            </span>
                            {formatValue(row[column.key], column.format)}
                          </span>
                        ) : (
                          formatValue(row[column.key], column.format)
                        )}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
