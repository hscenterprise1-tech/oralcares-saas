import { router } from '@inertiajs/react';
import type { CalendarView, Filters } from './types';

/**
 * The URL is the calendar's only state: view, date and filters. Every
 * control changes the URL and the server answers with the matching range.
 */
export interface CalendarQuery {
  view: CalendarView;
  date: string;
  filters: Filters;
}

export function queryFor({ view, date, filters }: CalendarQuery): Record<string, string | number | (string | number)[]> {
  const query: Record<string, string | number | (string | number)[]> = { view, date };
  // An explicit (even empty) provider list, so a provider's own default
  // can be widened to every provider.
  query.providers = filters.providers.length > 0 ? filters.providers : [''];
  if (filters.service_id) {
    query.service_id = filters.service_id;
  }
  if (filters.statuses.length > 0) {
    query.statuses = filters.statuses;
  }
  if (filters.q) {
    query.q = filters.q;
  }

  return query;
}

export function visit(url: string, query: CalendarQuery, options: { replace?: boolean } = {}): void {
  router.get(url, queryFor(query), {
    preserveState: true,
    preserveScroll: true,
    replace: options.replace ?? false,
  });
}

export function patientUrl(template: string, id: number): string {
  return template.replace('__ID__', String(id));
}
