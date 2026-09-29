import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
} from '@/pages/Company/design';
import type { Deal } from '../deal';

/*
 * Booking a demo, including how the customer actually joins it.
 *
 * The mode selector used to be online/in-person, which meant "online" covered
 * both Meet and Zoom and the link field was the only thing that said which. A
 * reminder message has to name the platform — "join on Zoom" and "join on Meet"
 * are different instructions — so the platform is now the choice, and the
 * server derives the coarse mode from it rather than accepting both and risking
 * a demo that claims to be in person with a Meet link attached.
 *
 * Dial-in id and passcode are separate fields rather than something a rep pastes
 * into the link, because the people who join a clinic demo by phone need them on
 * their own and cannot pick them out of a URL.
 */

type Platform = 'google_meet' | 'zoom' | 'in_person';

interface LeadScheduleDemoModalProps {
  isOpen: boolean;
  onClose: () => void;
  lead: Deal;
  assignableUsers: { id: number; name: string }[];
}

const PLATFORMS: { value: Platform; label: string }[] = [
  { value: 'google_meet', label: 'Google Meet' },
  { value: 'zoom', label: 'Zoom' },
  { value: 'in_person', label: 'In person' },
];

const LINK_PLACEHOLDER: Record<Platform, string> = {
  google_meet: 'https://meet.google.com/abc-defg-hij',
  zoom: 'https://zoom.us/j/1234567890',
  in_person: '',
};

export function LeadScheduleDemoModal({
  isOpen,
  onClose,
  lead,
  assignableUsers = [],
}: LeadScheduleDemoModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    lead_id: lead.id,
    scheduled_at: '',
    meeting_platform: 'google_meet' as Platform,
    presenter_id: lead.assigned_user?.id ? String(lead.assigned_user.id) : '',
    meeting_link: '',
    meeting_id: '',
    passcode: '',
    location: '',
    notes: '',
  });

  const isRemote = data.meeting_platform !== 'in_person';

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('company.crm.leads.schedule-demo', lead.id), {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={`Schedule Demonstration — ${lead.company_name}`}
      description="Book a product walkthrough session for this healthcare prospect."
    >
      <form onSubmit={handleSubmit} className="space-y-3.5">
        <CrmFormField label="Date &amp; Time" required error={errors.scheduled_at}>
          <CrmInput
            type="datetime-local"
            required
            value={data.scheduled_at}
            onChange={(e) => setData('scheduled_at', e.target.value)}
          />
        </CrmFormField>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <CrmFormField label="Platform" error={errors.meeting_platform}>
            <CrmSelect
              value={data.meeting_platform}
              onValueChange={(val) => setData('meeting_platform', val as Platform)}
              options={PLATFORMS}
            />
          </CrmFormField>

          <CrmFormField label="Presenter / Rep">
            <CrmSelect
              value={data.presenter_id}
              onValueChange={(val) => setData('presenter_id', val)}
              options={[
                { value: '', label: 'Unassigned Presenter' },
                ...assignableUsers.map((u) => ({ value: String(u.id), label: u.name })),
              ]}
            />
          </CrmFormField>
        </div>

        {isRemote ? (
          <>
            <CrmFormField label="Meeting link" error={errors.meeting_link}>
              <CrmInput
                value={data.meeting_link}
                onChange={(e) => setData('meeting_link', e.target.value)}
                placeholder={LINK_PLACEHOLDER[data.meeting_platform]}
              />
            </CrmFormField>

            {/*
              * Optional, and labelled as such. Most Meet calls have neither;
              * most Zoom calls have both. Showing them for either platform is
              * simpler than guessing, as long as nobody thinks they are required.
              */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <CrmFormField label="Meeting ID" error={errors.meeting_id}>
                <CrmInput
                  value={data.meeting_id}
                  onChange={(e) => setData('meeting_id', e.target.value)}
                  placeholder="For anyone dialling in"
                />
              </CrmFormField>

              <CrmFormField label="Passcode" error={errors.passcode}>
                <CrmInput
                  value={data.passcode}
                  onChange={(e) => setData('passcode', e.target.value)}
                  placeholder="Optional"
                />
              </CrmFormField>
            </div>
          </>
        ) : (
          <CrmFormField label="Clinic location" error={errors.location}>
            <CrmInput
              value={data.location}
              onChange={(e) => setData('location', e.target.value)}
              placeholder="Clinic address or branch"
            />
          </CrmFormField>
        )}

        <CrmFormField label="Preparation Notes">
          <CrmTextarea
            rows={2}
            value={data.notes}
            onChange={(e) => setData('notes', e.target.value)}
            placeholder="Focus areas, questions raised by doctor, specific modules requested..."
          />
        </CrmFormField>

        <p className="text-[11.5px] text-ovs-crm-text-secondary">
          {isRemote
            ? 'The customer is reminded on WhatsApp a day before and again two hours before, with this link.'
            : 'The customer is reminded a day before and again two hours before, with this address.'}
        </p>

        <div className="pt-3 border-t border-ovs-crm-border flex items-center justify-end gap-2">
          <CrmButton tone="ghost" size="sm" onClick={onClose}>
            Cancel
          </CrmButton>
          <CrmButton tone="primary" size="sm" type="submit" loading={processing}>
            Schedule Demo
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
