import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import {
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmModal,
  CrmSelect,
  CrmStatusPill,
} from '@/pages/Company/design';
import type { DealContactRow } from '../deal';

/*
 * Everybody else at the organisation.
 *
 * These were add-only: a second contact could be created and then never
 * corrected, never removed, and never made the main one. A deal that has
 * outgrown the person it was opened with had no way to say so, and the fix was
 * to open the Contacts module and hope the right row was findable.
 *
 * Promoting somebody moves the deal's own name, email and phone with them,
 * because those columns are what the list, the search and the duplicate check
 * read. And the main contact cannot be removed while they are the main
 * contact — the deal would be left naming nobody.
 */

const blank = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  whatsapp: '',
  job_title: '',
  decision_role: '',
};

export function DealPeople({
  dealId,
  contacts,
  decisionRoles,
  readOnly,
  hasUnsavedChanges = false,
}: {
  dealId: number;
  contacts: DealContactRow[];
  decisionRoles: Record<string, string>;
  readOnly: boolean;
  /** The surrounding profile form has edits that a remount would discard. */
  hasUnsavedChanges?: boolean;
}) {
  const [editing, setEditing] = React.useState<DealContactRow | 'new' | null>(null);
  const form = useForm({ ...blank });

  const open = (contact: DealContactRow | 'new') => {
    form.setData(
      contact === 'new'
        ? { ...blank }
        : {
            first_name: contact.first_name ?? '',
            last_name: contact.last_name ?? '',
            email: contact.email ?? '',
            phone: contact.phone ?? '',
            whatsapp: contact.whatsapp ?? '',
            job_title: contact.job_title ?? '',
            decision_role: contact.decision_role ?? '',
          }
    );
    form.clearErrors();
    setEditing(contact);
  };

  const submit = () => {
    const done = () => {
      setEditing(null);
      form.reset();
    };

    if (editing === 'new') {
      form.post(route('company.crm.leads.contacts.store', dealId), { preserveScroll: true, onSuccess: done });

      return;
    }

    if (editing) {
      form.put(route('company.crm.leads.contacts.update', [dealId, editing.id]), {
        preserveScroll: true,
        onSuccess: done,
      });
    }
  };

  /*
   * Promoting rewrites the deal's own name, email and phone, which remounts
   * the profile form around it — so unsaved edits elsewhere on the page would
   * be lost. Say so rather than losing them.
   */
  const promote = (contact: DealContactRow) => {
    if (hasUnsavedChanges && ! window.confirm('Save or discard your other changes first — promoting reloads this page. Continue and lose them?')) {
      return;
    }

    router.post(route('company.crm.leads.contacts.promote', [dealId, contact.id]), {}, { preserveScroll: true });
  };

  const remove = (contact: DealContactRow) => {
    if (! window.confirm(`Remove ${contact.name} from this deal?`)) return;

    router.delete(route('company.crm.leads.contacts.destroy', [dealId, contact.id]), {
      preserveScroll: true,
    });
  };

  const others = contacts.filter((c) => ! c.is_primary);

  return (
    <div className="mt-5 border-t border-ovs-crm-line pt-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
          Others at this organisation
        </p>

        {! readOnly && (
          <CrmButton size="sm" icon={Plus} onClick={() => open('new')}>
            Add a person
          </CrmButton>
        )}
      </div>

      {others.length === 0 ? (
        <p className="text-[13px] text-ovs-crm-text-muted">
          Nobody else recorded. Add the people who have a say — the finance lead, the IT contact.
        </p>
      ) : (
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {others.map((contact) => (
            <li
              key={contact.id}
              className="flex flex-col gap-2 rounded-[16px] border border-ovs-crm-border px-4 py-3"
            >
              <div className="min-w-0">
                <p className="truncate text-[13.5px] font-medium text-ovs-crm-text-primary">{contact.name}</p>
                <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                  {[contact.job_title, contact.email, contact.phone].filter(Boolean).join(' · ') ||
                    'No details recorded'}
                </p>
                {contact.decision_role && (
                  <span className="mt-1.5 inline-block">
                    <CrmStatusPill tone="neutral">{contact.decision_role}</CrmStatusPill>
                  </span>
                )}
              </div>

              {! readOnly && (
                <div className="flex flex-wrap items-center gap-1.5">
                  <CrmButton size="sm" icon={Star} onClick={() => promote(contact)}>
                    Make main
                  </CrmButton>
                  <CrmButton size="sm" icon={Pencil} onClick={() => open(contact)}>
                    Edit
                  </CrmButton>
                  <button
                    type="button"
                    aria-label={`Remove ${contact.name}`}
                    onClick={() => remove(contact)}
                    className="rounded-full p-1.5 text-ovs-crm-text-muted transition hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
                  >
                    <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <CrmModal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === 'new' ? 'Add a person' : 'Edit this person'}
        description="Somebody else at this organisation who has a say."
        footer={
          <>
            <CrmButton onClick={() => setEditing(null)}>Cancel</CrmButton>
            <CrmButton tone="primary" onClick={submit} loading={form.processing}>
              {editing === 'new' ? 'Add them' : 'Save'}
            </CrmButton>
          </>
        }
      >
        {/*
          * A div, not a form. This modal renders inside the profile page's own
          * form, and a nested form is invalid HTML the browser resolves by
          * dropping the inner submit — the button looked live and did nothing.
          * Enter still submits, wired explicitly.
          */}
        <div
          className="flex flex-col gap-3"
          onKeyDown={(e) => {
            if (e.key === 'Enter' && ! e.shiftKey) {
              e.preventDefault();
              submit();
            }
          }}
        >
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="First name" required error={form.errors.first_name}>
              <CrmInput
                value={form.data.first_name}
                onChange={(e) => form.setData('first_name', e.target.value)}
              />
            </CrmFormField>
            <CrmFormField label="Last name" error={form.errors.last_name}>
              <CrmInput
                value={form.data.last_name}
                onChange={(e) => form.setData('last_name', e.target.value)}
              />
            </CrmFormField>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Email" error={form.errors.email}>
              <CrmInput
                type="email"
                value={form.data.email}
                onChange={(e) => form.setData('email', e.target.value)}
              />
            </CrmFormField>
            <CrmFormField label="Phone" error={form.errors.phone}>
              <CrmInput
                type="tel"
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
              />
            </CrmFormField>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="WhatsApp" error={form.errors.whatsapp}>
              <CrmInput
                type="tel"
                value={form.data.whatsapp}
                onChange={(e) => form.setData('whatsapp', e.target.value)}
              />
            </CrmFormField>
            <CrmFormField label="Designation" error={form.errors.job_title}>
              <CrmInput
                value={form.data.job_title}
                onChange={(e) => form.setData('job_title', e.target.value)}
                placeholder="Practice manager"
              />
            </CrmFormField>
          </div>

          <CrmFormField label="Say in the decision" error={form.errors.decision_role}>
            <CrmSelect
              value={form.data.decision_role}
              onChange={(e) => form.setData('decision_role', e.target.value)}
            >
              <option value="">Not established</option>
              {Object.entries(decisionRoles).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>
      </CrmModal>
    </div>
  );
}
