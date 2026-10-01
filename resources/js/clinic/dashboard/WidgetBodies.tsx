import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type {
  BreakdownData,
  FactsData,
  FlowData,
  KpiData,
  ListData,
  QuickAction,
  StatsData,
  TrendData,
} from '../types';
import { formatMoney, formatNumber, humanize, iconFor, statusTone, toneFor } from '../ui/visuals';

/**
 * One body per widget kind. A widget's kind (from the server's registry)
 * picks the body; nothing here knows which role is looking.
 */

function figure(value: number, format?: 'number' | 'currency', currency?: string): string {
  return format === 'currency' ? formatMoney(value, currency) : formatNumber(value);
}

export function KpiBody({ data }: { data: KpiData }) {
  return (
    <div>
      <p className="text-[28px] font-bold leading-none tracking-tight text-clinic-ink tabular-nums sm:text-[34px]">
        {figure(data.value, data.format, data.currency)}
      </p>
      {data.detail && <p className="mt-2 text-[12.5px] text-clinic-secondary">{data.detail}</p>}
    </div>
  );
}

export function StatusPill({ status }: { status: string }) {
  return (
    <span
      className={cn('shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-semibold', toneFor(statusTone(status)).chip)}
    >
      {humanize(status)}
    </span>
  );
}

export function ListBody({ data, empty }: { data: ListData; empty: string | null }) {
  return (
    <div className="flex flex-1 flex-col">
      {data.summary && (
        <div
          className={cn(
            'mb-3 flex items-center justify-between rounded-2xl px-3.5 py-2.5',
            toneFor(data.summary.tone ?? 'slate').soft,
          )}
        >
          <span className="text-[12.5px] font-semibold text-clinic-secondary">{data.summary.label}</span>
          <span className={cn('text-[16px] font-bold tabular-nums', toneFor(data.summary.tone ?? 'slate').text)}>
            {figure(data.summary.value, data.summary.format, data.summary.currency)}
          </span>
        </div>
      )}
      {data.items.length === 0 ? (
        <p className="my-auto rounded-2xl bg-clinic-inset px-4 py-6 text-center text-[13px] text-clinic-secondary">
          {empty ?? 'Nothing here yet.'}
        </p>
      ) : (
        <ul className="-mx-2 divide-y divide-clinic-border">
          {data.items.map((item) => (
            <li key={item.id} className="flex items-center gap-3 px-2 py-2.5">
              {item.time && (
                <span className="min-w-[68px] shrink-0 whitespace-nowrap text-[12px] font-bold tabular-nums text-clinic-ink">
                  {item.time}
                </span>
              )}
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-semibold text-clinic-ink">{item.primary}</span>
                {item.secondary && (
                  <span className="block truncate text-[12px] text-clinic-secondary">{item.secondary}</span>
                )}
              </span>
              {typeof item.amount === 'number' ? (
                <span className="shrink-0 text-[13px] font-bold tabular-nums text-amber-800">
                  {formatMoney(item.amount, item.currency)}
                </span>
              ) : (
                item.status && <StatusPill status={item.status} />
              )}
            </li>
          ))}
        </ul>
      )}
      {data.total > data.items.length && (
        <p className="mt-2 text-[12px] text-clinic-secondary">
          Showing {data.items.length} of {formatNumber(data.total)}
        </p>
      )}
    </div>
  );
}

