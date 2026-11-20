import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, HeartPulse, NotebookText, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicHttpError, requestJson } from '@/clinic/http';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { humanize } from '@/clinic/ui/visuals';
import { shortDate } from '../format';
import type { ConditionEditor, MedicalCondition, Patient360Context, SafetyEditor } from './types';

/*
 * The header's safety strip: one compact row, three kinds kept apart.
 *
 * - Medical conditions (clinical roles): the patient's medical history —
 *   diabetes, hypertension… — with how serious and who recorded it.
 * - Safety alerts (everyone): precautions staff recorded, most severe first.
 *   Colour only for high and critical; the rest read as ordinary text.
 * - Notes (clinical roles): a short preview; the full text on request.
 *
 * Allergies are deliberately not shown on Patient 360 (product decision,
 * `docs/design-review/patient-360/product-ux-audit.md`); their records are
 * untouched. Each part shows a little and opens the rest — nothing is cut
 * off for good.
 */

const CONDITIONS_SHOWN = 4;
const ALERTS_SHOWN = 2;
const NOTES_PREVIEW = 120;

const SEVERE = ['critical', 'high'];

/** A dot for how serious a condition is; nothing for low or unknown. */
function SeverityDot({ severity }: { severity: string | null }) {
  if (!severity || severity === 'low') {
    return null;
  }

  return <span aria-hidden="true" className={cn('h-1.5 w-1.5 shrink-0 rounded-full', SEVERE.includes(severity) ? 'bg-cx-rose-ink' : 'bg-cx-m-orange')} />;
}

function SeverityTag({ severity }: { severity: string | null }) {
  if (!severity) {
    return null;
  }

  return (
    <span
      className={cn(
        'inline-flex h-6 shrink-0 items-center rounded-full px-2 text-[12px] font-semibold',
        SEVERE.includes(severity) ? 'bg-cx-rose-bg text-cx-rose-ink' : severity === 'low' ? 'bg-cx-slate-bg text-cx-slate-ink' : 'bg-cx-amber-bg text-cx-amber-ink',
      )}
    >
      {humanize(severity === 'medium' ? 'moderate' : severity)}
    </span>
  );
}

/** "Reported at registration · 3 Oct 2026" / "Recorded by Dr. Priya Nair · 22 Oct 2026". */
function provenance(condition: MedicalCondition): string {
  const how = condition.source === 'registration' ? 'Reported at registration' : condition.source === 'clinical' ? `Recorded by ${condition.recorded_by ?? 'clinical staff'}` : 'From earlier records';

  return [how, shortDate(condition.recorded_on)].filter(Boolean).join(' · ');
}

function LinkButton({ onClick, children, label }: { onClick: () => void; children: ReactNode; label?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="inline-flex min-h-7 items-center justify-center rounded-lg px-2 py-0.5 text-[12px] font-bold text-blue-600 hover:bg-blue-50 hover:text-blue-800 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
    >
      {children}
    </button>
  );
}

