import { RefreshCw } from 'lucide-react';
import { router } from '@inertiajs/react';
import type {
  BreakdownData,
  DashboardWidget,
  FactsData,
  FlowData,
  KpiData,
  ListData,
  QuickAction,
  StatsData,
  TrendData,
  WidgetData,
} from '../types';
import { Skeleton } from '../ui/Spinner';
import {
  ActionsBody,
  BreakdownBody,
  FactsBody,
  FlowBody,
  KpiBody,
  ListBody,
  StatsBody,
  TrendBody,
} from './WidgetBodies';
import { WidgetCard } from './WidgetCard';

/**
 * The dashboard engine's renderer. It draws exactly the widgets the server
 * resolved, in the server's order, each by its kind. There is no list of
 * widgets here and no knowledge of roles: a widget the server did not send
 * cannot appear, and its space is never reserved.
 */
export function DashboardGrid({
  widgets,
  data,
  actions,
}: {
  widgets: DashboardWidget[];
  /** Undefined while the deferred figures load. */
  data: Record<string, WidgetData> | undefined;
  actions: QuickAction[];
}) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      {widgets.map((widget) => (
        <WidgetSlot key={widget.key} widget={widget} data={data} actions={actions} />
      ))}
    </div>
  );
}

function WidgetSlot({
  widget,
  data,
  actions,
}: {
  widget: DashboardWidget;
  data: Record<string, WidgetData> | undefined;
  actions: QuickAction[];
}) {
  if (widget.kind === 'actions') {
    return (
      <WidgetCard widget={widget}>
        <ActionsBody actions={actions} />
      </WidgetCard>
    );
  }

  const value = data?.[widget.key];

  if (data === undefined) {
    return (
      <WidgetCard widget={widget}>
        <div role="status" aria-label={`Loading ${widget.title}`} className="mt-auto space-y-2.5">
          <Skeleton className="h-8 w-24" />
          <Skeleton className="h-4 w-full" />
          {widget.span > 1 && <Skeleton className="h-4 w-2/3" />}
        </div>
      </WidgetCard>
    );
  }

  if (value === undefined || 'error' in value) {
    return (
      <WidgetCard widget={widget}>
        <div role="alert" className="my-auto flex flex-col items-start gap-2 rounded-2xl bg-rose-50/70 px-4 py-3.5">
          <p className="text-[13px] font-semibold text-rose-700">These figures could not load.</p>
          <button
            type="button"
            onClick={() => router.reload({ only: ['widgetData'] })}
            className="inline-flex min-h-11 items-center gap-1.5 rounded-full text-[12.5px] font-semibold text-rose-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 sm:min-h-0"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Try again
          </button>
        </div>
      </WidgetCard>
    );
  }

  return (
    <WidgetCard widget={widget} aside={listTotal(widget, value)}>
      <Body widget={widget} value={value} />
    </WidgetCard>
  );
}

function listTotal(widget: DashboardWidget, value: WidgetData) {
  if (widget.kind !== 'list' || !('total' in value) || typeof value.total !== 'number') {
    return undefined;
  }

  return (
    <span className="rounded-full bg-clinic-inset px-2.5 py-1 text-[12px] font-bold tabular-nums text-clinic-ink">
      {value.total}
    </span>
  );
}

function Body({ widget, value }: { widget: DashboardWidget; value: WidgetData }) {
  switch (widget.kind) {
    case 'kpi':
      return <KpiBody data={value as KpiData} />;
    case 'list':
      return <ListBody data={value as ListData} empty={widget.empty} />;
    case 'flow':
      return <FlowBody data={value as FlowData} />;
    case 'stats':
      return <StatsBody data={value as StatsData} />;
    case 'breakdown':
      return <BreakdownBody data={value as BreakdownData} empty={widget.empty} />;
    case 'trend':
      return <TrendBody data={value as TrendData} />;
    case 'facts':
      return <FactsBody data={value as FactsData} />;
    default:
      return null;
  }
}