export function FlowBody({ data }: { data: FlowData }) {
  const total = data.stages.reduce((sum, stage) => sum + stage.count, 0);

  return (
    <div className="space-y-4">
      <div className="flex h-3 overflow-hidden rounded-full bg-clinic-inset" aria-hidden="true">
        {total > 0 &&
          data.stages.map((stage) =>
            stage.count > 0 ? (
              <span
                key={stage.key}
                className={toneFor(stage.tone).fill}
                style={{ width: `${(stage.count / total) * 100}%` }}
              />
            ) : null,
          )}
      </div>
      <dl className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        {data.stages.map((stage) => (
          <div key={stage.key} className={cn('rounded-2xl px-3 py-2.5', toneFor(stage.tone).soft)}>
            <dt className="flex items-center gap-1.5 text-[11.5px] font-semibold text-clinic-secondary">
              <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', toneFor(stage.tone).fill)} />
              {stage.label}
            </dt>
            <dd className={cn('mt-1 text-[24px] font-bold leading-none tabular-nums', toneFor(stage.tone).text)}>
              {stage.count}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

export function StatsBody({ data }: { data: StatsData }) {
  return (
    <dl className="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-3">
      {data.stats.map((stat) => (
        <div key={stat.key} className={cn('rounded-2xl px-3.5 py-3', toneFor(stat.tone).soft)}>
          <dt className="text-[11.5px] font-semibold text-clinic-secondary">{stat.label}</dt>
          <dd className={cn('mt-1 text-[24px] font-bold leading-none tabular-nums', toneFor(stat.tone).text)}>
            {figure(stat.value, stat.format, data.currency)}
          </dd>
        </div>
      ))}
    </dl>
  );
}

export function BreakdownBody({ data, empty }: { data: BreakdownData; empty: string | null }) {
  if (data.segments.length === 0) {
    return (
      <p className="my-auto rounded-2xl bg-clinic-inset px-4 py-6 text-center text-[13px] text-clinic-secondary">
        {empty ?? 'Nothing recorded yet.'}
      </p>
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-[28px] font-bold leading-none tracking-tight tabular-nums text-clinic-ink">
        {formatMoney(data.total, data.currency)}
      </p>
      <div className="flex h-3 overflow-hidden rounded-full bg-clinic-inset" aria-hidden="true">
        {data.segments.map((segment) => (
          <span
            key={segment.key}
            className={toneFor(segment.tone).fill}
            style={{ width: `${data.total > 0 ? (segment.value / data.total) * 100 : 0}%` }}
          />
        ))}
      </div>
      <ul className="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2">
        {data.segments.map((segment) => (
          <li
            key={segment.key}
            className="flex items-center justify-between gap-2 rounded-xl bg-clinic-inset/60 px-3 py-2 text-[12.5px]"
          >
            <span className="flex items-center gap-2 font-semibold text-clinic-ink">
              <span aria-hidden="true" className={cn('h-2.5 w-2.5 rounded-full', toneFor(segment.tone).fill)} />
              {segment.label}
            </span>
            <span className="font-bold tabular-nums text-clinic-ink">{formatMoney(segment.value, data.currency)}</span>
          </li>
        ))}
      </ul>
    </div>
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
        className="flex h-36 items-end gap-2"
        aria-label={`${data.series.a} and ${data.series.b}, last ${data.points.length} days`}
      >
        {data.points.map((point) => (
          <li key={point.date} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5">
            <span className="sr-only">
              {point.label}: {point.a} {data.series.a.toLowerCase()}, {point.b} {data.series.b.toLowerCase()}
            </span>
            <div className="flex h-full w-full items-end justify-center gap-1" aria-hidden="true">
              <span
                className="w-1/2 max-w-[14px] rounded-t-md bg-clinic-brand"
                style={{ height: `${(point.a / max) * 100}%`, minHeight: point.a > 0 ? 4 : 0 }}
              />
              <span
                className="w-1/2 max-w-[14px] rounded-t-md bg-blue-500"
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
        <div key={row.id ?? `${row.label}-${index}`} className="flex items-center justify-between gap-3 py-2.5">
          <dt className="min-w-0 truncate text-[13px] font-semibold text-clinic-ink">{row.label}</dt>
          <dd className="flex shrink-0 items-center gap-1.5">
            {row.values ? (
              row.values.map((value) => (
                <span
                  key={value.label}
                  className={cn(
                    'rounded-full px-2.5 py-1 text-[11.5px] font-semibold tabular-nums',
                    toneFor(value.tone).chip,
                  )}
                >
                  {value.value} {value.label.toLowerCase()}
                </span>
              ))
            ) : (
              <span
                className={cn('text-[13px] font-semibold', row.tone ? toneFor(row.tone).text : 'text-clinic-secondary')}
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

export function ActionsBody({ actions }: { actions: QuickAction[] }) {
  return (
    <ul className="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-2 lg:grid-cols-4">
      {actions.map((action) => {
        const Icon = iconFor(action.icon);
        const tone = toneFor(action.tone);

        return (
          <li key={action.key}>
            <Link
              href={action.href}
              className={cn(
                'group flex min-h-[64px] items-center gap-3 rounded-2xl border border-clinic-border bg-clinic-surface px-3.5 py-3',
                'motion-safe:transition-[border-color,box-shadow] hover:border-clinic-border-strong hover:shadow-[0_6px_20px_-10px_rgba(16,24,40,0.25)]',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
              )}
            >
              <span className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', tone.chip)}>
                <Icon className="h-5 w-5" aria-hidden="true" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-[13.5px] font-bold text-clinic-ink">{action.label}</span>
                <span className="block truncate text-[12px] text-clinic-secondary">{action.description}</span>
              </span>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
