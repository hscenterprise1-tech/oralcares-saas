import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { FactsData, KpiData, ListData, ListItem, QuickAction, StatsData, TrendData } from '../types';
import { formatMoney, formatNumber, humanize, iconFor, statusTone, toneFor } from '../ui/visuals';

/**
 * One body per widget kind. A widget's kind (from the server's registry)
 * picks the body; nothing here knows which role is looking.
 */

function figure(value: number, format?: 'number' | 'currency', currency?: string): string {
  return format === 'currency' ? formatMoney(value, currency) : formatNumber(value);
}

function Empty({ text }: { text: string }) {
  return (
    <p className="rounded-2xl bg-clinic-inset px-4 py-5 text-center text-[12.5px] text-clinic-secondary">{text}</p>
  );
}

export function KpiBody({ data }: { data: KpiData }) {
  return (
    <>
      <p className="text-[26px] font-bold leading-none tracking-tight text-clinic-ink tabular-nums">
        {figure(data.value, data.format, data.currency)}
      </p>
      {data.detail && <p className="mt-1.5 truncate text-[11.5px] text-clinic-secondary">{data.detail}</p>}
    </>
  );
}

export function StatusPill({ status }: { status: string }) {
  return (
    <span
      className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold', toneFor(statusTone(status)).chip)}
    >
      {humanize(status)}
    </span>
  );
}

