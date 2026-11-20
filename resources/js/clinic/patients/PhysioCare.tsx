import { useEffect, useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DsButton, DsDialog, TONE } from '@/clinic/ds';
import { ClinicHttpError, requestJson } from '@/clinic/http';
import { formatMoney } from '@/clinic/ui/visuals';

/** `PhysioCareSummary`: a patient's physiotherapy at a glance, read-only. Null outside physiotherapy clinics. */
export type PhysioCare = {
  treatments: {
    id: number;
    title: string;
    status: string;
    /** What the treatment plans (null when it sets no number) — not what is paid for. */
    sessions_planned: number | null;
    /** Completed sessions of this treatment (reversed ones are not counted). */
    sessions_completed: number;
    /** Packages of this treatment that can still pay for a session, from the package ledger. */
    packages: { id: number; name: string; sessions_total: number; remaining: number; valid_until: string | null }[];
  }[];
  /** Owed on live bills; null for members who do not see billing. */
  amount_due: number | null;
  /** Where the desk confirms today's session; null when the patient is not queued here or the member may not. */
  confirm: { url: string; therapist: string | null; therapist_id: number | null } | null;
  /** Therapists who give physiotherapy at this branch today (for the desk); empty for members who may not run sessions. */
  therapists: { id: number; name: string }[];
  /** Where the desk starts a physiotherapy programme; null for members who may not. */
  start_url: string | null;
};

/**
 * The summary itself: each open treatment with sessions completed (of the
 * planned number, when there is one) and, separately, what each package has
 * left — two different quantities, never added together — then what the
 * patient owes. Nothing is inferred: what the server does not send is not shown.
 */
export function PhysioCareList({ care, dense = false }: { care: PhysioCare; dense?: boolean }) {
  if (care.treatments.length === 0) {
    return (
      <div className="space-y-2">
        <p className="text-[13.5px] leading-5 text-cx-text-2">No physiotherapy treatment under way.</p>
        <AmountDue amount={care.amount_due} />
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <ul className={cn('space-y-2.5', dense && 'space-y-2')}>
        {care.treatments.map((treatment) => (
          <li key={treatment.id} className="rounded-xl border border-[#E6EBF1] bg-white px-3 py-2.5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <p className="min-w-0 text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{treatment.title}</p>
              <p className="text-[13px] leading-5 text-cx-text-2">
                <span className="font-semibold text-cx-text">{treatment.sessions_completed}</span>
                {treatment.sessions_planned !== null ? ` of ${treatment.sessions_planned} sessions done` : ` session${treatment.sessions_completed === 1 ? '' : 's'} done`}
              </p>
            </div>
            {treatment.packages.length === 0 ? (
              <p className="mt-1 text-[12.5px] leading-5 text-cx-text-2">No package with sessions left.</p>
            ) : (
              <ul className="mt-1.5 flex flex-wrap gap-1.5">
                {treatment.packages.map((pkg) => (
                  <li key={pkg.id} className={cn('inline-flex min-h-6 items-center rounded-full px-2.5 text-[12px] font-semibold', TONE.teal.soft, TONE.teal.ink)}>
                    {pkg.name}: {pkg.remaining} of {pkg.sessions_total} left{pkg.valid_until ? ` · until ${pkg.valid_until}` : ''}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ul>
      <AmountDue amount={care.amount_due} />
    </div>
  );
}

function AmountDue({ amount }: { amount: number | null }) {
  if (amount === null) {
    return null;
  }

  return (
    <p className="text-[13px] leading-5 text-cx-text-2">
      Amount due: <span className={cn('font-semibold', amount > 0 ? 'text-[#B45309]' : 'text-cx-text')}>{formatMoney(amount)}</span>
    </p>
  );
}

/**
 * Confirming at the desk that today's session took place: which treatment,
 * and which of its packages paid for it (or none — then nothing is taken
 * from a package). An explicit confirmation; it records no clinical notes and
 * signs nothing. A request key makes a double tap a single confirmation.
 */
export function ConfirmSessionDialog({ care, open, onOpenChange, onDone }: { care: PhysioCare; open: boolean; onOpenChange: (open: boolean) => void; onDone: (message: string) => void }) {
  const [treatmentId, setTreatmentId] = useState<string>('');
  const [packageId, setPackageId] = useState<string>('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [key, setKey] = useState('');

  useEffect(() => {
    if (open) {
      setTreatmentId(care.treatments.length === 1 ? String(care.treatments[0].id) : '');
      setPackageId('');
      setError(null);
      setKey(`pss-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`);
    }
  }, [open, care.treatments]);

  const treatment = care.treatments.find((entry) => String(entry.id) === treatmentId) ?? null;
  const confirm = care.confirm;

  const submit = async () => {
    if (!confirm || !treatment) {
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const response = await requestJson<{ message: string }>('POST', confirm.url, {
        body: { patient_treatment_id: treatment.id, package_id: packageId === '' ? null : Number(packageId), confirm: true, idempotency_key: key },
      });
      onDone(response.message);
      onOpenChange(false);
    } catch (caught) {
      const body = caught instanceof ClinicHttpError ? (caught.body as { message?: string } | null) : null;
      setError(body?.message ?? 'The session could not be confirmed. Try again.');
    } finally {
      setSaving(false);
    }
  };

  const fieldClass = 'h-11 w-full rounded-md border border-[#D8E0EA] bg-white px-3 text-[14px] text-cx-text focus:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan';

  return (
    <DsDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Confirm today's session"
      description={confirm?.therapist ? `Given by ${confirm.therapist}. This records that the session took place; it adds no clinical notes.` : 'This records that the session took place; it adds no clinical notes.'}
      footer={
        <>
          <DsButton variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </DsButton>
          <DsButton variant="primary" icon={CheckCircle2} loading={saving} disabled={!treatment || !confirm?.therapist} onClick={submit}>
            Confirm session
          </DsButton>
        </>
      }
    >
      <div className="space-y-4">
        {!confirm?.therapist && <p className="rounded-xl bg-[#FFF7E6] px-3 py-2.5 text-[13px] text-[#92400E]">Choose the therapist on the queue entry first.</p>}
        <label className="block space-y-1.5">
          <span className="text-[13px] font-semibold text-cx-text">Treatment</span>
          <select className={fieldClass} value={treatmentId} onChange={(event) => { setTreatmentId(event.target.value); setPackageId(''); }}>
            <option value="">Choose the treatment</option>
            {care.treatments.map((entry) => (
              <option key={entry.id} value={entry.id}>
                {entry.title}
              </option>
            ))}
          </select>
        </label>
        <label className="block space-y-1.5">
          <span className="text-[13px] font-semibold text-cx-text">Paid from</span>
          <select className={fieldClass} value={packageId} onChange={(event) => setPackageId(event.target.value)} disabled={!treatment}>
            <option value="">No package — bill as usual</option>
            {(treatment?.packages ?? []).map((pkg) => (
              <option key={pkg.id} value={pkg.id}>
                {pkg.name} ({pkg.remaining} left)
              </option>
            ))}
          </select>
          <span className="block text-[12px] text-cx-text-2">Only this treatment&apos;s packages can pay for its session.</span>
        </label>
        {error && (
          <p role="alert" className="rounded-xl bg-[#FDECEC] px-3 py-2.5 text-[13px] text-[#B42318]">
            {error}
          </p>
        )}
      </div>
    </DsDialog>
  );
}
