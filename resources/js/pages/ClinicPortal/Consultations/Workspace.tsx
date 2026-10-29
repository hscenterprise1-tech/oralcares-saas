import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, ClipboardList, FileSignature, FileText, FlaskConical, HeartPulse, NotebookPen, Pill, Plus, Printer, ShieldAlert, Stethoscope, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Checkbox, Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { humanize } from '@/clinic/ui/visuals';
import { ClinicHttpError, requestJson } from '@/clinic/http';
import { shortDate } from '../Patients/components/format';
import { CarePanel, DentalPanel, PhysioAssessmentPanel } from './ClinicalPanels';

/*
 * The consultation: one patient's visit with a clinician, from
 * `ConsultationController@show`. Everything here writes through the
 * existing endpoints, which authorise every action on the encounter
 * (`ClinicalEncounterPolicy`, `PrescriptionPolicy`, order policy):
 *
 * - the narrative autosaves with the encounter's lock version (a 409 means
 *   another screen saved first — nothing is overwritten);
 * - diagnoses, procedures, notes, investigations and prescriptions are
 *   added and removed while the consultation is open;
 * - a prescription is signed only after the patient's documented allergies
 *   are reviewed for it (`ClinicalPrescriptionService` refuses otherwise);
 * - signing the consultation locks it; afterwards only addenda (and the
 *   signer's own amendments, with a reason) are added, keeping the history.
 *
 * Allergies are shown here, with medical conditions and alerts, because
 * this is where they bear on decisions; "not recorded" is never "none".
 */

type Rx = {
  id: number;
  number: string | null;
  status: string;
  notes: string | null;
  finalized_at: string | null;
  cancellation_reason: string | null;
  items: { id: number; drug_name: string; form: string | null; strength: string | null; dosage: string; frequency: string; duration: string; route: string | null; timing: string | null; instructions: string | null }[];
  allergies: { status: string; allergies: { label: string; severity: string | null; reaction: string | null }[]; fingerprint?: string; review_required?: boolean; reviewed?: boolean; reviewed_at?: string | null } | null;
  links: { self: string; items: string; finalize: string; cancel: string; print: string; download: string; allergy_review: string };
  can: { edit: boolean; cancel: boolean; print: boolean };
};

type Props = {
  encounter: {
    id: number;
    number: string | null;
    label: string;
    specialty: string | null;
    status: string;
    is_finalized: boolean;
    started_at: string | null;
    finalized_at: string | null;
    finalized_by: string | null;
    signature_intact: boolean | null;
    doctor: string | null;
    branch: string | null;
    lock_version: number;
    last_autosaved_at: string | null;
    narrative: Record<string, string | null>;
    follow_up_on: string | null;
  };
  patient: {
    id: number;
    name: string;
    uhid: string | null;
    gender: string | null;
    age: number | null;
    blood_group: string | null;
    alerts: { type: string; description: string; severity: string | null }[];
    allergies: { status: string; allergies: { label: string; severity: string | null; reaction: string | null }[] };
    conditions: string[];
  };
  diagnoses: { id: number; code: string | null; description: string; status: string; is_primary: boolean }[];
  procedures: { id: number; code: string | null; name: string; quantity: number; notes: string | null }[];
  notes: { id: number; type: string; type_label: string; text: string; author: string | null; status: string; created_at: string | null }[];
  prescriptions: Rx[];
  orders: { id: number; number: string | null; status: string; priority: string; url: string | null; items: { id: number; item_name: string; category: string; status: string; body_site: string | null }[] }[];
  order_categories: Record<string, string>;
  prescription_options: { frequencies: Record<string, string>; timings: Record<string, string>; routes: Record<string, string>; forms: Record<string, string> };
  addenda: { id: number; kind: string; section: string | null; content: string; previous_content: string | null; reason: string | null; author: string | null; signed_at: string | null }[];
  history: { id: number; url?: string; number?: string | null; status?: string; started_at?: string | null; doctor?: string | null; chief_complaint?: string | null }[];
  services: { id: number; code: string | null; name: string; category: string | null }[];
  options: { diagnosis_statuses: Record<string, string>; note_types: Record<string, string>; narrative: string[] };
  dental: Parameters<typeof DentalPanel>[0]['dental'] | null;
  physio: Parameters<typeof PhysioAssessmentPanel>[0]['physio'] | null;
  care: Parameters<typeof CarePanel>[0]['care'] | null;
  links: Record<string, string>;
  can: { edit: boolean; finalize: boolean; addendum: boolean; amend: boolean; upload: boolean; prescribe: boolean; order: boolean };
};

const NARRATIVE_LABELS: Record<string, string> = { chief_complaint: 'Chief complaint', history: 'History', examination: 'Examination and findings', follow_up_note: 'Follow-up advice' };

