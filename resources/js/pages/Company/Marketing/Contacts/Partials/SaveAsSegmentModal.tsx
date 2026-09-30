import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton, CrmInput, CrmTextarea } from '@/pages/Company/design';
import { BookmarkPlus } from 'lucide-react';

/**
 * Turns the filters currently on screen into a reusable Segment.
 *
 * Deliberately thin: it takes the same nine keys a Segment's rules already
 * are (`state`, `city`, `area`, `organization`, `tags`, `import_batch`,
 * `imported_from`, `imported_to`, `status`) straight off the active filter
 * state and posts them to the same endpoint the Segment builder itself saves
 * through. `search`, `sort_by` and `sort_dir` are left out — those describe
 * how this particular visit was looking at the list, not who the Segment
 * should target.
 *
 * This is not a second Segment builder. There is nowhere here to adjust a
 * rule — only to name the ones already applied. Anyone who wants to change
 * them opens the Segment afterwards, which is Contacts filtering all over
 * again, on the page that already does it.
 */

const SEGMENT_RULE_KEYS = [
  'state', 'city', 'area', 'organization', 'tags',
  'import_batch', 'imported_from', 'imported_to', 'status',
] as const;

export function SaveAsSegmentButton({
  filters,
  activeCount,
}: {
  filters: Record<string, unknown>;
  activeCount: number;
}) {
  const [open, setOpen] = useState(false);

  const form = useForm({
    name: '',
    description: '',
  });

  if (activeCount === 0) {
    return null;
  }

  /*
   * Shared by the form's Enter-to-submit and the footer button's click — the
   * footer lives outside the <form> element (CrmModal renders it as a
   * sibling of the body), so it needs its own trigger, and `SyntheticEvent`
   * is the common base both a form submit and a button click actually are.
   */
  const handleSubmit = (e?: React.SyntheticEvent) => {
    e?.preventDefault();

    const rules: Record<string, unknown> = {};
    for (const key of SEGMENT_RULE_KEYS) {
      const value = filters[key];
      const isEmpty =
        value === undefined ||
        value === null ||
        value === '' ||
        value === 'all' ||
        (Array.isArray(value) && value.length === 0);

      if (!isEmpty) rules[key] = value;
    }

    form.transform((data) => ({ ...data, status: 'active', rules }));
    form.post('/company/marketing/audiences', {
      onSuccess: () => setOpen(false),
    });
  };

  return (
    <>
      <CrmButton icon={BookmarkPlus} onClick={() => setOpen(true)}>
        Save as Segment
      </CrmButton>

      <CrmModal
        open={open}
        onClose={() => setOpen(false)}
        title="Save as Segment"
        description={`The ${activeCount} filter${activeCount === 1 ? '' : 's'} currently applied become this Segment's rules.`}
        size="sm"
        footer={
          <>
            <CrmButton onClick={() => setOpen(false)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              icon={BookmarkPlus}
              disabled={form.processing || !form.data.name}
              onClick={handleSubmit}
            >
              Save Segment
            </CrmButton>
          </>
        }
      >
        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <p className="mb-1.5 text-[11.5px] font-medium text-ovs-crm-text-secondary">Segment name</p>
            <CrmInput
              value={form.data.name}
              onChange={(e) => form.setData('name', e.target.value)}
              placeholder="e.g. Gujarat Untouched Clinics"
              autoFocus
              required
            />
            {form.errors.name && <p className="mt-1 text-[11.5px] text-ovs-semantic-danger">{form.errors.name}</p>}
          </div>

          <div>
            <p className="mb-1.5 text-[11.5px] font-medium text-ovs-crm-text-secondary">Description (optional)</p>
            <CrmTextarea
              value={form.data.description}
              onChange={(e) => form.setData('description', e.target.value)}
              rows={2}
            />
          </div>
        </form>
      </CrmModal>
    </>
  );
}
