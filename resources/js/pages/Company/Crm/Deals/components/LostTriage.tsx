import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmDatePicker,
} from '@/pages/Company/design';

/**
 * Loss triage, on the deal that was lost.
 *
 * This used to be a workspace of its own — four sections and two modals over
 * the same `leads` rows the Deals list already holds. A lost deal is a deal:
 * splitting it out meant the reason was recorded on one page and read on
 * another, and the two disagreed about how many losses there were.
 *
 * Both actions post to the endpoints the old page used, unchanged:
 * `leads.triage` and `leads.winback`.
 */

export interface TriageTarget {
  id: number;
  company_name: string;
  lost_reason: string | null;
  lost_competitor: string | null;
  winback_scheduled_at: string | null;
}

export interface LostReasonOption {
  value: string;
  label: string;
}

/**
 * Why the deal was lost — and, optionally, when to try again.
 *
 * The win-back date sits in this form as well as in its own, because the
 * moment a reason is being recorded is the moment somebody knows whether the
 * account is worth revisiting. Leaving it blank is a decision, not a gap.
 */
export function TriageModal({
  deal,
  reasons,
  onClose,
}: {
  deal: TriageTarget | null;
  reasons: LostReasonOption[];
  onClose: () => void;
}) {
  const form = useForm({
    lost_reason: '',
    lost_competitor: '',
    notes: '',
    winback_date: '',
    winback_notes: '',
  });

  /*
   * Reset on open, so the previous deal's autopsy is never pre-filled into the
   * next one. `deal.id` rather than `deal`, which is a new object each render.
   */
  React.useEffect(() => {
    if (deal) {
      form.setDefaults({
        lost_reason: deal.lost_reason ?? '',
        lost_competitor: deal.lost_competitor ?? '',
        notes: '',
        winback_date: '',
        winback_notes: '',
      });
      form.reset();
      form.clearErrors();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deal?.id]);

  if (! deal) return null;

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    form.transform((data) => ({
      ...data,
      lost_competitor: data.lost_competitor || null,
      notes: data.notes || null,
      winback_date: data.winback_date || null,
      /* The autopsy is the win-back's brief when no separate note was written. */
      winback_notes: data.winback_notes || data.notes || null,
    }));
    form.post(`/company/crm/leads/${deal.id}/triage`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  };

  return (
    <CrmModal
      open
      onClose={onClose}
      size="sm"
      title="Why was this deal lost?"
      description={`Recorded against ${deal.company_name}, and read back by the loss report.`}
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" type="submit" form="deal-triage" loading={form.processing}>
            Save triage
          </CrmButton>
        </>
      }
    >
      <form id="deal-triage" onSubmit={submit} className="space-y-4">
        <CrmFormField label="Loss reason" required error={form.errors.lost_reason}>
          <CrmSelect
            value={form.data.lost_reason}
            onChange={(e) => form.setData('lost_reason', e.target.value)}
          >
            <option value="">Pick a reason</option>
            {reasons.map((reason) => (
              <option key={reason.value} value={reason.value}>{reason.label}</option>
            ))}
          </CrmSelect>
        </CrmFormField>

        <CrmFormField
          label="Competitor"
          hint="Only where we lost to one"
          error={form.errors.lost_competitor}
        >
          <CrmInput
            value={form.data.lost_competitor}
            onChange={(e) => form.setData('lost_competitor', e.target.value)}
            placeholder="e.g. Dentrix, Practo, Cliniko"
          />
        </CrmFormField>

        <CrmFormField
          label="Win-back date"
          hint="Leave blank if this account is not worth revisiting"
          error={form.errors.winback_date}
        >
          <CrmDatePicker
            value={form.data.winback_date}
            onChange={(e) => form.setData('winback_date', e.target.value)}
            aria-label="Win-back date"
          />
        </CrmFormField>

        <CrmFormField
          label="What we learned"
          hint="The part the next deal against this competitor needs"
          error={form.errors.notes}
        >
          <CrmTextarea
            rows={3}
            value={form.data.notes}
            onChange={(e) => form.setData('notes', e.target.value)}
            placeholder="Pricing, timing, the feature they would not go without…"
          />
        </CrmFormField>
      </form>
    </CrmModal>
  );
}

/**
 * Put a lost account back on the calendar.
 *
 * Separate from triage because the two happen months apart: the reason is
 * recorded when the deal dies, the win-back is scheduled — or rescheduled —
 * whenever somebody decides the timing has changed.
 */
export function WinbackModal({
  deal,
  onClose,
}: {
  deal: TriageTarget | null;
  onClose: () => void;
}) {
  const form = useForm({ winback_date: '', notes: '' });

  React.useEffect(() => {
    if (deal) {
      form.reset();
      form.clearErrors();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deal?.id]);

  if (! deal) return null;

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    form.transform((data) => ({ ...data, notes: data.notes || null }));
    form.post(`/company/crm/leads/${deal.id}/winback`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  };

  return (
    <CrmModal
      open
      onClose={onClose}
      size="sm"
      title="Schedule a win-back"
      description={`Puts a follow-up on the calendar to try ${deal.company_name} again.`}
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" type="submit" form="deal-winback" loading={form.processing}>
            Schedule it
          </CrmButton>
        </>
      }
    >
      <form id="deal-winback" onSubmit={submit} className="space-y-4">
        <p className="rounded-[16px] border border-ovs-crm-info-border bg-ovs-crm-info-soft p-3 text-[12px] leading-relaxed text-ovs-crm-info-text">
          This creates a follow-up task for the deal&rsquo;s owner on the date you pick.
        </p>

        <CrmFormField label="Target date" required error={form.errors.winback_date}>
          <CrmDatePicker
            required
            value={form.data.winback_date}
            onChange={(e) => form.setData('winback_date', e.target.value)}
            aria-label="Win-back target date"
          />
        </CrmFormField>

        <CrmFormField label="Strategy" error={form.errors.notes}>
          <CrmTextarea
            rows={3}
            value={form.data.notes}
            onChange={(e) => form.setData('notes', e.target.value)}
            placeholder="Follow up once their current licence comes up for renewal…"
          />
        </CrmFormField>
      </form>
    </CrmModal>
  );
}