export default function ConsultationWorkspace(props: Props) {
  const { encounter, patient, can } = props;
  const errors = usePage().props.errors as Record<string, string>;
  const open = !encounter.is_finalized && can.edit;
  const [signing, setSigning] = useState(false);
  const post = (url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) => router.post(url, data as never, { preserveScroll: true, onSuccess });
  const remove = (url: string) => router.delete(url, { preserveScroll: true });
  const problem = errors.consultation || errors.prescription || errors.order || Object.values(errors)[0];

  return (
    <ClinicPortalShell title={`${encounter.number ?? 'Consultation'} · ${patient.name}`}>
      <div className="mx-auto w-full max-w-[1280px] space-y-4">
        <Link
          href={props.links.patient}
          className="inline-flex min-h-9 items-center gap-1.5 rounded-sm text-[14px] font-semibold text-cx-text-2 hover:text-cx-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          {patient.name}
        </Link>

        <Card as="section" aria-label="Consultation" className="flex flex-wrap items-start justify-between gap-3 p-4 sm:p-5">
          <div className="min-w-0">
            <h1 className="flex flex-wrap items-center gap-2 text-[21px] font-bold leading-7 text-cx-text">
              {encounter.label} {encounter.number}
              {encounter.is_finalized ? <Tag tone="green">Signed</Tag> : <Tag tone="amber">Open</Tag>}
              {encounter.is_finalized && encounter.signature_intact === false && <Tag tone="rose">Record changed after signing</Tag>}
            </h1>
            <p className="mt-1 text-[13.5px] text-cx-text-2">
              {[
                patient.name,
                patient.uhid,
                [patient.age !== null ? `${patient.age} y` : null, patient.gender ? humanize(patient.gender) : null].filter(Boolean).join(' · '),
                encounter.doctor,
                encounter.branch,
                shortDate(encounter.started_at),
              ]
                .filter(Boolean)
                .join(' · ')}
            </p>
            {encounter.is_finalized && (
              <p className="text-[13px] text-cx-text-2">
                Signed {shortDate(encounter.finalized_at)}
                {encounter.finalized_by ? ` by ${encounter.finalized_by}` : ''}.
              </p>
            )}
          </div>
          {open && can.finalize && (
            <Button tone="primary" icon={FileSignature} onClick={() => setSigning(true)}>
              Sign consultation
            </Button>
          )}
        </Card>

        {problem && <Notice tone="danger">{problem}</Notice>}

        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
          <div className="min-w-0 space-y-4">
            <Narrative {...props} open={open} />
            {props.dental && <DentalPanel dental={props.dental} services={props.services} open={open} />}
            {props.physio && <PhysioAssessmentPanel physio={props.physio} open={open} />}
            {props.care && <CarePanel care={props.care} encounterId={encounter.id} open={open} />}
            <Diagnoses {...props} open={open} onRemove={remove} />
            <Prescriptions {...props} open={open} post={post} onRemove={remove} />
            <Investigations {...props} open={open} post={post} />
            <Procedures {...props} open={open} post={post} onRemove={remove} />
            <Notes {...props} open={open} post={post} />
            {encounter.is_finalized && <Addenda {...props} post={post} />}
          </div>
          <aside className="min-w-0 space-y-4" aria-label="Patient safety and history">
            <Safety patient={patient} />
            <History history={props.history} />
          </aside>
        </div>
      </div>

      <Modal
        open={signing}
        onOpenChange={setSigning}
        title="Sign this consultation"
        description="Signing locks the record. Draft prescriptions with medicines are signed with it; afterwards only addenda can be added."
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={() => setSigning(false)}>
              Cancel
            </Button>
            <Button tone="primary" onClick={() => post(props.links.finalize, {}, () => setSigning(false))}>
              Sign
            </Button>
          </>
        }
      >
        <p className="text-[14px] text-cx-text-2">You are signing as yourself. This cannot be undone.</p>
      </Modal>
    </ClinicPortalShell>
  );
}

/* ------------------------------------------------------------ building blocks */

type Tone = 'green' | 'amber' | 'rose' | 'blue' | 'slate';
const TAG: Record<Tone, string> = {
  green: 'bg-cx-green-bg text-cx-green-ink',
  amber: 'bg-cx-amber-bg text-cx-amber-ink',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
  blue: 'bg-cx-m-blue-bg text-cx-blue-ink',
  slate: 'bg-cx-slate-bg text-cx-slate-ink',
};

