import React from 'react';
import { router } from '@inertiajs/react';
import { CrmTabs } from '@/pages/Company/design';

/**
 * The one tab bar for the contact database.
 *
 * Three views of the same people, not three destinations: the database
 * itself, the files they arrived in, and the record of who may no longer be
 * contacted. Compliance in particular was a top-level sidebar item of its
 * own, which read as a separate system — it is the same contacts, filtered by
 * whether they can still be messaged, so it belongs here.
 *
 * Each view keeps its own route and controller, so pagination, filters and
 * the suppression actions and audit history all work exactly as they did;
 * only where you reach them from has changed.
 */

export type ContactsTabKey = 'contacts' | 'batches' | 'compliance';

/**
 * All three figures, from the server, on every view.
 *
 * Each view used to pass only the counts its own controller had already
 * computed, so a number sat beside a tab on one view and was missing beside
 * the same tab on another — the figures appeared to come and go while nothing
 * about the data had changed. `ContactWorkspaceCounts` on the PHP side is the
 * single source; this is its shape.
 */
export type ContactWorkspaceCounts = Record<ContactsTabKey, number>;

const ROUTES: Record<ContactsTabKey, string> = {
  contacts: '/company/marketing/contacts',
  batches: '/company/marketing/contacts/import',
  compliance: '/company/marketing/suppression',
};

export function ContactsTabs({
  active,
  counts,
  className,
}: {
  active: ContactsTabKey;
  counts?: Partial<Record<ContactsTabKey, number>>;
  className?: string;
}) {
  return (
    <CrmTabs
      className={className}
      value={active}
      onChange={(next: ContactsTabKey) => {
        if (next !== active) {
          router.visit(ROUTES[next]);
        }
      }}
      tabs={[
        { key: 'contacts', label: 'All contacts', count: counts?.contacts },
        { key: 'batches', label: 'Import batches', count: counts?.batches },
        { key: 'compliance', label: 'Compliance', count: counts?.compliance },
      ]}
    />
  );
}
