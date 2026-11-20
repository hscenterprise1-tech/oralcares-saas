import { useCallback, useEffect, useMemo, useState } from 'react';
import { Activity, CheckCircle2, PackageCheck, Plus, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicHttpError, requestJson } from '@/clinic/http';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { FOCUS, IconTile, Meter, Pill } from '@/pages/ClinicPortal/Patients/components/patient360/v2/ui';
import type { PhysioCare } from './PhysioCare';

const PAIN = Array.from({ length: 11 }, (_, index) => index);

function newKey(): string {
  return `pss-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function problemOf(caught: unknown, fallback: string): string {
  const body = caught instanceof ClinicHttpError ? (caught.body as { message?: string } | null) : null;

  return body?.message ?? fallback;
}

/**
 * Physiotherapy run from the front desk, for therapists who do not sign in:
 * which programme today's session belongs to, who gave it, which package paid
 * for it, and a short note of what was done (pain before and after). A
 * patient with no programme gets one started here. The server checks every
 * step again (`ReceptionController::confirmPhysioSession`, `startPhysioProgramme`).
 */
export function PhysioDeskDialog({ patientId, patientName, onClose, onDone }: { patientId: number; patientName: string; onClose: () => void; onDone: (message: string) => void }) {
  const [care, setCare] = useState<PhysioCare | null | undefined>(undefined);
  const [loadProblem, setLoadProblem] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoadProblem(null);
    try {
      const response = await requestJson<{ physio: PhysioCare | null }>('GET', route('clinic.reception.physio-summary', patientId));
      setCare(response.physio);
    } catch (caught) {
      setLoadProblem(problemOf(caught, 'The physiotherapy record could not be opened.'));
    }
  }, [patientId]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <Modal open onOpenChange={(open) => !open && onClose()} title="Physiotherapy session" description={patientName} size="md">
      {loadProblem ? (
        <p className="rounded-xl bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">{loadProblem}</p>
      ) : care === undefined ? (
        <p className="py-6 text-center text-[13px] text-cx-text-2">Opening the record…</p>
      ) : care === null ? (
        <p className="text-[13.5px] text-cx-text-2">This clinic does not practise physiotherapy.</p>
      ) : care.treatments.length === 0 ? (
        <StartProgramme care={care} onStarted={setCare} />
      ) : (
        <SessionForm care={care} onStarted={setCare} onDone={onDone} onClose={onClose} />
      )}
    </Modal>
  );
}

/** A patient with no programme: name it, how many sessions, and the therapist. */
function StartProgramme({ care, onStarted, onCancel }: { care: PhysioCare; onStarted: (care: PhysioCare) => void; onCancel?: () => void }) {
  const [title, setTitle] = useState('');
  const [sessions, setSessions] = useState('');
  const [therapist, setTherapist] = useState(care.therapists.length === 1 ? String(care.therapists[0].id) : '');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  if (!care.start_url) {
    return <p className="text-[13.5px] text-cx-text-2">No physiotherapy programme is under way, and you cannot start one.</p>;
  }

  const start = async () => {
    setBusy(true);
    setProblem(null);
    try {
      const response = await requestJson<{ physio: PhysioCare }>('POST', care.start_url!, {
        body: { title: title.trim(), sessions_planned: sessions === '' ? null : Number(sessions), therapist_id: Number(therapist) },
      });
      onStarted(response.physio);
    } catch (caught) {
      setProblem(problemOf(caught, 'The programme could not be started.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-2xl bg-gradient-to-r from-[#EEF6FF] to-[#F4F1FF] p-4">
        <IconTile icon={Activity} tone="violet" />
        <div>
          <p className="text-[14px] font-semibold text-cx-nav-ink">Start a physiotherapy programme</p>
          <p className="text-[12.5px] text-cx-text-2">Sessions are counted against it from today.</p>
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
        <Field label="Programme" htmlFor="physio-title" required>
          <Input id="physio-title" value={title} maxLength={150} placeholder="e.g. Knee rehabilitation" onChange={(event) => setTitle(event.target.value)} />
        </Field>
        <Field label="Sessions" htmlFor="physio-sessions">
          <Input id="physio-sessions" type="number" min={1} max={500} value={sessions} placeholder="Not set" onChange={(event) => setSessions(event.target.value)} />
        </Field>
      </div>
      <Field label="Therapist" htmlFor="physio-therapist" required>
        <Select id="physio-therapist" value={therapist} placeholder="Choose the therapist" onChange={(event) => setTherapist(event.target.value)} options={care.therapists.map((item) => ({ value: String(item.id), label: item.name }))} />
      </Field>
      {problem && <p className="rounded-xl bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">{problem}</p>}
      <div className="flex justify-end gap-2">
        {onCancel && (
          <Button tone="ghost" onClick={onCancel} disabled={busy}>
            Back
          </Button>
        )}
        <Button tone="primary" loading={busy} disabled={title.trim() === '' || !therapist} onClick={start}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Start programme
        </Button>
      </div>
    </div>
  );
}

function SessionForm({ care, onStarted, onDone, onClose }: { care: PhysioCare; onStarted: (care: PhysioCare) => void; onDone: (message: string) => void; onClose: () => void }) {
  const [adding, setAdding] = useState(false);
  const [treatmentId, setTreatmentId] = useState(String(care.treatments[0].id));
  const [therapist, setTherapist] = useState(care.confirm?.therapist_id ? String(care.confirm.therapist_id) : care.therapists.length === 1 ? String(care.therapists[0].id) : '');
  const [packageId, setPackageId] = useState('');
  const [done, setDone] = useState('');
  const [response, setResponse] = useState('');
  const [painBefore, setPainBefore] = useState<number | null>(null);
  const [painAfter, setPainAfter] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [key] = useState(newKey);

  const treatment = useMemo(() => care.treatments.find((item) => String(item.id) === treatmentId) ?? care.treatments[0], [care.treatments, treatmentId]);

  useEffect(() => {
    setPackageId(treatment.packages.length === 1 ? String(treatment.packages[0].id) : '');
  }, [treatment]);

  if (adding) {
    return <StartProgramme care={care} onCancel={() => setAdding(false)} onStarted={(next) => { onStarted(next); setAdding(false); }} />;
  }

  const confirm = care.confirm;
  const therapists = care.therapists.some((item) => item.id === confirm?.therapist_id) || !confirm?.therapist_id
    ? care.therapists
    : [{ id: confirm.therapist_id, name: confirm.therapist ?? 'Queued therapist' }, ...care.therapists];

  const submit = async () => {
    if (!confirm) {
      return;
    }
    setBusy(true);
    setProblem(null);
    try {
      const result = await requestJson<{ message: string }>('POST', confirm.url, {
        body: {
          patient_treatment_id: treatment.id,
          package_id: packageId === '' ? null : Number(packageId),
          therapist_id: therapist === '' ? null : Number(therapist),
          treatment_performed: done.trim() || null,
          patient_response: response.trim() || null,
          pain_before: painBefore,
          pain_after: painAfter,
          confirm: true,
          idempotency_key: key,
        },
      });
      onDone(result.message);
      onClose();
    } catch (caught) {
      setProblem(problemOf(caught, 'The session could not be recorded. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      {/* Which programme */}
      <div role="radiogroup" aria-label="Programme" className="grid gap-2">
        {care.treatments.map((item) => {
          const selected = item.id === treatment.id;

          return (
            <button
              key={item.id}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => setTreatmentId(String(item.id))}
              className={cn('rounded-2xl border p-3 text-left transition-colors', FOCUS, selected ? 'border-[#6B48DE]/50 bg-[#F4F1FF]' : 'border-[#E6EBF1] bg-white hover:border-[#C9D3E1]')}
            >
              <div className="flex items-center justify-between gap-3">
                <span className="min-w-0 text-[14px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{item.title}</span>
                {selected && <CheckCircle2 className="h-4 w-4 shrink-0 text-[#6B48DE]" aria-hidden="true" />}
              </div>
              <div className="mt-2">
                {item.sessions_planned ? (
                  <Meter done={item.sessions_completed} total={item.sessions_planned} label={`${item.sessions_completed} of ${item.sessions_planned} sessions done`} />
                ) : (
                  <p className="text-[12.5px] text-cx-text-2">{item.sessions_completed} sessions done</p>
                )}
              </div>
            </button>
          );
        })}
        {care.start_url && (
          <button type="button" onClick={() => setAdding(true)} className={cn('inline-flex items-center gap-1.5 self-start rounded-full px-2 py-1 text-[12.5px] font-semibold text-[#1769AA] hover:underline', FOCUS)}>
            <Plus className="h-3.5 w-3.5" aria-hidden="true" /> Start another programme
          </button>
        )}
      </div>

      {!confirm ? (
        <p className="rounded-xl bg-cx-amber-bg px-3 py-2 text-[13px] font-semibold text-cx-amber-ink">The patient is not in today's queue here, so a session cannot be recorded. Check them in first.</p>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Given by" htmlFor="physio-given-by" required>
              <Select id="physio-given-by" value={therapist} placeholder="Choose the therapist" onChange={(event) => setTherapist(event.target.value)} options={therapists.map((item) => ({ value: String(item.id), label: item.name }))} />
            </Field>
            <Field label="Paid from" htmlFor="physio-package">
              <Select
                id="physio-package"
                value={packageId}
                onChange={(event) => setPackageId(event.target.value)}
                options={[{ value: '', label: 'No package' }, ...treatment.packages.map((pkg) => ({ value: String(pkg.id), label: `${pkg.name} · ${pkg.remaining} left` }))]}
              />
            </Field>
          </div>
          {treatment.packages.length === 0 && (
            <Pill tone="amber">
              <PackageCheck className="h-3 w-3" aria-hidden="true" /> No package with sessions left: the session is billed as usual
            </Pill>
          )}

          <div className="grid gap-3 sm:grid-cols-2">
            <PainScale label="Pain before" value={painBefore} onChange={setPainBefore} />
            <PainScale label="Pain after" value={painAfter} onChange={setPainAfter} />
          </div>
          <Field label="What was done (optional)" htmlFor="physio-done">
            <Input id="physio-done" value={done} maxLength={2000} placeholder="e.g. IFT 15 min, knee strengthening" onChange={(event) => setDone(event.target.value)} />
          </Field>
          <Field label="How the patient felt (optional)" htmlFor="physio-response">
            <Input id="physio-response" value={response} maxLength={2000} onChange={(event) => setResponse(event.target.value)} />
          </Field>

          {problem && <p className="rounded-xl bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">{problem}</p>}
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="inline-flex items-center gap-1.5 text-[12px] text-cx-text-2">
              <UserRound className="h-3.5 w-3.5" aria-hidden="true" /> Recorded by you, for the therapist
            </p>
            <div className="flex gap-2">
              <Button tone="ghost" onClick={onClose} disabled={busy}>
                Cancel
              </Button>
              <Button tone="primary" loading={busy} disabled={!therapist} onClick={submit}>
                <CheckCircle2 className="h-4 w-4" aria-hidden="true" /> Session done
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function PainScale({ label, value, onChange }: { label: string; value: number | null; onChange: (value: number | null) => void }) {
  return (
    <div>
      <p className="mb-1.5 text-[12.5px] font-semibold text-cx-nav-ink">
        {label} <span className="font-normal text-cx-text-2">{value === null ? '(not asked)' : `${value}/10`}</span>
      </p>
      <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1">
        {PAIN.map((score) => (
          <button
            key={score}
            type="button"
            role="radio"
            aria-checked={value === score}
            onClick={() => onChange(value === score ? null : score)}
            className={cn(
              'h-8 w-8 rounded-full text-[12px] font-semibold tabular-nums transition-colors',
              FOCUS,
              value === score ? (score >= 7 ? 'bg-[#E5484D] text-white' : score >= 4 ? 'bg-[#E9821A] text-white' : 'bg-[#12A877] text-white') : 'bg-[#F1F5F9] text-cx-text-2 hover:bg-[#E2E8F0]',
            )}
          >
            {score}
          </button>
        ))}
      </div>
    </div>
  );
}
