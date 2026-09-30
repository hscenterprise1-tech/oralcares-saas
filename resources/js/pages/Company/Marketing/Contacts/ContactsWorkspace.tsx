import React from 'react';
import { CrmPageHead } from '@/pages/Company/design';
import { ContactsTabs, type ContactsTabKey, type ContactWorkspaceCounts } from './ContactsTabs';

/**
 * The header every view of the contact workspace shares.
 *
 * All contacts, Import batches and Compliance are three views of the same
 * people, so they get one header rather than three that happen to look
 * similar — the title block, the view's own figures, the workspace actions,
 * and the tab strip, in that order and always in that order.
 *
 * The action area is declared here rather than left to each page, and it is
 * always present. Not every view has a page-level action — Compliance acts on
 * individual contacts in the register rather than on the workspace — and when
 * the slot was simply absent the header collapsed by exactly one control row.
 * That is invisible on a wide screen, where the actions sit beside the
 * figures, but on a narrow one they wrap onto their own line, so the tab strip
 * sat 60px higher on Compliance than on the other two and appeared to jump as
 * you switched views.
 *
 * Reserving the row makes the tab strip's position a property of the
 * workspace instead of a side effect of which view happens to have a button.
 * `min-h-10` is the height of one `CrmButton` at its default size, so the slot
 * is measured by the control it is there to hold rather than an arbitrary
 * number.
 */
export function ContactsWorkspaceHead({
  title,
  stats,
  actions,
  active,
  counts,
}: {
  title: string;
  stats?: React.ReactNode;
  /** Page-level actions. Optional — the slot holding them is not. */
  actions?: React.ReactNode;
  active: ContactsTabKey;
  counts?: ContactWorkspaceCounts;
}) {
  return (
    <>
      {/* "Marketing" everywhere — the eyebrow names the module, and the title
          and tabs already say which part of it you are in. */}
      <CrmPageHead
        eyebrow="Marketing"
        title={title}
        stats={stats}
        /*
         * From `sm` up the actions sit beside the figures, which is how every
         * other page in the product places them, and the wide layout was
         * already consistent across the three views.
         */
        actions={actions ? <div className="hidden items-center gap-2 sm:flex">{actions}</div> : undefined}
      />

      {/*
       * Below `sm`, the same actions again on a line of their own — and the
       * line is there whether or not the view has any.
       *
       * Narrow is where the views diverged: the actions wrapped onto their
       * own line on the two views that have them, and the view without them
       * had no such line, so the tab strip sat a row higher on Compliance.
       * It has to be a sibling rather than a slot because CrmPageHead wraps
       * whatever it is handed in its own content-sized element, so a width
       * set on the node passed in never reaches the element that actually
       * wraps. `min-h-10` is one CrmButton tall, so the reserved line is
       * measured by the control it holds rather than a number chosen to fit.
       */}
      <div className="mb-4 flex min-h-10 flex-wrap items-center justify-end gap-2 sm:hidden">
        {actions}
      </div>

      <ContactsTabs className="mb-4" active={active} counts={counts} />
    </>
  );
}