/** One part of the strip: a small labelled heading with its actions, then the content. */
function Part({ icon: Icon, tone, label, count, actions, children }: { icon: typeof HeartPulse; tone: string; label: string; count?: number; actions?: ReactNode; children: ReactNode }) {
  return (
    <div className="min-w-0 px-4 py-3.5 sm:px-5">
      <div className="flex min-h-7 items-center justify-between gap-2">
        <p className="flex min-w-0 items-center gap-2 text-[12px] font-bold uppercase tracking-wider text-slate-700">
          <Icon className={cn('h-4 w-4 shrink-0', tone)} aria-hidden="true" />
          {label}
          {count !== undefined && count > 0 && <span className="rounded-full border border-slate-200 bg-slate-100 px-2 text-[11px] font-bold tabular-nums text-slate-700">{count}</span>}
        </p>
        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
      </div>
      <div className="mt-2 min-w-0">{children}</div>
    </div>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <p className="text-[13px] leading-5 text-slate-400">{children}</p>;
}

export function SafetyStrip({ context, editor, safetyEditor, readOnly }: { context: Patient360Context; editor: ConditionEditor | null; safetyEditor: SafetyEditor | null; readOnly: boolean }) {
  const [open, setOpen] = useState<'conditions' | 'alerts' | 'notes' | 'manage' | 'manage-alerts' | 'edit-notes' | null>(null);
  const canEditSafety = safetyEditor !== null && !readOnly;
  const conditions = context.medical_conditions;
  const alerts = context.alerts;
  const notes = context.medical_notes;
  const canManage = editor !== null && !readOnly;
  const parts = context.clinical ? 3 : 1;

  return (
    <>
      {context.allergies && context.allergies.length > 0 && (
        <div role="alert" className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-rose-900 shadow-sm">
          <span className="flex items-center gap-1.5 font-bold uppercase tracking-wide text-[12px] text-rose-700">
            <AlertTriangle className="h-4 w-4 text-rose-600" aria-hidden="true" />
            Allergies:
          </span>
          <div className="flex flex-wrap items-center gap-1.5">
            {context.allergies.map((allergy) => (
              <span key={allergy} className="inline-flex items-center rounded-lg bg-white px-2.5 py-0.5 text-[12px] font-bold text-rose-700 border border-rose-200 shadow-xs">
                {allergy}
              </span>
            ))}
          </div>
        </div>
      )}

      <div className={cn('grid divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-sm', parts === 3 && 'xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1.15fr)_minmax(0,1fr)] xl:divide-x xl:divide-y-0')}>
        {context.clinical && (
          <Part
            icon={HeartPulse}
            tone="text-rose-600"
            label="Medical conditions"
            count={conditions.length}
            actions={
              <>
                {conditions.length > 0 && <LinkButton onClick={() => setOpen('conditions')}>View all</LinkButton>}
                {canManage && <LinkButton onClick={() => setOpen('manage')}>Manage</LinkButton>}
              </>
            }
          >
            {conditions.length === 0 ? (
              <Quiet>No medical conditions recorded.</Quiet>
            ) : (
              <ul className="flex flex-wrap items-center gap-1.5">
                {conditions.slice(0, CONDITIONS_SHOWN).map((condition) => (
                  <li
                    key={condition.label}
                    className="inline-flex min-h-7 max-w-full items-center gap-1.5 rounded-xl border border-slate-200/80 bg-slate-50/70 px-2.5 py-0.5 text-[12.5px] font-semibold text-slate-800 [overflow-wrap:anywhere]"
                  >
                    <SeverityDot severity={condition.severity} />
                    {condition.label}
                  </li>
                ))}
                {conditions.length > CONDITIONS_SHOWN && (
                  <li>
                    <LinkButton onClick={() => setOpen('conditions')} label={`Show all ${conditions.length} medical conditions`}>
                      +{conditions.length - CONDITIONS_SHOWN} more
                    </LinkButton>
                  </li>
                )}
              </ul>
            )}
          </Part>
        )}

        <Part
          icon={AlertTriangle}
          tone="text-cx-amber-ink"
          label="Safety alerts"
          count={alerts.length}
          actions={
            <>
              {alerts.length > ALERTS_SHOWN && <LinkButton onClick={() => setOpen('alerts')}>View all</LinkButton>}
              {canEditSafety && <LinkButton onClick={() => setOpen('manage-alerts')}>Manage</LinkButton>}
            </>
          }
        >
          {alerts.length === 0 ? (
            <Quiet>No safety alerts.</Quiet>
          ) : (
            <ul className="space-y-1">
              {alerts.slice(0, ALERTS_SHOWN).map((alert) => (
                <AlertLine key={alert.text} text={alert.text} severity={alert.severity} />
              ))}
              {alerts.length > ALERTS_SHOWN && (
                <li>
                  <LinkButton onClick={() => setOpen('alerts')} label={`Show all ${alerts.length} safety alerts`}>
                    +{alerts.length - ALERTS_SHOWN} more
                  </LinkButton>
                </li>
              )}
            </ul>
          )}
        </Part>

        {context.clinical && (
          <Part
            icon={NotebookText}
            tone="text-cx-m-blue"
            label="Notes"
            actions={
              <>
                {notes && notes.length > NOTES_PREVIEW && <LinkButton onClick={() => setOpen('notes')}>View more</LinkButton>}
                {canEditSafety && <LinkButton onClick={() => setOpen('edit-notes')}>Edit</LinkButton>}
              </>
            }
          >
            {notes ? <p className="line-clamp-2 text-[13px] leading-5 text-cx-text-2 [overflow-wrap:anywhere]">{notes}</p> : <Quiet>No notes recorded.</Quiet>}
          </Part>
        )}
      </div>

      <Modal
        open={open === 'conditions'}
        onOpenChange={(next) => !next && setOpen(null)}
        title="Medical conditions"
        description="The patient's medical history, most serious first."
        size="md"
        footer={
          <>
            {canManage && (
              <Button tone="ghost" onClick={() => setOpen('manage')}>
                Manage conditions
              </Button>
            )}
            <Button tone="primary" onClick={() => setOpen(null)}>
              Close
            </Button>
          </>
        }
      >
        <ul className="divide-y divide-cx-line">
          {conditions.map((condition) => (
            <li key={condition.label} className="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
              <div className="min-w-0">
                <p className="text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{condition.label}</p>
                <p className="text-[12.5px] leading-[18px] text-cx-text-2">{provenance(condition)}</p>
              </div>
              <SeverityTag severity={condition.severity} />
            </li>
          ))}
        </ul>
      </Modal>

      <Modal
        open={open === 'alerts'}
        onOpenChange={(next) => !next && setOpen(null)}
        title="Safety alerts"
        description="Precautions recorded for this patient, most serious first."
        size="md"
        footer={
          <Button tone="primary" onClick={() => setOpen(null)}>
            Close
          </Button>
        }
      >
        <ul className="space-y-1.5">
          {alerts.map((alert) => (
            <AlertLine key={alert.text} text={alert.text} severity={alert.severity} withTag />
          ))}
        </ul>
      </Modal>

      <Modal
        open={open === 'notes'}
        onOpenChange={(next) => !next && setOpen(null)}
        title="Notes"
        description={
          context.medical_notes_updated_on
            ? `Medical history notes, last updated ${shortDate(context.medical_notes_updated_on)}${context.medical_notes_updated_by ? ` by ${context.medical_notes_updated_by}` : ''}.`
            : 'Medical history notes.'
        }
        size="md"
        footer={
          <Button tone="primary" onClick={() => setOpen(null)}>
            Close
          </Button>
        }
      >
        <p className="whitespace-pre-line text-[14px] leading-6 text-cx-text [overflow-wrap:anywhere]">{notes}</p>
      </Modal>

      {canManage && open === 'manage' && <ConditionEditorDialog editor={editor} conditions={conditions} onClose={() => setOpen(null)} />}
      {canEditSafety && open === 'manage-alerts' && <AlertsEditorDialog editor={safetyEditor} alerts={alerts} onClose={() => setOpen(null)} />}
      {canEditSafety && open === 'edit-notes' && <NotesEditorDialog editor={safetyEditor} notes={notes} onClose={() => setOpen(null)} />}
    </>
  );
}

/**
 * Add a safety alert, or remove one (it is resolved — who and when — and
 * stays in the history). Only alerts staff recorded are listed; the copies
 * kept of allergies and conditions change with their records.
 */
function AlertsEditorDialog({ editor, alerts, onClose }: { editor: SafetyEditor; alerts: Patient360Context['alerts']; onClose: () => void }) {
  const [text, setText] = useState('');
  const [severity, setSeverity] = useState('moderate');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const options = {
    preserveScroll: true,
    onStart: () => setBusy(true),
    onFinish: () => setBusy(false),
    onError: (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Not saved.'),
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Manage safety alerts"
      description="Precautions staff must notice before acting. A removed alert is kept in the patient's history."
      size="md"
      footer={
        <Button tone="primary" onClick={onClose}>
          Done
        </Button>
      }
    >
      <div className="space-y-4">
        {error && <Notice tone="danger">{error}</Notice>}
        {alerts.length === 0 ? (
          <Quiet>No safety alerts.</Quiet>
        ) : (
          <ul className="divide-y divide-cx-line rounded-[12px] border border-cx-line">
            {alerts.map((alert) => (
              <li key={alert.id} className="flex items-start gap-2 px-3 py-2">
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">{alert.text}</p>
                  <p className="text-[12.5px] text-cx-text-2">
                    {[alert.recorded_by ? `Added by ${alert.recorded_by}` : null, shortDate(alert.recorded_on)].filter(Boolean).join(' · ') || 'Added before authorship was recorded'}
                  </p>
                </div>
                <SeverityTag severity={alert.severity} />
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => router.post(editor.resolve.replace('__ALERT__', String(alert.id)), {}, options)}
                  aria-label={`Remove ${alert.text}`}
                  className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan disabled:opacity-50"
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}
        <form
          className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            router.post(editor.alerts, { description: text.trim(), severity }, { ...options, onSuccess: () => setText('') });
          }}
        >
          <Field label="New alert" htmlFor="alert-text" hint="An actionable precaution, e.g. “Bleeding risk: on warfarin”. Preferences belong in notes.">
            <Input id="alert-text" value={text} maxLength={255} onChange={(event) => setText(event.target.value)} />
          </Field>
          <Field label="How serious" htmlFor="alert-severity">
            <Select
              id="alert-severity"
              value={severity}
              onChange={(event) => setSeverity(event.target.value)}
              options={editor.severities.map((value) => ({
                value,
                label: humanize(value),
              }))}
            />
          </Field>
          <Button type="submit" icon={Plus} disabled={text.trim() === '' || busy}>
            Add
          </Button>
        </form>
      </div>
    </Modal>
  );
}

/** Edit the medical-history notes. Who saved and when is recorded; the previous text is kept in the timeline. */
function NotesEditorDialog({ editor, notes, onClose }: { editor: SafetyEditor; notes: string | null; onClose: () => void }) {
  const [text, setText] = useState(notes ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Edit notes"
      description="Clinical notes on the patient's medical history. The earlier version is kept."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            tone="primary"
            loading={busy}
            loadingLabel="Saving…"
            disabled={busy || text === (notes ?? '')}
            onClick={() =>
              router.put(
                editor.notes,
                { notes: text },
                {
                  preserveScroll: true,
                  onStart: () => setBusy(true),
                  onFinish: () => setBusy(false),
                  onError: (errors) => setError(Object.values(errors as Record<string, string>)[0] ?? 'Not saved.'),
                  onSuccess: onClose,
                },
              )
            }
          >
            Save notes
          </Button>
        </>
      }
    >
      {error && <Notice tone="danger">{error}</Notice>}
      <Field label="Notes" htmlFor="medical-notes">
        <Textarea id="medical-notes" value={text} maxLength={5000} onChange={(event) => setText(event.target.value)} className="min-h-[160px]" />
      </Field>
    </Modal>
  );
}

/** One alert: high and critical stand out in rose; anything else reads as ordinary text with a quiet marker. */
function AlertLine({ text, severity, withTag = false }: { text: string; severity: string | null; withTag?: boolean }) {
  const severe = severity !== null && SEVERE.includes(severity);

  return (
    <li className={cn('flex items-start gap-2 rounded-[8px] text-[13px] leading-5 [overflow-wrap:anywhere]', severe ? 'bg-cx-rose-bg/70 px-2 py-1 font-semibold text-cx-rose-ink' : 'px-0.5 text-cx-text')}>
      <span aria-hidden="true" className={cn('mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full', severe ? 'bg-cx-rose-ink' : severity === 'moderate' || severity === 'medium' ? 'bg-cx-m-orange' : 'bg-cx-text-3')} />
      <span className="min-w-0 flex-1">
        {severe && !withTag && <span className="sr-only">{humanize(severity)}: </span>}
        {text}
      </span>
      {withTag && <SeverityTag severity={severity} />}
    </li>
  );
}

type Draft = { key: string | null; label: string; severity: string | null };

/**
 * Manage the patient's medical conditions. Sends the whole active list to
 * `PUT …/clinical-entries/condition` (`PatientClinicalEntryController`):
 * a condition left out is resolved and kept in the history, never deleted.
 * Options are the clinic's own condition catalogue; "Other" records a typed
 * name, as the server allows.
 */
function ConditionEditorDialog({ editor, conditions, onClose }: { editor: ConditionEditor; conditions: MedicalCondition[]; onClose: () => void }) {
  const [drafts, setDrafts] = useState<Draft[]>(() =>
    conditions.map((condition) => ({
      key: condition.key,
      label: condition.label,
      severity: condition.severity,
    })),
  );
  const [choice, setChoice] = useState('');
  const [other, setOther] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const taken = new Set(drafts.map((draft) => draft.key ?? draft.label.toLowerCase()));
  const available = editor.options.filter((option) => !taken.has(option.key));

  const addOption = () => {
    const option = editor.options.find((item) => item.key === choice);
    if (option) {
      setDrafts((list) => [
        ...list,
        {
          key: option.key,
          label: option.name,
          severity: option.severity,
        },
      ]);
      setChoice('');
    }
  };
  const addOther = () => {
    const label = other.trim();
    if (label === '' || taken.has(label.toLowerCase())) {
      return;
    }
    setDrafts((list) => [...list, { key: null, label, severity: null }]);
    setOther('');
  };

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      await requestJson('PUT', editor.url, {
        body: {
          entries: drafts.map((draft) => ({
            key: draft.key,
            label: draft.label,
            severity: draft.severity,
          })),
        },
      });
      router.reload({ only: ['context'] });
      onClose();
    } catch (failure) {
      const body =
        failure instanceof ClinicHttpError
          ? (failure.body as {
              message?: string;
              errors?: Record<string, string[]>;
            } | null)
          : null;
      setError(
        failure instanceof ClinicHttpError && failure.kind === 'forbidden'
          ? "You cannot change this patient's clinical record."
          : (Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? 'The conditions were not saved. Try again.'),
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Manage medical conditions"
      description="Conditions you remove are kept in the patient's history as resolved."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button tone="primary" onClick={save} loading={saving} loadingLabel="Saving…" disabled={saving}>
            Save conditions
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Notice tone="danger">{error}</Notice>}
        {drafts.length === 0 ? (
          <Quiet>No medical conditions recorded.</Quiet>
        ) : (
          <ul className="divide-y divide-cx-line rounded-[12px] border border-cx-line">
            {drafts.map((draft, index) => (
              <li key={draft.key ?? draft.label} className="flex flex-wrap items-center gap-2 px-3 py-2">
                <span className="min-w-0 flex-1 text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">{draft.label}</span>
                <div className="w-[140px]">
                  <Select
                    aria-label={`How serious: ${draft.label}`}
                    value={draft.severity ?? ''}
                    onChange={(event) =>
                      setDrafts((list) =>
                        list.map((item, at) =>
                          at === index
                            ? {
                                ...item,
                                severity: event.target.value || null,
                              }
                            : item,
                        ),
                      )
                    }
                    placeholder="Not rated"
                    options={editor.severities.map((severity) => ({
                      value: severity,
                      label: humanize(severity),
                    }))}
                  />
                </div>
                <button
                  type="button"
                  onClick={() => setDrafts((list) => list.filter((_, at) => at !== index))}
                  aria-label={`Remove ${draft.label}`}
                  className="grid h-9 w-9 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className={cn('grid gap-3', editor.custom_allowed && 'sm:grid-cols-2')}>
          <Field label="Add a condition" htmlFor="condition-option" hint={editor.custom_allowed ? undefined : 'This clinic records conditions from its own list. An administrator adds others in Settings.'}>
            <div className="flex gap-2">
              <div className="min-w-0 flex-1">
                <Select
                  id="condition-option"
                  value={choice}
                  onChange={(event) => setChoice(event.target.value)}
                  placeholder={available.length ? 'Choose…' : 'All added'}
                  options={available.map((option) => ({
                    value: option.key,
                    label: option.name,
                  }))}
                />
              </div>
              <Button tone="ghost" onClick={addOption} disabled={choice === ''} aria-label="Add the chosen condition">
                <Plus className="h-4 w-4" aria-hidden="true" />
              </Button>
            </div>
          </Field>
          {editor.custom_allowed && (
            <Field label="Other condition" htmlFor="condition-other">
              <div className="flex gap-2">
                <div className="min-w-0 flex-1">
                  <Input id="condition-other" value={other} maxLength={160} onChange={(event) => setOther(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && (event.preventDefault(), addOther())} />
                </div>
                <Button tone="ghost" onClick={addOther} disabled={other.trim() === ''} aria-label="Add the typed condition">
                  <Plus className="h-4 w-4" aria-hidden="true" />
                </Button>
              </div>
            </Field>
          )}
        </div>
      </div>
    </Modal>
  );
}
