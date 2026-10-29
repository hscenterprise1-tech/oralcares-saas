import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import { useLiveProps } from '@/clinic/live';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { ButtonLink } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { LinkTabs } from '@/clinic/ui/Tabs';
import { EmptyState } from '@/clinic/ui/states';
import { QueueRowItem } from './components/QueueRowItem';
import { LIST_LABELS, type ListKey, type QueueRow } from './components/types';

interface Props {
  branch: { id: number; name: string };
  list: ListKey;
  page: { data: QueueRow[]; next_cursor: string | null };
  can: { queue: boolean };
  /** Only on the Queue page (`clinic.queue`): one tab per stage. */
  tabs?: { key: ListKey; count: number; href: string }[];
  links: { back: string; self: string };
}

const EMPTY: Record<ListKey, string> = {
  waiting: 'Nobody is waiting at this branch.',
  with_doctor: 'Nobody is with a doctor right now.',
  done: 'No visits finished yet today.',
  not_present: 'Nobody has been marked not present today.',
};

const STEP =
  'inline-flex min-h-9 items-center gap-1 rounded-full border border-cx-line bg-cx-surface px-3.5 text-[12.5px] font-semibold text-cx-text hover:bg-cx-nav-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan [@media(pointer:coarse)]:min-h-11';

/**
 * One Front Desk list in full (today, this branch), a cursor page at a time.
 * The same page serves the Queue (`clinic.queue`), which adds a tab per stage.
 * The first page follows other people's changes; a later page stays put.
 */
export default function FrontDeskList({ branch, list, page, tabs, links }: Props) {
  const { url } = usePage();
  const onLaterPage = url.includes('cursor=');
  useLiveProps(['page', 'tabs'], { pollMs: onLaterPage ? 0 : 15_000 });
  const title = tabs ? 'Queue' : LIST_LABELS[list];

  return (
    <ClinicPortalShell title={title}>
      <PageHeader
        eyebrow={
          <Link href={links.back} className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-cx-cyan-strong hover:underline">
            <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" /> Front desk
          </Link>
        }
        title={title}
        description={`${branch.name} · today${tabs ? '' : ` · ${LIST_LABELS[list].toLowerCase()}`}`}
      />

      {tabs && (
        <LinkTabs
          label="Queue stages"
          className="mb-4"
          tabs={tabs.map((tab) => ({ key: tab.key, label: LIST_LABELS[tab.key], href: tab.href, count: tab.count, active: tab.key === list }))}
        />
      )}

      <Card>
        {page.data.length === 0 ? (
          <EmptyState title={onLaterPage ? 'No more patients' : undefined} description={EMPTY[list]} />
        ) : (
          <ul aria-label={`${LIST_LABELS[list]} patients`} className="divide-y divide-cx-line">
            {page.data.map((row) => (
              <li key={row.id} className="px-4 py-3.5 sm:px-5">
                <QueueRowItem row={row} showStatus={list === 'waiting'} />
              </li>
            ))}
          </ul>
        )}

        {(onLaterPage || page.next_cursor) && (
          <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-cx-line px-4 py-3 sm:px-5">
            {onLaterPage ? (
              <ButtonLink href={links.self} size="sm">
                Back to the start
              </ButtonLink>
            ) : (
              <span />
            )}
            {page.next_cursor && (
              <Link href={links.self} data={{ cursor: page.next_cursor }} preserveScroll className={STEP} rel="next">
                Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </Link>
            )}
          </nav>
        )}
      </Card>
    </ClinicPortalShell>
  );
}
