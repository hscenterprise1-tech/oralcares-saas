import { useRef, useState, type ChangeEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { Camera, GraduationCap, MapPin, Pencil, Send, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { Field, inputClass, Modal } from '@/clinic/ui/Modal';
import type { ProfileProps } from '../lib/types';
import { Avatar, Chip, statusOf } from '../lib/ui';

/**
 * Who this is at a glance: photo, name, top qualification, profession and
 * specialty, primary branch, status, and the actions on the record.
 */
export function ProfileHeader({ props }: { props: ProfileProps }) {
  const { employee, practitioner, can, links } = props;
  const status = statusOf(employee.status);
  const photoInput = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState(employee.email ?? '');
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const uploadPhoto = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }
    setUploading(true);
    router.post(`${links.self}/photo`, { photo: file }, { forceFormData: true, preserveScroll: true, onFinish: () => setUploading(false) });
  };

  const setStatus = (value: string) => {
    setBusy(value);
    router.patch(`${links.self}/status`, { status: value }, { preserveScroll: true, onFinish: () => setBusy(null) });
  };

  const invite = () => {
    setBusy('invite');
    setInviteError(null);
    router.post(
      links.invite,
      { clinic_employee_id: employee.id, email: inviteEmail },
      {
        preserveScroll: true,
        onSuccess: () => setInviteOpen(false),
        onError: (errors) => setInviteError(Object.values(errors)[0] ?? 'The invitation could not be sent.'),
        onFinish: () => setBusy(null),
      },
    );
  };

  const subtitle = [practitioner?.profession ?? employee.staff_type_label, practitioner?.primary_specialty?.name].filter(Boolean).join(' · ');
  const active = employee.status_tab === 'active';

  return (
    <header className="relative mb-4 overflow-hidden rounded-3xl border border-white/70 bg-white/[0.93] p-4 backdrop-blur-sm sm:p-6">
      <div aria-hidden="true" className={cn('pointer-events-none absolute inset-x-0 top-0 h-20 bg-gradient-to-r', practitioner ? 'from-violet-100/80 via-sky-100/60 to-teal-100/60' : 'from-sky-100/70 via-teal-50/60 to-emerald-50/60')} />
      <div className="relative flex flex-col gap-4 sm:flex-row sm:items-end">
        <div className="relative shrink-0 self-start">
          <Avatar name={employee.name} url={employee.photo_url} id={employee.id} size={88} />
          {can.edit && (
            <>
              <button
                type="button"
                onClick={() => photoInput.current?.click()}
                aria-label="Change photo"
                className="absolute -bottom-1 -right-1 flex h-11 w-11 items-center justify-center rounded-full bg-clinic-ink text-white ring-4 ring-white hover:bg-clinic-ink-soft focus-visible:outline-none focus-visible:ring-clinic-brand-strong sm:h-9 sm:w-9"
              >
                <Camera className="h-4 w-4" aria-hidden="true" />
              </button>
              <input ref={photoInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={uploadPhoto} />
            </>
          )}
          {uploading && <span className="absolute inset-0 rounded-full bg-white/60" aria-label="Uploading photo" />}
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-[22px] font-bold leading-tight tracking-tight text-clinic-ink sm:text-[26px]">{employee.name}</h1>
            <Chip className={status.chip}>
              <span className={cn('h-1.5 w-1.5 rounded-full', status.dot)} aria-hidden="true" />
              {status.label}
            </Chip>
          </div>
          {practitioner?.headline && (
            <p className="mt-1 flex items-center gap-1.5 text-[13.5px] font-semibold text-violet-800">
              <GraduationCap className="h-4 w-4" aria-hidden="true" /> {practitioner.headline}
            </p>
          )}
          <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-clinic-secondary">
            {subtitle && (
              <span className="inline-flex items-center gap-1.5">
                <Stethoscope className="h-3.5 w-3.5" aria-hidden="true" /> {subtitle}
              </span>
            )}
            {employee.primary_branch && (
              <span className="inline-flex items-center gap-1.5">
                <MapPin className="h-3.5 w-3.5" aria-hidden="true" /> {employee.primary_branch.name}
                {employee.branches.length > 1 && <span>+{employee.branches.length - 1}</span>}
              </span>
            )}
          </div>
        </div>

        {can.edit && (
          <div className="flex flex-wrap gap-2">
            {!employee.has_login && !employee.invitation && active && (
              <Button icon={Send} onClick={() => setInviteOpen(true)}>
                Invite to sign in
              </Button>
            )}
            <Link href={links.edit} className={buttonClasses('secondary', 'md')}>
              <Pencil className="h-4 w-4" aria-hidden="true" /> Edit
            </Link>
            {!employee.is_self &&
              (active ? (
                <Button tone="ghost" loading={busy === 'inactive'} onClick={() => setStatus('inactive')}>
                  Deactivate
                </Button>
              ) : (
                <Button tone="ghost" loading={busy === 'active'} onClick={() => setStatus('active')}>
                  Reactivate
                </Button>
              ))}
            {!employee.is_self && employee.status !== 'resigned' && !active && (
              <Button tone="ghost" loading={busy === 'resigned'} onClick={() => setStatus('resigned')}>
                Archive
              </Button>
            )}
          </div>
        )}
      </div>

      <Modal
        open={inviteOpen}
        onOpenChange={setInviteOpen}
        size="sm"
        title={`Invite ${employee.name}`}
        description="They get a link to set a password and sign in. It expires in three days."
        footer={
          <>
            <Button tone="ghost" onClick={() => setInviteOpen(false)}>
              Cancel
            </Button>
            <Button tone="primary" icon={Send} loading={busy === 'invite'} onClick={invite}>
              Send invitation
            </Button>
          </>
        }
      >
        <Field label="Email" htmlFor="invite-email" error={inviteError}>
          <input id="invite-email" type="email" className={inputClass} value={inviteEmail} onChange={(e) => setInviteEmail(e.target.value)} />
        </Field>
      </Modal>
    </header>
  );
}