function ListRow({ item }: { item: ListItem }) {
  const content = (
    <>
      {item.time && (
        <span className="min-w-[62px] shrink-0 whitespace-nowrap text-[12px] font-bold tabular-nums text-clinic-ink">
          {item.time}
        </span>
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] font-semibold text-clinic-ink">{item.primary}</span>
        {item.secondary && <span className="block truncate text-[11.5px] text-clinic-secondary">{item.secondary}</span>}
      </span>
      {typeof item.count === 'number' && (
        <span
          className={cn(
            'shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-bold tabular-nums',
            toneFor(statusTone(item.status)).chip,
          )}
        >
          {item.count}
        </span>
      )}
      {typeof item.amount === 'number' ? (
        <span className="shrink-0 text-[12.5px] font-bold tabular-nums text-amber-800">
          {formatMoney(item.amount, item.currency)}
        </span>
      ) : (
        item.status && typeof item.count !== 'number' && <StatusPill status={item.status} />
      )}
      {item.href && <ChevronRight className="h-4 w-4 shrink-0 text-clinic-secondary" aria-hidden="true" />}
    </>
  );

  return (
    <li>
      {item.href ? (
        <Link
          href={item.href}
          className="flex min-h-11 items-center gap-3 rounded-xl px-2 py-2 hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
        >
          {content}
        </Link>
      ) : (
        <div className="flex min-h-11 items-center gap-3 px-2 py-2">{content}</div>
      )}
    </li>
  );
}

export function ListBody({ data, empty }: { data: ListData; empty: string | null }) {
  if (data.items.length === 0) {
    return <Empty text={empty ?? 'Nothing here yet.'} />;
  }

  return (
    <>
      <ul className="-mx-2 divide-y divide-clinic-border">
        {data.items.map((item) => (
          <ListRow key={item.id} item={item} />
        ))}
      </ul>
      {data.total > data.items.length && !data.items.some((item) => typeof item.count === 'number') && (
        <p className="mt-1.5 text-[11.5px] text-clinic-secondary">
          Showing {data.items.length} of {formatNumber(data.total)}
        </p>
      )}
    </>
  );
}

export function StatsBody({ data }: { data: StatsData }) {
  return (
    <dl className="grid grid-cols-[repeat(auto-fit,minmax(104px,1fr))] gap-2">
      {data.stats.map((stat) => (
        <div key={stat.key} className={cn('rounded-xl px-3 py-2', toneFor(stat.tone).soft)}>
          <dt className="text-[11px] font-semibold leading-tight text-clinic-secondary">{stat.label}</dt>
          <dd className={cn('mt-0.5 text-[20px] font-bold leading-tight tabular-nums', toneFor(stat.tone).text)}>
            {figure(stat.value, stat.format, data.currency)}
          </dd>
        </div>
      ))}
    </dl>
  );
}

export function TrendBody({ data }: { data: TrendData }) {
  const max = Math.max(1, ...data.points.flatMap((point) => [point.a, point.b]));

  return (
    <div>
      <div className="mb-3 flex gap-4 text-[11.5px] font-semibold text-clinic-secondary">
        <span className="flex items-center gap-1.5">
          <span aria-hidden="true" className="h-2.5 w-2.5 rounded-sm bg-clinic-brand" /> {data.series.a}
        </span>
        <span className="flex items-center gap-1.5">
          <span aria-hidden="true" className="h-2.5 w-2.5 rounded-sm bg-blue-500" /> {data.series.b}
        </span>
      </div>
      <ul
        className="flex h-28 items-end gap-2"
        aria-label={`${data.series.a} and ${data.series.b}, last ${data.points.length} days`}
      >
        {data.points.map((point) => (
          <li key={point.date} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5">
            <span className="sr-only">
              {point.label}: {point.a} {data.series.a.toLowerCase()}, {point.b} {data.series.b.toLowerCase()}
            </span>
            <div className="flex h-full w-full items-end justify-center gap-1" aria-hidden="true">
              <span
                className="w-1/2 max-w-[12px] rounded-t-md bg-clinic-brand"
                style={{ height: `${(point.a / max) * 100}%`, minHeight: point.a > 0 ? 4 : 0 }}
              />
              <span
                className="w-1/2 max-w-[12px] rounded-t-md bg-blue-500"
                style={{ height: `${(point.b / max) * 100}%`, minHeight: point.b > 0 ? 4 : 0 }}
              />
            </div>
            <span aria-hidden="true" className="text-[11px] font-semibold text-clinic-secondary">
              {point.label}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function FactsBody({ data }: { data: FactsData }) {
  return (
    <dl className="divide-y divide-clinic-border">
      {data.rows.map((row, index) => (
        <div key={row.id ?? `${row.label}-${index}`} className="flex items-center justify-between gap-3 py-2">
          <dt className="min-w-0 truncate text-[12.5px] font-semibold text-clinic-ink">{row.label}</dt>
          <dd className="flex shrink-0 items-center gap-1.5">
            {row.values ? (
              row.values.map((value) => (
                <span
                  key={value.label}
                  className={cn(
                    'rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums',
                    toneFor(value.tone).chip,
                  )}
                >
                  {value.value} {value.label.toLowerCase()}
                </span>
              ))
            ) : typeof row.amount === 'number' ? (
              <span className={cn('text-[13px] font-bold tabular-nums', toneFor(row.tone).text)}>
                {formatMoney(row.amount, row.currency)}
              </span>
            ) : (
              <span
                className={cn(
                  'text-[12.5px] font-semibold',
                  row.tone ? toneFor(row.tone).text : 'text-clinic-secondary',
                )}
              >
                {row.value}
              </span>
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}

/** Quick actions as compact pills, not cards: the side column's first thing. */
export function ActionsBody({ actions }: { actions: QuickAction[] }) {
  return (
    <ul className="flex flex-wrap gap-2">
      {actions.map((action) => {
        const Icon = iconFor(action.icon);
        const tone = toneFor(action.tone);

        return (
          <li key={action.key}>
            <Link
              href={action.href}
              title={action.description}
              className={cn(
                'inline-flex h-9 items-center gap-1.5 rounded-full border border-clinic-border bg-clinic-surface pl-1.5 pr-3.5 text-[12.5px] font-semibold text-clinic-ink',
                '[@media(pointer:coarse)]:h-11 motion-safe:transition-colors hover:border-clinic-border-strong hover:bg-clinic-inset',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
              )}
            >
              <span className={cn('flex h-6 w-6 items-center justify-center rounded-full', tone.chip)}>
                <Icon className="h-3.5 w-3.5" aria-hidden="true" />
              </span>
              {action.label}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
