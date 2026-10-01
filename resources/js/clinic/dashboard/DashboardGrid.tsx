import { RefreshCw } from 'lucide-react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type {
  DashboardWidget,
  FactsData,
  KpiData,
  ListData,
  QuickAction,
  StatsData,
  TrendData,
  WidgetData,
} from '../types';
import { Skeleton } from '../ui/Spinner';
import { ActionsBody, FactsBody, KpiBody, ListBody, StatsBody, TrendBody } from './WidgetBodies';
import { KpiCard, WidgetCard } from './WidgetCard';

/**
 * The dashboard engine's renderer. It draws exactly the widgets the server
 * resolved, in the server's order, each by its kind, in its zone:
 *
 *   summary  compact figures across the top
 *   main     operational lists (left, wider)
 *   side     quick actions, attention, status (right)
 *
 * There is no list of widgets here and no knowledge of roles: a widget the
 * server did not send cannot appear, and an empty zone takes no space.
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
  const summary = widgets.filter((widget) => widget.zone === 'summary');
  const main = widgets.filter((widget) => widget.zone === 'main');
  const side = widgets.filter((widget) => widget.zone === 'side');
  // On a phone or tablet the quick actions lead, before the lists; on a wide
  // screen they head the side column.
  const quickActions = side.find((widget) => widget.kind === 'actions');

  return (
    <div className="space-y-4">
      {summary.length > 0 && (
        <div className={cn('grid grid-cols-2 gap-3', summary.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
          {summary.map((widget) => (
            <KpiCard key={widget.key} widget={widget}>
              <WidgetContent widget={widget} data={data} actions={actions} />
            </KpiCard>
          ))}
        </div>
      )}

      {quickActions && main.length > 0 && (
        <div className="lg:hidden">
          <WidgetSlot widget={quickActions} data={data} actions={actions} />
        </div>
      )}

      {(main.length > 0 || side.length > 0) && (
        <div
          className={cn('grid grid-cols-1 items-start gap-4', main.length > 0 && side.length > 0 && 'lg:grid-cols-3')}
        >
          {main.length > 0 && (
            <div className={cn('grid min-w-0 gap-4', side.length > 0 && 'lg:col-span-2')}>
              {main.map((widget) => (
                <WidgetSlot key={widget.key} widget={widget} data={data} actions={actions} />
              ))}
            </div>
          )}
          {side.length > 0 && (
            <div className={cn('grid min-w-0 gap-4', main.length === 0 && 'md:grid-cols-2')}>
              {side.map((widget) =>
                widget === quickActions && main.length > 0 ? (
                  <div key={widget.key} className="hidden lg:block">
                    <WidgetSlot widget={widget} data={data} actions={actions} />
                  </div>
                ) : (
                  <WidgetSlot key={widget.key} widget={widget} data={data} actions={actions} />
                ),
              )}
            </div>
          )}
        </div>
      )}
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
  const value = data?.[widget.key];

  return (
    <WidgetCard widget={widget} aside={listTotal(widget, value)}>
      <WidgetContent widget={widget} data={data} actions={actions} />
    </WidgetCard>
  );
}

function WidgetContent({
  widget,
  data,
  actions,
}: {
  widget: DashboardWidget;
  data: Record<string, WidgetData> | undefined;
  actions: QuickAction[];
}) {
  if (widget.kind === 'actions') {
    return <ActionsBody actions={actions} />;
  }

  if (data === undefined) {
    return (
      <div role="status" aria-label={`Loading ${widget.title}`} className="space-y-2">
        <Skeleton className={widget.kind === 'kpi' ? 'h-7 w-16' : 'h-9 w-full'} />
        {widget.kind !== 'kpi' && <Skeleton className="h-9 w-full" />}
      </div>
    );
  }

  const value = data[widget.key];

  if (value === undefined || 'error' in value) {
    return (
      <div role="alert" className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px]">
        <span className="font-semibold text-rose-700">Could not load.</span>
        <button
          type="button"
          onClick={() => router.reload({ only: ['widgetData'] })}
          className="inline-flex min-h-11 items-center gap-1 rounded-full font-semibold text-rose-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 sm:min-h-0"
        >
          <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Retry
        </button>
      </div>
    );
  }

  switch (widget.kind) {
    case 'kpi':
      return <KpiBody data={value as KpiData} />;
    case 'list':
      return <ListBody data={value as ListData} empty={widget.empty} />;
    case 'stats':
      return <StatsBody data={value as StatsData} />;
    case 'trend':
      return <TrendBody data={value as TrendData} />;
    case 'facts':
      return <FactsBody data={value as FactsData} />;
    default:
      return null;
  }
}

function listTotal(widget: DashboardWidget, value: WidgetData | undefined) {
  if (widget.kind !== 'list' || !value || !('total' in value) || typeof value.total !== 'number' || value.total === 0) {
    return undefined;
  }

  return (
    <span className="rounded-full bg-clinic-inset px-2 py-0.5 text-[11.5px] font-bold tabular-nums text-clinic-ink">
      {value.total}
    </span>
  );
}
