import { Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PillTabs } from '@/clinic/ui/Tabs';
import { EmptyState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { shortDate } from '../Patients/components/format';

/*
 * The investigations worklist (`InvestigationController@index`), for this
 * branch: awaiting a sample or result, awaiting review, and done in the last
 * two weeks. Each row opens its order.
 */

type Props = {
  branch: { id: number; name: string } | null;
  tab: string;
  category: string | null;
  counts: Record<string, number>;
  categories: Record<string, string>;
  items: {
    id: number;
    name: string;
    category: string;
    status: string;
    body_site: string | null;
    is_abnormal: boolean;
    ordered_at: string | null;
    order: { number: string | null; priority: string; doctor: string | null; url: string };
    patient: { name: string; uhid: string | null } | null;
  }[];
  links: { index: string };
};

const TABS: [string, string][] = [
  ['pending', 'Awaiting sample or result'],
  ['review', 'Awaiting review'],
  ['done', 'Done (14 days)'],
];

export default function OrdersIndex({ branch, tab, category, counts, categories, items, links }: Props) {
  const go = (next: Record<string, string | null>) => router.get(links.index, { tab, category, ...next }, { preserveState: true, preserveScroll: true });

  return (
    <ClinicPortalShell title="Investigations">
      <div className="w-full space-y-4">
        <PageHeader icon={ClipboardList} title="Investigations" description={branch?.name} />
        <div className="flex flex-wrap items-center justify-between gap-2">
          <PillTabs label="Worklist" value={tab} onChange={(key) => go({ tab: key })} options={TABS.map(([key, label]) => ({ key, label, count: counts[key] }))} />
          <select aria-label="Type" value={category ?? ''} onChange={(event) => go({ category: event.target.value || null })} className="h-10 rounded-[10px] border border-cx-line bg-white px-3 text-[13.5px]">
            <option value="">All types</option>
            {Object.entries(categories).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>
        {items.length === 0 ? (
          <EmptyState title="Nothing here" description="Investigations ordered in consultations appear here as they move from sample to result to review." />
        ) : (
          <Card as="section" aria-label="Investigations" className="p-2">
            <ul className="divide-y divide-cx-line">
              {items.map((item) => (
                <li key={item.id}>
                  <Link href={item.order.url} className="flex flex-wrap items-center justify-between gap-2 rounded-[10px] px-3 py-2.5 hover:bg-cx-slate-bg/60">
                    <span className="min-w-0">
                      <span className="block text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                        {item.name}
                        {item.is_abnormal && <span className="ml-2 text-[12px] font-semibold text-cx-rose-ink">Abnormal</span>}
                      </span>
                      <span className="block text-[12.5px] text-cx-text-2">
                        {[
                          item.patient ? `${item.patient.name}${item.patient.uhid ? ` (${item.patient.uhid})` : ''}` : null,
                          categories[item.category] ?? item.category,
                          item.body_site,
                          item.order.number,
                          item.order.doctor,
                          shortDate(item.ordered_at),
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                    </span>
                    <span className="flex items-center gap-1.5">
                      {item.order.priority !== 'routine' && <span className="rounded-full bg-cx-amber-bg px-2 py-0.5 text-[12px] font-semibold text-cx-amber-ink">{humanize(item.order.priority)}</span>}
                      <span className="rounded-full bg-cx-slate-bg px-2 py-0.5 text-[12px] font-semibold text-cx-slate-ink">{humanize(item.status)}</span>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>
    </ClinicPortalShell>
  );
}