function Tag({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[12px] font-semibold', TAG[tone])}>{children}</span>;
}

function Block({ title, icon: Icon, aside, children }: { title: string; icon: typeof Pill; aside?: ReactNode; children: ReactNode }) {
  const id = `c-${title.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <Card as="section" aria-labelledby={id} className="p-4 sm:p-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id={id} className="flex items-center gap-2 text-[16px] font-semibold text-cx-text">
          <Icon className="h-[18px] w-[18px] text-cx-m-blue" aria-hidden="true" />
          {title}
        </h2>
        {aside}
      </div>
      {children}
    </Card>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <p className="text-[13.5px] text-cx-text-2">{children}</p>;
}

function RemoveButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="grid h-8 w-8 shrink-0 place-items-center rounded-[9px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
    >
      <Trash2 className="h-4 w-4" aria-hidden="true" />
    </button>
  );
}

const options = (map: Record<string, string>) => Object.entries(map).map(([value, label]) => ({ value, label }));

/* ------------------------------------------------------------ narrative */

/** The visit's narrative, autosaved (debounced) with the lock version; read-only once signed. */
function Narrative({ encounter, links, open }: Props & { open: boolean }) {
  const [values, setValues] = useState<Record<string, string>>(() => ({
    chief_complaint: encounter.narrative.chief_complaint ?? '',
    history: encounter.narrative.history ?? '',
    examination: encounter.narrative.examination ?? '',
    follow_up_note: encounter.narrative.follow_up_note ?? '',
    follow_up_on: encounter.follow_up_on ?? '',
  }));
  const version = useRef(encounter.lock_version);
  const pending = useRef<Record<string, string>>({});
  const timer = useRef<number | null>(null);
  const [status, setStatus] = useState<{ kind: 'idle' | 'saving' | 'saved' | 'conflict' | 'error'; text?: string }>({
    kind: encounter.last_autosaved_at ? 'saved' : 'idle',
    text: encounter.last_autosaved_at ? (shortDate(encounter.last_autosaved_at) ?? undefined) : undefined,
  });

  const flush = async () => {
    const changes = pending.current;
    pending.current = {};
    if (Object.keys(changes).length === 0) {
      return;
    }
    setStatus({ kind: 'saving' });
    try {
      const result = await requestJson<{ lock_version: number; saved_at: string | null }>('PATCH', links.autosave, {
        body: { lock_version: version.current, ...Object.fromEntries(Object.entries(changes).map(([key, value]) => [key, value === '' ? null : value])) },
      });
      version.current = result.lock_version;
      setStatus({ kind: 'saved', text: 'just now' });
    } catch (failure) {
      if (failure instanceof ClinicHttpError && failure.kind === 'conflict') {
        setStatus({ kind: 'conflict' });
      } else {
        const body = failure instanceof ClinicHttpError ? (failure.body as { message?: string } | null) : null;
        pending.current = { ...changes, ...pending.current };
        setStatus({ kind: 'error', text: body?.message ?? 'Not saved. Check the connection; your text is kept here.' });
      }
    }
  };

  const change = (field: string, value: string) => {
    setValues((current) => ({ ...current, [field]: value }));
    pending.current[field] = value;
    if (timer.current) {
      window.clearTimeout(timer.current);
    }
    timer.current = window.setTimeout(flush, 1200);
  };
  useEffect(() => () => void (timer.current && window.clearTimeout(timer.current)), []);

  if (!open) {
    return (
      <Block title="Clinical notes of the visit" icon={Stethoscope}>
        <dl className="space-y-3">
          {Object.keys(NARRATIVE_LABELS).map((field) => (
            <div key={field}>
              <dt className="text-[12.5px] font-semibold text-cx-text-2">{NARRATIVE_LABELS[field]}</dt>
              <dd className="whitespace-pre-line text-[14px] text-cx-text [overflow-wrap:anywhere]">{encounter.narrative[field] || <span className="text-cx-text-2">Not recorded</span>}</dd>
            </div>
          ))}
          {encounter.follow_up_on && (
            <div>
              <dt className="text-[12.5px] font-semibold text-cx-text-2">Follow-up on</dt>
              <dd className="text-[14px] text-cx-text">{shortDate(encounter.follow_up_on)}</dd>
            </div>
          )}
        </dl>
      </Block>
    );
  }

  return (
    <Block
      title="Clinical notes of the visit"
      icon={Stethoscope}
      aside={
        <p role="status" className={cn('text-[12.5px]', status.kind === 'error' || status.kind === 'conflict' ? 'font-semibold text-cx-rose-ink' : 'text-cx-text-2')}>
          {status.kind === 'saving' && 'Saving…'}
          {status.kind === 'saved' && `Saved ${status.text ?? ''}`}
          {status.kind === 'error' && status.text}
          {status.kind === 'conflict' && (
            <>
              Changed on another screen.{' '}
              <button type="button" className="underline" onClick={() => router.reload()}>
                Reload
              </button>
            </>
          )}
        </p>
      }
    >
      <div className="grid gap-3">
        {(['chief_complaint', 'history', 'examination'] as const).map((field) => (
          <Field key={field} label={NARRATIVE_LABELS[field]} htmlFor={`n-${field}`}>
            <Textarea id={`n-${field}`} value={values[field]} onChange={(event) => change(field, event.target.value)} onBlur={flush} maxLength={field === 'chief_complaint' ? 5000 : 20000} />
          </Field>
        ))}
        <div className="grid gap-3 sm:grid-cols-[200px_minmax(0,1fr)]">
          <Field label="Follow-up on" htmlFor="n-follow-up-on" hint="Creates a follow-up task when signed.">
            <Input id="n-follow-up-on" type="date" value={values.follow_up_on} onChange={(event) => change('follow_up_on', event.target.value)} onBlur={flush} />
          </Field>
          <Field label={NARRATIVE_LABELS.follow_up_note} htmlFor="n-follow-up-note">
            <Input id="n-follow-up-note" value={values.follow_up_note} maxLength={5000} onChange={(event) => change('follow_up_note', event.target.value)} onBlur={flush} />
          </Field>
        </div>
      </div>
    </Block>
  );
}

/* ------------------------------------------------------------ diagnoses */

function Diagnoses({ diagnoses, options: opts, links, open, onRemove }: Props & { open: boolean; onRemove: (url: string) => void }) {
  const [form, setForm] = useState({ description: '', code: '', status: Object.keys(opts.diagnosis_statuses)[0] ?? 'provisional', is_primary: false });

  return (
    <Block title="Diagnoses" icon={ClipboardList}>
      {diagnoses.length === 0 ? (
        <Quiet>No diagnosis recorded.</Quiet>
      ) : (
        <ul className="divide-y divide-cx-line">
          {diagnoses.map((diagnosis) => (
            <li key={diagnosis.id} className="flex items-start justify-between gap-3 py-2">
              <div className="min-w-0">
                <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">{diagnosis.description}</p>
                <p className="text-[12.5px] text-cx-text-2">
                  {[diagnosis.code, opts.diagnosis_statuses[diagnosis.status] ?? humanize(diagnosis.status), diagnosis.is_primary ? 'Primary' : null].filter(Boolean).join(' · ')}
                </p>
              </div>
              {open && <RemoveButton label={`Remove ${diagnosis.description}`} onClick={() => onRemove(`${links.diagnoses}/${diagnosis.id}`)} />}
            </li>
          ))}
        </ul>
      )}
      {open && (
        <form
          className="mt-3 grid gap-2 border-t border-cx-line pt-3 sm:grid-cols-[minmax(0,1fr)_120px_150px_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            router.post(links.diagnoses, { ...form, code: form.code || null } as never, { preserveScroll: true, onSuccess: () => setForm((current) => ({ ...current, description: '', code: '', is_primary: false })) });
          }}
        >
          <Field label="Diagnosis" htmlFor="dx-description">
            <Input id="dx-description" value={form.description} maxLength={500} onChange={(event) => setForm({ ...form, description: event.target.value })} />
          </Field>
          <Field label="Code" htmlFor="dx-code">
            <Input id="dx-code" value={form.code} maxLength={20} onChange={(event) => setForm({ ...form, code: event.target.value })} />
          </Field>
          <Field label="Status" htmlFor="dx-status">
            <Select id="dx-status" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} options={options(opts.diagnosis_statuses)} />
          </Field>
          <Button type="submit" icon={Plus} disabled={form.description.trim() === ''}>
            Add
          </Button>
          <div className="sm:col-span-4">
            <Checkbox checked={form.is_primary} onCheckedChange={(checked) => setForm({ ...form, is_primary: checked })} label="Primary diagnosis" />
          </div>
        </form>
      )}
    </Block>
  );
}

/* ------------------------------------------------------------ prescriptions */

const EMPTY_LINE = { drug_name: '', strength: '', dosage: '', frequency: '', duration: '', timing: '', instructions: '' };

function Prescriptions({
  prescriptions,
  prescription_options: rxOptions,
  links,
  can,
  open,
  post,
  onRemove,
}: Props & { open: boolean; post: (url: string, data?: Record<string, unknown>, onSuccess?: () => void) => void; onRemove: (url: string) => void }) {
  const draft = prescriptions.find((rx) => rx.status === 'draft');
  const [cancelling, setCancelling] = useState<Rx | null>(null);
  const [reason, setReason] = useState('');

  return (
    <Block
      title="Prescriptions"
      icon={Pill}
      aside={
        open && can.prescribe && !draft ? (
          <Button icon={Plus} onClick={() => post(links.prescription)}>
            Write prescription
          </Button>
        ) : undefined
      }
    >
      {prescriptions.length === 0 ? (
        <Quiet>No prescription in this consultation.</Quiet>
      ) : (
        <div className="space-y-3">
          {prescriptions.map((rx) => (
            <div key={rx.id} className="rounded-[12px] border border-cx-line p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex flex-wrap items-center gap-2 text-[14px] font-semibold text-cx-text">
                  {rx.number ? `Prescription ${rx.number}` : 'Draft prescription'}
                  {rx.status === 'draft' ? <Tag tone="amber">Draft</Tag> : rx.status === 'cancelled' ? <Tag tone="slate">Cancelled</Tag> : <Tag tone="green">Signed</Tag>}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {rx.status !== 'draft' && rx.can.print && (
                    <a
                      href={rx.links.print}
                      target="_blank"
                      rel="noopener"
                      className="inline-flex h-8 items-center gap-1.5 rounded-[9px] border border-cx-line px-2.5 text-[13px] font-semibold text-cx-blue-ink hover:bg-cx-m-blue-bg"
                    >
                      <Printer className="h-4 w-4" aria-hidden="true" />
                      Print
                    </a>
                  )}
                  {rx.can.cancel && (
                    <Button tone="ghost" size="sm" onClick={() => setCancelling(rx)}>
                      Cancel prescription
                    </Button>
                  )}
                </div>
              </div>
              {rx.items.length === 0 ? (
                <p className="mt-2 text-[13px] text-cx-text-2">No medicines yet.</p>
              ) : (
                <ul className="mt-2 divide-y divide-cx-line">
                  {rx.items.map((line) => (
                    <li key={line.id} className="flex items-start justify-between gap-3 py-1.5 text-[13.5px]">
                      <span className="min-w-0 [overflow-wrap:anywhere]">
                        <span className="font-semibold text-cx-text">{[line.drug_name, line.strength].filter(Boolean).join(' ')}</span> —{' '}
                        {[line.dosage, rxOptions.frequencies[line.frequency] ?? line.frequency, line.duration, line.timing ? (rxOptions.timings[line.timing] ?? line.timing) : null].filter(Boolean).join(' · ')}
                        {line.instructions && <span className="block text-[12.5px] text-cx-text-2">{line.instructions}</span>}
                      </span>
                      {rx.status === 'draft' && rx.can.edit && <RemoveButton label={`Remove ${line.drug_name}`} onClick={() => onRemove(`${rx.links.items}/${line.id}`)} />}
                    </li>
                  ))}
                </ul>
              )}
              {rx.cancellation_reason && <p className="mt-1 text-[13px] text-cx-rose-ink">Cancelled: {rx.cancellation_reason}</p>}
              {rx.status === 'draft' && rx.can.edit && (
                <>
                  <LineForm url={rx.links.items} rxOptions={rxOptions} />
                  <AllergyCheck rx={rx} post={post} />
                  <div className="mt-3 flex justify-end">
                    <Button tone="primary" disabled={rx.items.length === 0 || (rx.allergies?.review_required === true && !rx.allergies.reviewed)} onClick={() => post(rx.links.finalize)}>
                      Sign prescription
                    </Button>
                  </div>
                </>
              )}
              {rx.status !== 'draft' && rx.allergies && (
                <p className="mt-2 text-[12.5px] text-cx-text-2">
                  Allergies when signed: {rx.allergies.status === 'documented' ? rx.allergies.allergies.map((a) => a.label).join(', ') : rx.allergies.status === 'none_known' ? 'none known' : 'not recorded'}
                  {rx.allergies.reviewed_at ? ` · reviewed ${shortDate(rx.allergies.reviewed_at)}` : ''}
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      <Modal
        open={cancelling !== null}
        onOpenChange={(next) => !next && setCancelling(null)}
        title="Cancel prescription"
        description="It stays on the record, marked cancelled with your reason."
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={() => setCancelling(null)}>
              Keep it
            </Button>
            <Button
              tone="danger"
              disabled={reason.trim() === ''}
              onClick={() =>
                cancelling &&
                post(cancelling.links.cancel, { reason }, () => {
                  setCancelling(null);
                  setReason('');
                })
              }
            >
              Cancel prescription
            </Button>
          </>
        }
      >
        <Field label="Reason" htmlFor="rx-cancel-reason" required>
          <Input id="rx-cancel-reason" value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
    </Block>
  );
}

/** The allergy step before signing: the current documented list, and the clinician's acknowledgement (stored on the server). */
function AllergyCheck({ rx, post }: { rx: Rx; post: (url: string, data?: Record<string, unknown>) => void }) {
  const state = rx.allergies;
  const [confirmed, setConfirmed] = useState(false);
  if (!state) {
    return null;
  }
  if (state.status !== 'documented') {
    return (
      <p className={cn('mt-3 rounded-[10px] px-3 py-2 text-[13px]', state.status === 'not_recorded' ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-slate-bg text-cx-slate-ink')}>
        {state.status === 'not_recorded' ? 'Allergies have not been recorded for this patient. That is not the same as none — ask before prescribing.' : 'No known allergies recorded.'}
      </p>
    );
  }

  return (
    <div className={cn('mt-3 rounded-[10px] border px-3 py-2.5', state.reviewed ? 'border-cx-line bg-cx-slate-bg/60' : 'border-[#F2C4C1] bg-cx-rose-bg/70')}>
      <p className="flex items-center gap-2 text-[13.5px] font-semibold text-cx-rose-ink">
        <ShieldAlert className="h-4 w-4" aria-hidden="true" />
        Documented allergies
      </p>
      <ul className="mt-1 space-y-0.5 text-[13.5px] text-cx-text">
        {state.allergies.map((allergy) => (
          <li key={allergy.label} className="[overflow-wrap:anywhere]">
            <span className="font-semibold">{allergy.label}</span>
            {[allergy.severity ? humanize(allergy.severity) : null, allergy.reaction].filter(Boolean).length > 0 && (
              <span className="text-cx-text-2"> — {[allergy.severity ? humanize(allergy.severity) : null, allergy.reaction].filter(Boolean).join(' · ')}</span>
            )}
          </li>
        ))}
      </ul>
      {state.reviewed ? (
        <p className="mt-1.5 flex items-center gap-1.5 text-[12.5px] font-semibold text-cx-green-ink">
          <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
          Reviewed {shortDate(state.reviewed_at ?? null) ?? ''}
        </p>
      ) : (
        <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
          <Checkbox checked={confirmed} onCheckedChange={setConfirmed} label="I have reviewed these allergies against the medicines prescribed." />
          <Button size="sm" disabled={!confirmed} onClick={() => post(rx.links.allergy_review, { fingerprint: state.fingerprint })}>
            Record review
          </Button>
        </div>
      )}
    </div>
  );
}

function LineForm({ url, rxOptions }: { url: string; rxOptions: Props['prescription_options'] }) {
  const [line, setLine] = useState(EMPTY_LINE);
  const set = (field: keyof typeof EMPTY_LINE) => (event: { target: { value: string } }) => setLine({ ...line, [field]: event.target.value });
  const ready = line.drug_name.trim() && line.dosage.trim() && line.frequency && line.duration.trim();

  return (
    <form
      className="mt-3 grid gap-2 border-t border-cx-line pt-3 sm:grid-cols-6"
      onSubmit={(event) => {
        event.preventDefault();
        router.post(url, Object.fromEntries(Object.entries(line).map(([key, value]) => [key, value === '' ? null : value])) as never, { preserveScroll: true, onSuccess: () => setLine(EMPTY_LINE) });
      }}
    >
      <div className="sm:col-span-2">
        <Field label="Medicine" htmlFor={`rx-name-${url}`}>
          <Input id={`rx-name-${url}`} value={line.drug_name} maxLength={150} onChange={set('drug_name')} />
        </Field>
      </div>
      <Field label="Strength" htmlFor={`rx-strength-${url}`}>
        <Input id={`rx-strength-${url}`} value={line.strength} maxLength={40} onChange={set('strength')} />
      </Field>
      <Field label="Dose" htmlFor={`rx-dose-${url}`}>
        <Input id={`rx-dose-${url}`} value={line.dosage} maxLength={60} onChange={set('dosage')} />
      </Field>
      <Field label="Frequency" htmlFor={`rx-freq-${url}`}>
        <Select id={`rx-freq-${url}`} value={line.frequency} onChange={set('frequency')} placeholder="Choose" options={options(rxOptions.frequencies)} />
      </Field>
      <Field label="Duration" htmlFor={`rx-dur-${url}`}>
        <Input id={`rx-dur-${url}`} value={line.duration} maxLength={40} onChange={set('duration')} />
      </Field>
      <Field label="When" htmlFor={`rx-timing-${url}`}>
        <Select id={`rx-timing-${url}`} value={line.timing} onChange={set('timing')} placeholder="Any time" options={options(rxOptions.timings)} />
      </Field>
      <div className="sm:col-span-4">
        <Field label="Instructions" htmlFor={`rx-instr-${url}`}>
          <Input id={`rx-instr-${url}`} value={line.instructions} maxLength={255} onChange={set('instructions')} />
        </Field>
      </div>
      <div className="flex items-end">
        <Button type="submit" icon={Plus} disabled={!ready} className="w-full">
          Add
        </Button>
      </div>
    </form>
  );
}

/* ------------------------------------------------------------ investigations */

const ORDER_STATUS: Record<string, [string, Tone]> = {
  ordered: ['Ordered', 'slate'],
  collected: ['Sample collected', 'blue'],
  resulted: ['Result in', 'amber'],
  reviewed: ['Reviewed', 'green'],
  cancelled: ['Cancelled', 'slate'],
};

function Investigations({ orders, order_categories: categories, links, can, open, post }: Props & { open: boolean; post: (url: string, data?: Record<string, unknown>, onSuccess?: () => void) => void }) {
  const [item, setItem] = useState({ item_name: '', category: Object.keys(categories)[0] ?? 'lab', body_site: '' });
  const [priority, setPriority] = useState('routine');

  return (
    <Block title="Investigations" icon={FlaskConical}>
      {orders.length === 0 ? (
        <Quiet>Nothing ordered in this consultation.</Quiet>
      ) : (
        <ul className="divide-y divide-cx-line">
          {orders.flatMap((order) =>
            order.items.map((line) => {
              const [label, tone] = ORDER_STATUS[line.status] ?? [humanize(line.status), 'slate'];

              return (
                <li key={line.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span className="min-w-0 text-[14px] text-cx-text [overflow-wrap:anywhere]">
                    {order.url ? (
                      <Link href={order.url} className="font-semibold text-cx-blue-ink underline-offset-2 hover:underline">
                        {line.item_name}
                      </Link>
                    ) : (
                      <span className="font-semibold">{line.item_name}</span>
                    )}
                    <span className="text-[12.5px] text-cx-text-2">
                      {' '}
                      · {[categories[line.category] ?? line.category, line.body_site, order.number, order.priority !== 'routine' ? humanize(order.priority) : null].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                  <Tag tone={tone}>{label}</Tag>
                </li>
              );
            }),
          )}
        </ul>
      )}
      {open && can.order && (
        <form
          className="mt-3 grid gap-2 border-t border-cx-line pt-3 sm:grid-cols-[minmax(0,1fr)_150px_140px_120px_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            post(links.orders, { priority, items: [{ item_name: item.item_name, category: item.category, body_site: item.body_site || null }] }, () => setItem({ ...item, item_name: '', body_site: '' }));
          }}
        >
          <Field label="Test or imaging" htmlFor="ord-name">
            <Input id="ord-name" value={item.item_name} maxLength={150} onChange={(event) => setItem({ ...item, item_name: event.target.value })} />
          </Field>
          <Field label="Type" htmlFor="ord-cat">
            <Select id="ord-cat" value={item.category} onChange={(event) => setItem({ ...item, category: event.target.value })} options={options(categories)} />
          </Field>
          <Field label="Site" htmlFor="ord-site">
            <Input id="ord-site" value={item.body_site} maxLength={100} onChange={(event) => setItem({ ...item, body_site: event.target.value })} />
          </Field>
          <Field label="Priority" htmlFor="ord-priority">
            <Select
              id="ord-priority"
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
              options={[
                { value: 'routine', label: 'Routine' },
                { value: 'urgent', label: 'Urgent' },
                { value: 'stat', label: 'Immediate' },
              ]}
            />
          </Field>
          <Button type="submit" icon={Plus} disabled={item.item_name.trim() === ''}>
            Order
          </Button>
        </form>
      )}
    </Block>
  );
}

/* ------------------------------------------------------------ procedures */

function Procedures({
  procedures,
  services,
  links,
  open,
  post,
  onRemove,
}: Props & { open: boolean; post: (url: string, data?: Record<string, unknown>, onSuccess?: () => void) => void; onRemove: (url: string) => void }) {
  const [service, setService] = useState('');
  const [quantity, setQuantity] = useState('1');

  return (
    <Block title="Procedures done" icon={FileText}>
      {procedures.length === 0 ? (
        <Quiet>No procedure recorded in this consultation.</Quiet>
      ) : (
        <ul className="divide-y divide-cx-line">
          {procedures.map((procedure) => (
            <li key={procedure.id} className="flex items-start justify-between gap-3 py-2 text-[14px]">
              <span className="min-w-0 [overflow-wrap:anywhere]">
                <span className="font-semibold text-cx-text">{procedure.name}</span>
                <span className="text-[12.5px] text-cx-text-2"> · {[procedure.code, `× ${procedure.quantity}`].filter(Boolean).join(' · ')}</span>
                {procedure.notes && <span className="block text-[12.5px] text-cx-text-2">{procedure.notes}</span>}
              </span>
              {open && <RemoveButton label={`Remove ${procedure.name}`} onClick={() => onRemove(`${links.procedures}/${procedure.id}`)} />}
            </li>
          ))}
        </ul>
      )}
      {open && services.length > 0 && (
        <form
          className="mt-3 grid gap-2 border-t border-cx-line pt-3 sm:grid-cols-[minmax(0,1fr)_90px_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            post(links.procedures, { clinical_procedure_id: Number(service), quantity: Number(quantity) || 1 }, () => setService(''));
          }}
        >
          <Field label="Service" htmlFor="proc-service">
            <Select
              id="proc-service"
              value={service}
              onChange={(event) => setService(event.target.value)}
              placeholder="Choose a service"
              options={services.map((item) => ({ value: String(item.id), label: [item.name, item.code].filter(Boolean).join(' · ') }))}
            />
          </Field>
          <Field label="Qty" htmlFor="proc-qty">
            <Input id="proc-qty" type="number" min={1} max={50} value={quantity} onChange={(event) => setQuantity(event.target.value)} />
          </Field>
          <Button type="submit" icon={Plus} disabled={service === ''}>
            Add
          </Button>
        </form>
      )}
    </Block>
  );
}

/* ------------------------------------------------------------ notes and addenda */

function Notes({ notes, options: opts, links, open, post }: Props & { open: boolean; post: (url: string, data?: Record<string, unknown>, onSuccess?: () => void) => void }) {
  const [form, setForm] = useState({ note_type: Object.keys(opts.note_types)[0] ?? 'progress', content: '' });

  return (
    <Block title="Clinical notes" icon={NotebookPen}>
      {notes.length === 0 ? (
        <Quiet>No notes.</Quiet>
      ) : (
        <ul className="space-y-2.5">
          {notes.map((note) => (
            <li key={note.id} className="rounded-[10px] bg-cx-slate-bg/60 px-3 py-2">
              <p className="text-[12.5px] font-semibold text-cx-text-2">{[note.type_label, note.author, shortDate(note.created_at)].filter(Boolean).join(' · ')}</p>
              <p className="mt-0.5 whitespace-pre-line text-[14px] text-cx-text [overflow-wrap:anywhere]">{note.text}</p>
            </li>
          ))}
        </ul>
      )}
      {open && (
        <form
          className="mt-3 grid gap-2 border-t border-cx-line pt-3"
          onSubmit={(event) => {
            event.preventDefault();
            post(links.notes, form, () => setForm({ ...form, content: '' }));
          }}
        >
          <div className="grid gap-2 sm:grid-cols-[200px_minmax(0,1fr)]">
            <Field label="Type" htmlFor="note-type">
              <Select id="note-type" value={form.note_type} onChange={(event) => setForm({ ...form, note_type: event.target.value })} options={options(opts.note_types)} />
            </Field>
            <Field label="Note" htmlFor="note-content">
              <Textarea id="note-content" value={form.content} maxLength={10000} onChange={(event) => setForm({ ...form, content: event.target.value })} />
            </Field>
          </div>
          <div className="flex justify-end">
            <Button type="submit" icon={Plus} disabled={form.content.trim() === ''}>
              Add note
            </Button>
          </div>
        </form>
      )}
    </Block>
  );
}

/** After signing: additions (anyone allowed) and the signer's own amendments (with a reason) — the original stays visible. */
function Addenda({ addenda, links, can, post }: Props & { post: (url: string, data?: Record<string, unknown>, onSuccess?: () => void) => void }) {
  const [form, setForm] = useState({ kind: 'addendum', section: '', content: '', reason: '' });

  return (
    <Block title="Addenda and amendments" icon={FileSignature}>
      {addenda.length === 0 ? (
        <Quiet>None added since signing.</Quiet>
      ) : (
        <ul className="space-y-2.5">
          {addenda.map((entry) => (
            <li key={entry.id} className="rounded-[10px] border border-cx-line px-3 py-2">
              <p className="text-[12.5px] font-semibold text-cx-text-2">
                {[entry.kind === 'amendment' ? `Amendment to ${NARRATIVE_LABELS[entry.section ?? ''] ?? entry.section}` : 'Addendum', entry.author, shortDate(entry.signed_at)].filter(Boolean).join(' · ')}
              </p>
              <p className="mt-0.5 whitespace-pre-line text-[14px] text-cx-text [overflow-wrap:anywhere]">{entry.content}</p>
              {entry.reason && <p className="text-[12.5px] text-cx-text-2">Reason: {entry.reason}</p>}
              {entry.previous_content && <p className="mt-1 text-[12.5px] text-cx-text-2 line-through [overflow-wrap:anywhere]">{entry.previous_content}</p>}
            </li>
          ))}
        </ul>
      )}
      {can.addendum && (
        <form
          className="mt-3 grid gap-2 border-t border-cx-line pt-3"
          onSubmit={(event) => {
            event.preventDefault();
            post(links.addenda, { kind: form.kind, content: form.content, section: form.kind === 'amendment' ? form.section : null, reason: form.kind === 'amendment' ? form.reason : null }, () =>
              setForm({ kind: 'addendum', section: '', content: '', reason: '' }),
            );
          }}
        >
          {can.amend && (
            <Field label="Kind" htmlFor="add-kind">
              <Select
                id="add-kind"
                value={form.kind}
                onChange={(event) => setForm({ ...form, kind: event.target.value })}
                options={[
                  { value: 'addendum', label: 'Addendum' },
                  { value: 'amendment', label: 'Amend a section (your own signed record)' },
                ]}
              />
            </Field>
          )}
          {form.kind === 'amendment' && (
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Section" htmlFor="add-section" required>
                <Select
                  id="add-section"
                  value={form.section}
                  onChange={(event) => setForm({ ...form, section: event.target.value })}
                  placeholder="Choose"
                  options={Object.entries(NARRATIVE_LABELS).map(([value, label]) => ({ value, label }))}
                />
              </Field>
              <Field label="Reason" htmlFor="add-reason" required>
                <Input id="add-reason" value={form.reason} maxLength={500} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
              </Field>
            </div>
          )}
          <Field label={form.kind === 'amendment' ? 'Corrected text' : 'Addendum'} htmlFor="add-content">
            <Textarea id="add-content" value={form.content} maxLength={20000} onChange={(event) => setForm({ ...form, content: event.target.value })} />
          </Field>
          <div className="flex justify-end">
            <Button type="submit" disabled={form.content.trim() === '' || (form.kind === 'amendment' && (!form.section || form.reason.trim() === ''))}>
              Sign and add
            </Button>
          </div>
        </form>
      )}
    </Block>
  );
}

/* ------------------------------------------------------------ side */

function Safety({ patient }: { patient: Props['patient'] }) {
  const allergies = patient.allergies;

  return (
    <Card as="section" aria-labelledby="c-safety" className="space-y-3 p-4">
      <h2 id="c-safety" className="flex items-center gap-2 text-[15px] font-semibold text-cx-text">
        <ShieldAlert className="h-4 w-4 text-cx-rose-ink" aria-hidden="true" />
        Before you prescribe
      </h2>
      <div>
        <p className="text-[12.5px] font-semibold text-cx-text-2">Allergies</p>
        {allergies.status === 'documented' ? (
          <ul className="mt-1 flex flex-wrap gap-1.5">
            {allergies.allergies.map((allergy) => (
              <li key={allergy.label} className="rounded-[8px] bg-cx-rose-bg px-2 py-0.5 text-[12.5px] font-semibold text-cx-rose-ink [overflow-wrap:anywhere]">
                {allergy.label}
              </li>
            ))}
          </ul>
        ) : (
          <p className={cn('text-[13px]', allergies.status === 'not_recorded' ? 'font-semibold text-cx-amber-ink' : 'text-cx-text')}>
            {allergies.status === 'not_recorded' ? 'Not recorded — ask the patient.' : 'No known allergies.'}
          </p>
        )}
      </div>
      <div>
        <p className="text-[12.5px] font-semibold text-cx-text-2">Medical conditions</p>
        {patient.conditions.length === 0 ? (
          <p className="text-[13px] text-cx-text-2">None recorded.</p>
        ) : (
          <ul className="mt-1 flex flex-wrap gap-1.5">
            {patient.conditions.map((condition) => (
              <li key={condition} className="rounded-[8px] bg-cx-slate-bg px-2 py-0.5 text-[12.5px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                <HeartPulse className="mr-1 inline h-3 w-3 text-cx-rose-ink" aria-hidden="true" />
                {condition}
              </li>
            ))}
          </ul>
        )}
      </div>
      {patient.alerts.filter((alert) => alert.type !== 'allergy' && alert.type !== 'medical_condition').length > 0 && (
        <div>
          <p className="text-[12.5px] font-semibold text-cx-text-2">Safety alerts</p>
          <ul className="mt-1 space-y-1">
            {patient.alerts
              .filter((alert) => alert.type !== 'allergy' && alert.type !== 'medical_condition')
              .map((alert) => (
                <li
                  key={alert.description}
                  className={cn('flex gap-1.5 text-[13px] [overflow-wrap:anywhere]', alert.severity === 'high' || alert.severity === 'critical' ? 'font-semibold text-cx-rose-ink' : 'text-cx-text')}
                >
                  <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                  {alert.description}
                </li>
              ))}
          </ul>
        </div>
      )}
    </Card>
  );
}

function History({ history }: { history: Props['history'] }) {
  return (
    <Card as="section" aria-labelledby="c-history" className="p-4">
      <h2 id="c-history" className="text-[15px] font-semibold text-cx-text">
        Earlier consultations
      </h2>
      {history.length === 0 ? (
        <p className="mt-1 text-[13px] text-cx-text-2">This is the first.</p>
      ) : (
        <ul className="mt-2 divide-y divide-cx-line">
          {history.map((row) => (
            <li key={row.id} className="py-2 text-[13px]">
              {row.url ? (
                <Link href={row.url} className="font-semibold text-cx-blue-ink hover:underline">
                  {row.number ?? 'Consultation'}
                </Link>
              ) : (
                <span className="font-semibold text-cx-text">{row.number ?? 'Consultation'}</span>
              )}
              <span className="text-cx-text-2"> · {[shortDate(row.started_at ?? null), row.doctor].filter(Boolean).join(' · ')}</span>
              {row.chief_complaint && <p className="line-clamp-2 text-cx-text-2 [overflow-wrap:anywhere]">{row.chief_complaint}</p>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
