import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ArrowLeft, CalendarClock, CalendarPlus, ChevronDown, ClipboardPlus, HeartOff, Mail, Pencil, Phone, Printer, Stethoscope, UserRoundCog } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING, TONE, dsButtonClasses } from '@/clinic/ds';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { ageSex, lastSeen, shortDate } from '../format';
import { LifecycleBadge, MaskedMobile, PatientAvatar } from '../patientVisuals';
import { DeathRecordDialog } from './DeathRecordDialog';
import { SafetyStrip } from './SafetyStrip';
import type { ConditionEditor, Patient360Action, SafetyEditor, Patient360Context, Patient360Identity } from './types';

/** The fact tiles' colour families, as on the front desk: blue contact, teal doctor, violet visits, green registration. */
const TILE = {
  blue: cn(TONE.blue.soft, TONE.blue.ink),
  teal: cn(TONE.teal.soft, TONE.teal.ink),
  violet: cn(TONE.violet.soft, TONE.violet.ink),
  green: cn(TONE.green.soft, TONE.green.ink),
} as const;

const MENU_ITEM =
  'flex min-h-10 cursor-pointer items-center gap-2.5 rounded-[8px] px-2.5 py-1.5 text-[14px] font-medium text-[#173B60] outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-60 data-[highlighted]:bg-[#EAF2FA] data-[highlighted]:text-[#1769AA]';

/** The patient's actions use the front desk's buttons: one solid primary, the rest white. */
const PRIMARY = dsButtonClasses('primary', 'md', 'shrink-0 max-md:h-11');
const SECONDARY = dsButtonClasses('secondary', 'md', 'shrink-0 shadow-[0_1px_2px_rgba(15,23,42,0.06)] max-md:h-11');

/**
 * Patient 360's header, in the clinical-workspace layout: the way back and
 * the patient's actions on one line; the patient's identity beside a large
 * avatar; the facts the desk asks first as icon tiles; and the safety
 * information — allergies and alerts for everyone, notes for clinicians — in
 * their own tinted panels. Only actions the server marks available, and only
 * data it sent for this member, are shown.
 */
