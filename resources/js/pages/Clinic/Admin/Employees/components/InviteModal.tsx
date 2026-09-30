import React, { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Loader2, Mail } from 'lucide-react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton } from '@/pages/Company/design/CrmControls';
import { CrmFormField, CrmInput } from '@/pages/Company/design';
import { Employee } from '../types';

/**
 * Sends or revokes a portal invite for one employee. Reused from both the
 * Index row action and the Edit page's own access section — same modal,
 * same request shape either way.
 */

interface InviteModalProps {
  isOpen: boolean;
  onClose: () => void;
  employee: Employee | null;
}

export const InviteModal: React.FC<InviteModalProps> = ({ isOpen, onClose, employee }) => {
  const { data, setData, post, processing, errors, reset } = useForm({
    clinic_employee_id: 0,
    email: '',
  });
  const [revoking, setRevoking] = useState(false);

  useEffect(() => {
    if (! isOpen || ! employee) return;
    setData({ clinic_employee_id: employee.id, email: employee.email ?? '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, employee]);

  if (! employee) return null;

  const pending = employee.invitations[0] ?? null;

  const handleSend = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('admin.invitations.store'), {
      preserveScroll: true,
      onSuccess: () => { reset(); onClose(); },
    });
  };

  const handleRevoke = () => {
    if (! pending) return;
    setRevoking(true);
    router.post(route('admin.invitations.revoke', pending.id), {}, {
      preserveScroll: true,
      onFinish: () => { setRevoking(false); onClose(); },
    });
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={`Invite ${employee.name}`}
      description="Sends a signed link to set a password and access this clinic's portal."
      footer={
        pending ? (
          <>
            <CrmButton tone="ghost" onClick={onClose}>Close</CrmButton>
            <CrmButton tone="danger" onClick={handleRevoke} disabled={revoking}>
              {revoking && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
              Revoke invite
            </CrmButton>
          </>
        ) : (
          <>
            <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="invite-form" disabled={processing || ! data.email.trim()}>
              {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
              Send invite
            </CrmButton>
          </>
        )
      }
    >
      {pending ? (
        <div className="flex items-start gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-4">
          <Mail className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          <div className="min-w-0">
            <p className="text-[13px] font-medium text-ovs-crm-text-primary">Invite already sent to {pending.email}</p>
            <p className="mt-1 text-[12px] text-ovs-crm-text-muted">
              Expires {new Date(pending.expires_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}.
              Revoke it to send a new one to a different address.
            </p>
          </div>
        </div>
      ) : (
        <form id="invite-form" onSubmit={handleSend}>
          <CrmFormField label="Invite email" required error={errors.email} hint="They'll use this address to sign in once they set a password.">
            <CrmInput
              type="email"
              value={data.email}
              onChange={(e) => setData('email', e.target.value)}
              placeholder="name@clinic.com"
              autoFocus
            />
          </CrmFormField>
        </form>
      )}
    </CrmModal>
  );
};

export default InviteModal;
