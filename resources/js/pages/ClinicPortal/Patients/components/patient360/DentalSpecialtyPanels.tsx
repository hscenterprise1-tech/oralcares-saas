import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/clinic/ui/Button';
import { Field, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { shortDate } from '../format';

export interface EndoRecord {
  id: number; tooth_number: number; status: string; stage: string; pulp_status: string | null; periapical_status: string | null; diagnosis: string | null;
  canals: { name: string; working_length?: number | string }[]; obturation_technique: string | null; obturation_material: string | null; obturated_on: string | null;
  temporary_restoration: string | null; permanent_restoration: string | null; review_on: string | null; notes: string | null; by: string | null; started_at: string | null;
}

export interface ImplantRecordRow {
  id: number; tooth_number: number; status: string; system: string | null; diameter_mm: number | null; length_mm: number | null; lot_number: string | null; placed_on: string | null;
  insertion_torque_ncm: number | null; integration_status: string | null; abutment: string | null; abutment_on: string | null; crown_status: string | null; crown_on: string | null;
  review_on: string | null; complications: string | null; notes: string | null; by: string | null;
}

export interface OrthoCaseRow {
  id: number; status: string; arches: string; appliance_label: string; bracket_system: string | null; phase: string; diagnosis: string | null; estimated_months: number | null;
  bracketed_teeth: number[]; notes: string | null; started_on: string | null; months_in: number | null; by: string | null;
  visits: { id: number; date: string | null; phase: string | null; upper_wire: string | null; lower_wire: string | null; adjustments: string | null; elastics: string | null; next_visit_weeks: number | null; by: string | null }[];
}

export interface LabRow {
  id?: number; number?: string | null; restoration?: string; laboratory?: string | null; lab?: string | null; status?: string; status_label?: string; due?: string | null; teeth?: number[];
  shade?: string | null; material?: string | null; notes?: string | null; cancellation_reason?: string | null; steps?: { step: string; url: string }[];
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card';
const ENDO_STAGES = ['diagnosis', 'access', 'cleaning_shaping', 'obturation', 'restoration', 'review'];
const ENDO_LABEL: Record<string, string> = { diagnosis: 'Diagnosis', access: 'Access opened', cleaning_shaping: 'Cleaning and shaping', obturation: 'Filling the canals', restoration: 'Restoration', review: 'Review' };
const TONE: Record<string, string> = {
  planned: 'bg-cx-slate-bg text-cx-slate-ink', in_progress: 'bg-cx-blue-bg text-cx-blue-ink', completed: 'bg-cx-green-bg text-cx-green-ink', abandoned: 'bg-cx-rose-bg text-cx-rose-ink',
  placed: 'bg-cx-blue-bg text-cx-blue-ink', integrating: 'bg-cx-amber-bg text-cx-amber-ink', restored: 'bg-cx-green-bg text-cx-green-ink', failed: 'bg-cx-rose-bg text-cx-rose-ink', removed: 'bg-cx-slate-bg text-cx-slate-ink',
  active: 'bg-cx-blue-bg text-cx-blue-ink', requested: 'bg-cx-amber-bg text-cx-amber-ink', in_production: 'bg-cx-amber-bg text-cx-amber-ink', delivered: 'bg-cx-blue-bg text-cx-blue-ink', fitted: 'bg-cx-green-bg text-cx-green-ink', cancelled: 'bg-cx-rose-bg text-cx-rose-ink',
};
const STEP_LABEL: Record<string, string> = { send: 'Send to lab', start: 'Lab started', receive: 'Received from lab', fit: 'Fitted', complete: 'Complete', remake: 'Remake', cancel: 'Cancel' };

function Section({ title, hint, children, action }: { title: string; hint?: string; children: ReactNode; action?: ReactNode }) {
  return (
    <section className={SURFACE} aria-label={title}>
      <header className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div><h3 className="text-[15px] font-semibold text-cx-nav-ink">{title}</h3>{hint && <p className="text-[12.5px] text-cx-text-3">{hint}</p>}</div>
        {action}
      </header>
      {children}
    </section>
  );
}

function Chip({ value, label }: { value: string; label?: string }) {
  return <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', TONE[value] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{label ?? humanize(value)}</span>;
}

function Line({ label, children }: { label: string; children: ReactNode }) {
  return children ? <p className="text-[12.5px] text-cx-text-2"><span className="text-cx-text-3">{label}: </span>{children}</p> : null;
}

function Bar({ steps, current }: { steps: string[]; current: number }) {
  return (
    <ol className="mt-3 flex gap-1" aria-label="Progress">
      {steps.map((s, i) => (
        <li key={s} className="flex-1" title={ENDO_LABEL[s] ?? humanize(s)}>
          <div className={cn('h-1.5 rounded-full', i <= current ? 'bg-[#1769AA]' : 'bg-cx-line')} />
          <p className={cn('mt-1 hidden text-[10.5px] sm:block', i === current ? 'font-semibold text-cx-nav-ink' : 'text-cx-text-3')}>{ENDO_LABEL[s] ?? humanize(s)}</p>
        </li>
      ))}
    </ol>
  );
}

/** Root canal, implants and braces as the patient's record (read here; they are written in a visit). */
export function SpecialtyPanels({ endodontic, implants, orthodontic, visitUrl }: { endodontic?: EndoRecord[]; implants?: ImplantRecordRow[]; orthodontic?: OrthoCaseRow | null; visitUrl: string | null }) {
  const hint = visitUrl ? undefined : 'Written in a dental visit.';
  const open = visitUrl ? <Link href={visitUrl} className="text-[13px] font-semibold text-[#1769AA] hover:underline">Record in today's visit</Link> : undefined;

  return (
    <>
      {endodontic && endodontic.length > 0 && (
        <Section title="Root canal treatment" hint={hint} action={open}>
          <ul className="space-y-3">
            {endodontic.map((r) => (
              <li key={r.id} className="rounded-2xl border border-cx-line/80 p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <span className="grid h-9 min-w-9 place-items-center rounded-xl bg-cx-blue-bg px-1 text-[13px] font-extrabold text-cx-blue-ink">{r.tooth_number}</span>
                  <div className="min-w-0 flex-1"><p className="text-[14px] font-semibold text-cx-nav-ink">{r.diagnosis || 'Root canal'}</p><p className="text-[12px] text-cx-text-3">Started {shortDate(r.started_at)}{r.by ? ` · ${r.by}` : ''}{r.review_on ? ` · review ${shortDate(r.review_on)}` : ''}</p></div>
                  <Chip value={r.status} />
                </div>
                <Bar steps={ENDO_STAGES} current={Math.max(0, ENDO_STAGES.indexOf(r.stage))} />
                <div className="mt-3 space-y-0.5">
                  <Line label="Pulp">{r.pulp_status ? humanize(r.pulp_status) : null}</Line>
                  <Line label="Canals">{r.canals.length > 0 ? r.canals.map((c) => `${c.name}${c.working_length ? ` ${c.working_length} mm` : ''}`).join(', ') : null}</Line>
                  <Line label="Filled">{[r.obturation_technique, r.obturation_material, r.obturated_on && shortDate(r.obturated_on)].filter(Boolean).join(' · ') || null}</Line>
                  <Line label="Restoration">{[r.temporary_restoration && `temporary: ${r.temporary_restoration}`, r.permanent_restoration && `permanent: ${r.permanent_restoration}`].filter(Boolean).join(' · ') || null}</Line>
                  <Line label="Notes">{r.notes}</Line>
                </div>
              </li>
            ))}
          </ul>
        </Section>
      )}

      {implants && implants.length > 0 && (
        <Section title="Dental implants" hint={hint} action={open}>
          <ul className="space-y-3">
            {implants.map((r) => (
              <li key={r.id} className="rounded-2xl border border-cx-line/80 p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <span className="grid h-9 min-w-9 place-items-center rounded-xl bg-cx-blue-bg px-1 text-[13px] font-extrabold text-cx-blue-ink">{r.tooth_number}</span>
                  <div className="min-w-0 flex-1"><p className="text-[14px] font-semibold text-cx-nav-ink">{r.system || 'Implant'}{r.diameter_mm && r.length_mm ? ` · ${r.diameter_mm} × ${r.length_mm} mm` : ''}</p><p className="text-[12px] text-cx-text-3">{r.placed_on ? `Placed ${shortDate(r.placed_on)}` : 'Not placed yet'}{r.by ? ` · ${r.by}` : ''}{r.review_on ? ` · review ${shortDate(r.review_on)}` : ''}</p></div>
                  <Chip value={r.status} />
                </div>
                <div className="mt-3 space-y-0.5">
                  <Line label="Healing">{r.integration_status ? humanize(r.integration_status) : null}</Line>
                  <Line label="Abutment">{[r.abutment, r.abutment_on && shortDate(r.abutment_on)].filter(Boolean).join(' · ') || null}</Line>
                  <Line label="Crown">{r.crown_status && r.crown_status !== 'none' ? `${humanize(r.crown_status)}${r.crown_on ? ` · ${shortDate(r.crown_on)}` : ''}` : null}</Line>
                  <Line label="Lot / torque">{[r.lot_number, r.insertion_torque_ncm && `${r.insertion_torque_ncm} Ncm`].filter(Boolean).join(' · ') || null}</Line>
                  <Line label="Complications">{r.complications}</Line>
                  <Line label="Notes">{r.notes}</Line>
                </div>
              </li>
            ))}
          </ul>
        </Section>
      )}

      {orthodontic && (
        <Section title="Braces and aligners" hint={hint} action={open}>
          <div className="rounded-2xl border border-cx-line/80 p-4">
            <div className="flex flex-wrap items-center gap-3"><p className="min-w-0 flex-1 text-[14px] font-semibold text-cx-nav-ink">{orthodontic.appliance_label} · {humanize(orthodontic.arches)} arch{orthodontic.arches === 'both' ? 'es' : ''}</p><Chip value={orthodontic.status} /></div>
            <div className="mt-2 space-y-0.5">
              <Line label="Phase">{humanize(orthodontic.phase)}</Line>
              <Line label="Started">{orthodontic.started_on ? `${shortDate(orthodontic.started_on)}${orthodontic.months_in != null ? ` · ${orthodontic.months_in} months in` : ''}${orthodontic.estimated_months ? ` of about ${orthodontic.estimated_months}` : ''}` : null}</Line>
              <Line label="Brackets">{orthodontic.bracket_system}</Line>
              <Line label="Teeth with brackets">{orthodontic.bracketed_teeth.length > 0 ? orthodontic.bracketed_teeth.join(', ') : null}</Line>
              <Line label="Diagnosis">{orthodontic.diagnosis}</Line>
              <Line label="Notes">{orthodontic.notes}</Line>
            </div>
          </div>
          {orthodontic.visits.length > 0 && (
            <ul className="mt-4 divide-y divide-cx-line/60 rounded-xl border border-cx-line/80">
              {orthodontic.visits.map((v) => (
                <li key={v.id} className="px-4 py-3">
                  <p className="text-[13px] font-semibold text-cx-nav-ink">{shortDate(v.date)}{v.phase && <span className="ml-2 text-[12px] font-normal text-cx-text-3">{humanize(v.phase)}</span>}{v.by && <span className="ml-2 text-[12px] font-normal text-cx-text-3">{v.by}</span>}</p>
                  <Line label="Wires">{[v.upper_wire && `upper ${v.upper_wire}`, v.lower_wire && `lower ${v.lower_wire}`].filter(Boolean).join(' · ') || null}</Line>
                  <Line label="Done">{v.adjustments}</Line>
                  <Line label="Elastics">{v.elastics}</Line>
                  <Line label="Next visit">{v.next_visit_weeks ? `in ${v.next_visit_weeks} weeks` : null}</Line>
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}
    </>
  );
}

/** A lab case's next steps. The desk sends and receives; clinicians fit, complete, remake and cancel (the server decides which are offered). */
export function LabSteps({ order, onChanged }: { order: LabRow; onChanged: () => void }) {
  const [cancelling, setCancelling] = useState(false);
  const [busy, setBusy] = useState(false);
  const steps = order.steps ?? [];
  if (steps.length === 0) {
    return null;
  }
  const go = (url: string, data: Record<string, unknown> = {}, done?: () => void) =>
    router.post(url, data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { done?.(); onChanged(); } });
  const cancel = steps.find((s) => s.step === 'cancel');

  return (
    <div className="mt-2 flex flex-wrap gap-2">
      {steps.map((s) => (
        <Button key={s.step} size="sm" tone={s.step === 'cancel' ? 'danger' : 'secondary'} disabled={busy} onClick={() => (s.step === 'cancel' ? setCancelling(true) : go(s.url))}>
          {STEP_LABEL[s.step] ?? humanize(s.step)}
        </Button>
      ))}
      {cancelling && cancel && <CancelDialog busy={busy} onClose={() => setCancelling(false)} onConfirm={(reason) => go(cancel.url, { reason }, () => setCancelling(false))} />}
    </div>
  );
}

function CancelDialog({ busy, onClose, onConfirm }: { busy: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title="Cancel this lab case?"
      footer={<><Button onClick={onClose} disabled={busy}>Keep it</Button><Button tone="danger" loading={busy} disabled={reason.trim().length < 3} onClick={() => onConfirm(reason.trim())}>Cancel the case</Button></>}
    >
      <Field label="Why" htmlFor="p360-lab-why" required><Textarea id="p360-lab-why" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} /></Field>
    </Modal>
  );
}