export function PatientHeader({
  patient,
  context,
  actions,
  practitioners,
  backHref,
  governance,
  openRequests,
  governanceEndpoints,
  conditionEditor,
  safetyEditor,
}: {
  patient: Patient360Identity;
  context: Patient360Context;
  actions: Patient360Action[];
  practitioners: { id: number; name: string; specialty: string | null }[];
  backHref: string;
  governance: Record<string, 'direct' | 'request' | null>;
  openRequests: string[];
  governanceEndpoints: { request: string; direct: string } | null;
  conditionEditor: ConditionEditor | null;
  safetyEditor: SafetyEditor | null;
}) {
  const intake = useIntake();
  const [assigning, setAssigning] = useState(false);
  const [deathDialog, setDeathDialog] = useState<'record' | 'correct' | null>(null);
  const deceased = patient.status === 'deceased';
  // Recording a death (or correcting one) is governed: the owner acts, others ask; one request at a time.
  const deathKey = deceased ? 'patient.correct_death' : 'patient.record_death';
  const deathMode = patient.status === 'merged' || !governanceEndpoints ? null : (governance[deathKey] ?? null);
  const deathPending = openRequests.includes(`${deathKey}:${patient.id}`);
  const available = (key: string) => actions.find((action) => action.key === key && action.available);
  const identity = [ageSex(patient.age, patient.gender), patient.blood_group ? `Blood group ${patient.blood_group}` : null].filter(Boolean) as string[];
  // Check-in is the desk's own popup (Repeat Case); it is offered where that popup is.
  const checkIn = available('check_in') && intake.available && patient.status !== 'merged' && !deceased;
  // Into the consultation: the open one, or a new one for a clinician who may start it (refused by the server for a deceased patient).
  const consultation = available('continue_consultation') ?? (deceased ? undefined : available('start_consultation'));
  const edit = available('edit');
  const print = available('print');
  const transfer = available('transfer') && practitioners.length > 0 ? available('transfer') : undefined;

  const buttons = (
    <>
      {checkIn && (
        <button
          type="button"
          onClick={() =>
            intake.open('repeat', {
              id: patient.id,
              uhid: patient.uhid,
              name: patient.name,
            })
          }
          className={PRIMARY}
        >
          <ClipboardPlus className="h-[18px] w-[18px]" aria-hidden="true" />
          Check in
        </button>
      )}
      {consultation?.url &&
        (consultation.key === 'continue_consultation' ? (
          <Link href={consultation.url} className={checkIn ? SECONDARY : PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            Continue consultation
          </Link>
        ) : (
          <button type="button" onClick={() => router.post(consultation.url as string)} className={checkIn ? SECONDARY : PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            {consultation.label}
          </button>
        ))}
      {edit?.url && (
        <Link href={edit.url} className={SECONDARY}>
          <Pencil className="h-4 w-4" aria-hidden="true" />
          Edit
        </Link>
      )}
      {print?.url && (
        <a href={print.url} target="_blank" rel="noopener" className={SECONDARY}>
          <Printer className="h-4 w-4" aria-hidden="true" />
          Print
        </a>
      )}
      {(transfer || deathMode) && (
        <Menu.Root modal={false}>
          <Menu.Trigger className={SECONDARY}>
            More
            <ChevronDown className="h-4 w-4" aria-hidden="true" />
          </Menu.Trigger>
          <Menu.Portal>
            <Menu.Content align="end" sideOffset={6} className="z-50 min-w-[220px] rounded-[12px] border border-cx-line bg-white p-1.5 shadow-cx-raised">
              {transfer && !deceased && (
                <Menu.Item onSelect={() => setAssigning(true)} className={MENU_ITEM}>
                  <UserRoundCog className="h-4 w-4 text-cx-m-teal" aria-hidden="true" />
                  Assign doctor
                </Menu.Item>
              )}
              {deathMode && (
                <Menu.Item disabled={deathPending} onSelect={() => setDeathDialog(deceased ? 'correct' : 'record')} className={MENU_ITEM}>
                  <HeartOff className="h-4 w-4 text-cx-slate-ink" aria-hidden="true" />
                  <span className="min-w-0">
                    {deceased ? 'Correct the recorded death' : 'Record death'}
                    {deathPending && <span className="block text-[12px] font-normal text-cx-text-2">Waiting for the owner&rsquo;s decision</span>}
                    {!deathPending && deathMode === 'request' && <span className="block text-[12px] font-normal text-cx-text-2">Asks the owner</span>}
                  </span>
                </Menu.Item>
              )}
            </Menu.Content>
          </Menu.Portal>
        </Menu.Root>
      )}
    </>
  );
  const hasButtons = Boolean(checkIn || consultation?.url || edit?.url || print?.url || transfer || deathMode);

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link
          href={backHref}
          className={cn('-ml-2 inline-flex h-9 items-center gap-1.5 rounded-lg px-2 text-[13.5px] font-semibold text-[#475569] hover:bg-white hover:text-[#173B60] [@media(pointer:coarse)]:h-11', FOCUS_RING)}
        >
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          Back to Patients
        </Link>
        {hasButtons && <div className="hidden flex-wrap items-center justify-end gap-2 md:flex">{buttons}</div>}
      </div>

      {/* The recorded death, stated plainly above everything else about the patient. */}
      {deceased && (
        <div role="status" className="mt-2 flex items-start gap-3 rounded-2xl border border-[#CBD3DE] bg-cx-slate-bg px-4 py-3 text-cx-slate-ink sm:items-center sm:px-5">
          <HeartOff className="mt-0.5 h-5 w-5 shrink-0 sm:mt-0" aria-hidden="true" />
          <p className="text-[13.5px] leading-5">
            <span className="font-semibold">
              Recorded as deceased
              {patient.deceased?.on ? ` on ${shortDate(patient.deceased.on)}` : ''}.
            </span>{' '}
            {patient.deceased?.recorded_by && <span>Recorded by {patient.deceased.recorded_by}. </span>}
            New visits, bookings, consultations, prescriptions and charges are not allowed; the record stays available to read.
          </p>
        </div>
      )}

      {/* The front desk's header treatment: the soft blue-lavender-mint wash, the patient on it, then the facts on a white band. */}
      <section
        aria-label="Patient"
        className="relative mt-2 overflow-hidden rounded-2xl border border-[#DCE6F3] bg-gradient-to-r from-[#EEF4FF] via-[#F6F3FF] to-[#EDF8F5] shadow-[0_1px_2px_rgba(15,23,42,0.04),0_10px_28px_-22px_rgba(23,59,96,0.35)]"
      >
        <div className="relative flex items-center gap-4 px-4 py-4 sm:gap-5 sm:px-6 sm:py-5">
          <div className="shrink-0 rounded-full bg-white p-1 shadow-[0_10px_24px_-14px_rgba(23,59,96,0.5)] ring-1 ring-[#DCE6F3]">
            <PatientAvatar name={patient.name} size="portrait" quiet={patient.status === 'merged'} />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <h1 className="min-w-0 text-[22px] font-bold leading-7 tracking-tight text-[#173B60] [overflow-wrap:anywhere] sm:text-[26px] sm:leading-8">{patient.name}</h1>
              <LifecycleBadge status={patient.status} />
            </div>
            {(patient.uhid || identity.length > 0) && (
              <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                {patient.uhid && (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/85 px-2.5 py-0.5 text-[12.5px] font-semibold leading-5 text-[#173B60] ring-1 ring-[#DCE6F3]">
                    <span className="font-medium text-[#64748B]">UHID</span>
                    <span className="tracking-[0.01em]">{patient.uhid}</span>
                  </span>
                )}
                {identity.length > 0 && (
                  <p className="flex flex-wrap items-center gap-x-2 text-[13.5px] leading-5 text-[#475569]">
                    {identity.map((fact, index) => (
                      <span key={fact} className="whitespace-nowrap">
                        {index > 0 && (
                          <span aria-hidden="true" className="mr-2 text-[#94A3B8]">
                            ·
                          </span>
                        )}
                        {fact}
                      </span>
                    ))}
                  </p>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Phones: the actions as one row of equal buttons, as in the reference. */}
        {hasButtons && <div className="relative flex gap-2 px-4 pb-4 md:hidden [&>*]:min-w-0 [&>*]:flex-1 [&>*]:px-2 max-[420px]:[&_svg]:hidden">{buttons}</div>}

        {/* Facts on a white band: four (or five with an email) equal columns from tablets; 2×2 on phones. */}
        <dl
          className={cn(
            'relative mx-3 mb-3 grid grid-cols-2 gap-x-3 gap-y-4 rounded-xl bg-white/85 px-3.5 py-3.5 ring-1 ring-[#E2E8F0] sm:mx-4 sm:mb-4 xl:gap-x-4 xl:px-5',
            patient.email ? 'sm:grid-cols-3 xl:grid-cols-5' : 'sm:grid-cols-4',
          )}
        >
          <Fact tile="blue" icon={Phone} label="Mobile">
            <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
          </Fact>
          {patient.email && (
            <Fact tile="blue" icon={Mail} label="Email">
              <span className="block text-[14px] font-medium leading-5 text-cx-text [overflow-wrap:anywhere]">{breakable(patient.email)}</span>
            </Fact>
          )}
          <Fact tile="teal" icon={Stethoscope} label="Assigned doctor">
            <Value empty={!patient.practitioner}>{patient.practitioner ?? 'Not assigned'}</Value>
          </Fact>
          <Fact tile="green" icon={CalendarPlus} label="Registered">
            <Value empty={!patient.registered_on}>{shortDate(patient.registered_on) ?? 'Not recorded'}</Value>
            {patient.registered_branch && <Sub>{patient.registered_branch}</Sub>}
          </Fact>
          <Fact tile="violet" icon={CalendarClock} label="Last visit">
            <Value empty={!patient.last_visit_on}>{lastSeen(patient.last_visit_on) ?? 'No visits yet'}</Value>
            {patient.branch && <Sub>{patient.branch}</Sub>}
          </Fact>
        </dl>

        {/* Safety: medical conditions, safety alerts and notes in one compact strip (`SafetyStrip`). No Allergies panel on Patient 360. */}
        <div className="relative border-t border-[#E6EBF1] bg-white px-4 py-4 sm:px-6">
          <SafetyStrip context={context} editor={conditionEditor} safetyEditor={safetyEditor} readOnly={patient.status === 'merged'} />
        </div>
      </section>

      {transfer?.url && <AssignDoctorDialog open={assigning} onOpenChange={setAssigning} url={transfer.url} current={patient.practitioner} practitioners={practitioners} />}
      {deathMode && governanceEndpoints && deathDialog && (
        <DeathRecordDialog
          open
          onOpenChange={(next) => !next && setDeathDialog(null)}
          kind={deathDialog}
          mode={deathMode}
          patient={{
            id: patient.id,
            name: patient.name,
            dateOfDeath: patient.deceased?.on ? shortDate(patient.deceased.on) : null,
          }}
          endpoints={governanceEndpoints}
        />
      )}
    </div>
  );
}

function Fact({ tile, icon: Icon, label, children }: { tile: keyof typeof TILE; icon: typeof Phone; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-2 xl:gap-3">
      {/* The icon gives way on the narrowest phones so the value keeps its width. */}
      <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-xl max-[400px]:hidden xl:h-9 xl:w-9', TILE[tile])} aria-hidden="true">
        <Icon className="h-[18px] w-[18px]" strokeWidth={1.9} />
      </span>
      <div className="min-w-0 pt-px">
        <dt className="text-[12.5px] font-medium leading-4 text-[#64748B]">{label}</dt>
        <dd className="mt-1 min-w-0">{children}</dd>
      </div>
    </div>
  );
}

/** A long email or address breaks after its separators before it breaks mid-word. */
function breakable(text: string): ReactNode[] {
  return text.split(/(?<=[@._-])/).flatMap((part, index) => (index === 0 ? [part] : [<wbr key={index} />, part]));
}

function Value({ empty, children }: { empty?: boolean; children: ReactNode }) {
  return <span className={cn('block text-[14px] leading-5 [overflow-wrap:anywhere]', empty ? 'text-[#64748B]' : 'font-semibold text-[#173B60]')}>{children}</span>;
}

function Sub({ children }: { children: ReactNode }) {
  return <span className={cn('mt-px block text-[12px] font-medium leading-4 [overflow-wrap:anywhere]', TONE.violet.ink)}>{children}</span>;
}

/**
 * More → Assign doctor: the patient's doctor (`current_practitioner_id`), set
 * through the existing transfer endpoint, which records who changed it and
 * why and accepts only the clinic's practitioners.
 */
function AssignDoctorDialog({
  open,
  onOpenChange,
  url,
  current,
  practitioners,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  url: string;
  current: string | null;
  practitioners: { id: number; name: string; specialty: string | null }[];
}) {
  const [doctor, setDoctor] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const close = (next: boolean) => {
    onOpenChange(next);
    if (!next) {
      setDoctor('');
      setReason('');
      setErrors({});
    }
  };

  const submit = () => {
    if (doctor === '') {
      setErrors({ to_practitioner_id: 'Choose a doctor.' });

      return;
    }
    router.post(
      url,
      {
        to_practitioner_id: Number(doctor),
        reason: reason.trim() || null,
      },
      {
        preserveScroll: true,
        onStart: () => setSaving(true),
        onFinish: () => setSaving(false),
        onError: (serverErrors) => setErrors(serverErrors as Record<string, string>),
        onSuccess: () => close(false),
      },
    );
  };

  return (
    <Modal
      open={open}
      onOpenChange={close}
      title="Assign doctor"
      description={current ? `Currently assigned to ${current}.` : 'Nobody is assigned yet.'}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={() => close(false)} disabled={saving}>
            Cancel
          </Button>
          <Button tone="primary" onClick={submit} loading={saving} loadingLabel="Assigning…" disabled={saving}>
            Assign
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Doctor" htmlFor="assign-doctor" required error={errors.to_practitioner_id || undefined}>
          <Select
            id="assign-doctor"
            value={doctor}
            onChange={(event) => {
              setDoctor(event.target.value);
              setErrors({});
            }}
            invalid={!!errors.to_practitioner_id}
            placeholder="Choose a doctor"
            options={practitioners.map((practitioner) => ({
              value: String(practitioner.id),
              label: practitioner.specialty ? `${practitioner.name} · ${practitioner.specialty}` : practitioner.name,
            }))}
          />
        </Field>
        <Field label="Reason" htmlFor="assign-reason" hint="Optional. Kept with the patient's history." error={errors.reason || undefined}>
          <Input id="assign-reason" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} />
        </Field>
      </div>
    </Modal>
  );
}
