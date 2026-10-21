import { cn } from '@/lib/utils';
import type { FactsData, ListData, StatsData } from '@/clinic/types';
import { StatusBadge } from '@/clinic/ui/badges';
import { cxTone } from '@/clinic/ui/tones';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { Empty } from './cards';

type Value = { value: number; format?: 'number' | 'currency'; currency?: string };

function figure({ value, format, currency }: Value): string {
  return format === 'currency' ? formatMoney(value, currency) : formatNumber(value);
}

/**
 * The bodies of the dashboard's general-purpose widget kinds (a short list,
 * a few figures, label and value rows), drawn from the server's data as it
 * arrives. Nothing here computes a figure.
 */
export function ListBody({ data, empty }: { data: ListData; empty: string | null }) {
  if (data.items.length === 0) {
    return <Empty>{empty ?? 'Nothing here yet.'}</Empty>;
  }

  return (
    <ul className="divide-y divide-[#F1F4F8]">
      {data.items.map((item) => (
        <li key={item.id} className="flex items-center gap-3 py-2.5">
          {item.time && <span className="w-[64px] shrink-0 text-[12px] font-medium text-cx-text-3 tabular-nums">{item.time}</span>}
          <span className="min-w-0 flex-1">
            <span className="block truncate text-[13.5px] font-medium text-cx-text">{item.primary}</span>
            {item.secondary && <span className="block truncate text-[12px] text-cx-text-3">{item.secondary}</span>}
          </span>
          {item.status && <StatusBadge status={item.status} className="shrink-0" />}
        </li>
      ))}
    </ul>
  );
}

export function StatsBody({ data }: { data: StatsData }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {data.stats.map((stat) => {
        const tone = cxTone(stat.tone);

        return (
          <div key={stat.key} className={cn('rounded-xl px-3 py-2.5', tone.bg)}>
            <p className="text-[17px] font-semibold leading-tight text-cx-text tabular-nums">{figure({ value: stat.value, format: stat.format, currency: data.currency })}</p>
            <p className={cn('mt-0.5 text-[11.5px] font-semibold', tone.ink)}>{stat.label}</p>
          </div>
        );
      })}
    </div>
  );
}

export function FactsBody({ data }: { data: FactsData }) {
  if (data.rows.length === 0) {
    return <Empty>Nothing to show yet.</Empty>;
  }

  return (
    <ul className="divide-y divide-[#F1F4F8]">
      {data.rows.map((row, index) => (
        <li key={row.id ?? index} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 py-2.5">
          <span className="min-w-0 truncate text-[13.5px] font-medium text-cx-text">{row.label}</span>
          {row.values ? (
            <span className="flex flex-wrap gap-1.5">
              {row.values.map((value) => {
                const tone = cxTone(value.tone);

                return (
                  <span key={value.label} className={cn('rounded-full px-2.5 py-0.5 text-[12px] font-semibold tabular-nums', tone.bg, tone.ink)}>
                    {figure({ value: value.value, format: value.format, currency: data.currency })} <span className="font-medium">{value.label.toLowerCase()}</span>
                  </span>
                );
              })}
            </span>
          ) : (
            <span className="text-[13px] font-semibold text-cx-text tabular-nums">{row.amount !== undefined ? formatMoney(row.amount, row.currency) : row.value}</span>
          )}
        </li>
      ))}
    </ul>
  );
}
