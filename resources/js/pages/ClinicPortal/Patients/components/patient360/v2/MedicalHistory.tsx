import { useMemo, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { ClipboardList, HeartPulse, NotebookPen, Pencil, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { IconChip } from '@/pages/Clinic/Reception/intake/IconChip';
import { MedicalHistorySection, SUPPORTED_CONDITIONS } from '@/pages/Clinic/Reception/intake/MedicalHistorySection';
import { Panel } from '@/pages/ClinicPortal/CommandCenter/sections';
import { shortDate } from '../../format';
import type { ConditionEditor, MedicalCondition, Patient360Context, SafetyEditor } from '../types';

/*
 * Medical history exactly as the New Case form records it (spec §3a): the
 * seven conditions, Other Condition (its name typed only when chosen), and
 * Medical History Notes & Remarks. Read from `context.medical_conditions` /
 * `context.medical_notes`; written through the existing condition and notes
 * endpoints, only when the server sends their editors. Nothing else from the
 * old Patient 360 (allergies, alerts, medicines, other history) is drawn.
 */

const FIXED = SUPPORTED_CONDITIONS.filter((condition) => condition.key !== 'other');
const FIXED_KEYS = FIXED.map((condition) => condition.key);

/** The recorded conditions split into the seven fixed ones and the rest (shown as Other Condition). */
function split(conditions: MedicalCondition[]): { fixed: MedicalCondition[]; other: MedicalCondition[] } {
  return {
    fixed: conditions.filter((condition) => condition.key !== null && FIXED_KEYS.includes(condition.key)),
    other: conditions.filter((condition) => condition.key === null || !FIXED_KEYS.includes(condition.key)),
  };
}

/**
 * The header's medical history in one compact line: only what was recorded,
 * each as a small chip with its New Case icon and name (other conditions with
 * the Other Condition icon), and a Notes chip whose text shows on hover.
 * Nothing unrecorded is drawn. "Edit" opens the New Case form itself.
 */
export function MedicalHistoryLine({ context, onOpen }: { context: Patient360Context; onOpen?: () => void }) {
  const { fixed, other } = split(context.medical_conditions ?? []);
  const otherLook = SUPPORTED_CONDITIONS.find((item) => item.key === 'other')!;
  const chips = [
    ...FIXED.filter((item) => fixed.some((condition) => condition.key === item.key)).map((item) => ({ key: item.key, label: item.label, look: item })),
    ...other.map((condition) => ({ key: `other-${condition.key ?? ''}-${condition.label}`, label: condition.label, look: otherLook })),
  ];

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="mr-1 inline-flex items-center gap-1.5 text-[12px] font-semibold text-cx-text-2">
        <HeartPulse className="h-3.5 w-3.5 text-[#6B48DE]" aria-hidden="true" />
        Medical history
      </span>
      {chips.length === 0 && !context.medical_notes && <span className="text-[12px] text-cx-text-3">None reported</span>}
      {chips.map((chip) => (
        <span key={chip.key} className={cn('inline-flex h-7 max-w-[260px] items-center gap-1.5 rounded-lg border pl-1 pr-2 text-[11.5px] font-semibold', chip.look.activeClass)} title={chip.label}>
          <IconChip icon={chip.look.icon} tone={chip.look.tone} size="xs" variant="solid" className="!h-5 !w-5" />
          <span className="truncate">{chip.label}</span>
        </span>
      ))}
      {context.medical_notes && (
        <span className="inline-flex h-7 max-w-[260px] items-center gap-1.5 rounded-lg border border-[#DCE6F3] bg-white pl-1.5 pr-2 text-[11.5px] font-semibold text-cx-nav-ink" title={context.medical_notes}>
          <NotebookPen className="h-3.5 w-3.5 shrink-0 text-cx-text-2" aria-hidden="true" />
          <span className="truncate">{context.medical_notes}</span>
        </span>
      )}
      {onOpen && (
        <button type="button" onClick={onOpen} aria-label="Edit medical history" className="ml-auto inline-flex h-7 items-center gap-1 rounded-full px-2 text-[12px] font-semibold text-cx-primary hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
          <Pencil className="h-3.5 w-3.5" aria-hidden="true" /> Edit
        </button>
      )}
    </div>
  );
}

/**
 * Editing the medical history in the New Case form itself
 * (`MedicalHistorySection`): conditions go to the condition endpoint (the
 * whole list, which it replaces), then changed notes to the notes endpoint.
 */
export function MedicalHistoryEditor({ context, conditionEditor, safetyEditor, onClose }: { context: Patient360Context; conditionEditor: ConditionEditor | null; safetyEditor: SafetyEditor | null; onClose: () => void }) {
  const recorded = context.medical_conditions ?? [];
  const [data, setData] = useState<{ conditions: { key?: string; label: string }[]; notes?: string }>(() => ({
    conditions: recorded.map((condition) => ({ key: condition.key ?? undefined, label: condition.label })),
    notes: context.medical_notes ?? '',
  }));
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fail = (errors: Record<string, string>) => {
    setBusy(false);
    setProblem(Object.values(errors)[0] ?? 'Not saved.');
  };
  const saveNotes = () => {
    if (!safetyEditor || (data.notes ?? '') === (context.medical_notes ?? '')) {
      setBusy(false);
      onClose();

      return;
    }
    router.put(safetyEditor.notes, { notes: data.notes ?? '' }, { preserveScroll: true, onError: fail, onSuccess: () => { setBusy(false); onClose(); } });
  };
  const save = () => {
    setBusy(true);
    setProblem(null);
    if (!conditionEditor) {
      saveNotes();

      return;
    }
    const entries = data.conditions
      .filter((condition) => condition.key !== 'other')
      .map((condition) => ({
        key: condition.key ?? null,
        label: FIXED.find((item) => item.key === condition.key)?.label ?? condition.label,
        severity: recorded.find((item) => item.key !== null && item.key === condition.key)?.severity ?? null,
      }));
    router.put(conditionEditor.url, { entries }, { preserveScroll: true, onError: fail, onSuccess: saveNotes });
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && !busy && onClose()}
      title="Edit medical history"
      description="The same form as New Case."
      size="lg"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} onClick={save}>
            Save
          </Button>
        </>
      }
    >
      <MedicalHistorySection data={data} onChange={(patch) => setData((current) => ({ ...current, ...patch }))} disabled={busy} />
      {conditionEditor && !conditionEditor.custom_allowed && <p className="mt-2 text-[12px] text-cx-text-2">This clinic records other conditions from its own list only.</p>}
      {problem && (
        <p role="alert" className="mt-2 text-[12.5px] font-medium text-cx-rose-ink">
          {problem}
        </p>
      )}
    </Modal>
  );
}

/** Clinical → Medical History: the nine New Case fields, read-only or edited through the existing endpoints. */
function Frame({ bare, edit, children }: { bare: boolean; edit: ReactNode; children: ReactNode }) {
  return bare ? (
    <div>
      {edit && <div className="mb-3 flex justify-end">{edit}</div>}
      {children}
    </div>
  ) : (
    <Panel id="medical-history" title="Medical history" caption="As recorded at registration and by clinicians" icon={ClipboardList} tone="teal" aside={edit}>
      {children}
    </Panel>
  );
}

/** The header's medical history, opened from its line: the same nine fields and the existing editors, in a dialog. */
export function MedicalHistoryDialog({ context, conditionEditor, safetyEditor, readOnly, onClose }: { context: Patient360Context; conditionEditor: ConditionEditor | null; safetyEditor: SafetyEditor | null; readOnly: boolean; onClose: () => void }) {
  return (
    <Modal open onOpenChange={(open) => !open && onClose()} title="Medical history" description="As recorded at registration and by clinicians" size="lg" footer={<Button tone="primary" onClick={onClose}>Close</Button>}>
      <MedicalHistoryCard context={context} conditionEditor={conditionEditor} safetyEditor={safetyEditor} readOnly={readOnly} bare />
    </Modal>
  );
}

export function MedicalHistoryCard({ context, conditionEditor, safetyEditor, readOnly, bare = false }: { context: Patient360Context; conditionEditor: ConditionEditor | null; safetyEditor: SafetyEditor | null; readOnly: boolean; bare?: boolean }) {
  const conditions = context.medical_conditions ?? [];
  const { fixed, other } = split(conditions);
  const [editing, setEditing] = useState<'conditions' | 'notes' | null>(null);
  const recordedKeys = fixed.map((condition) => condition.key);
  const edit =
    !readOnly && conditionEditor ? (
      <Button size="sm" icon={Pencil} onClick={() => setEditing('conditions')}>
        Edit
      </Button>
    ) : undefined;
  return (
    <Frame bare={bare} edit={edit}>
      <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {SUPPORTED_CONDITIONS.map((item) => {
          const Icon = item.icon;
          const on = item.key === 'other' ? other.length > 0 : recordedKeys.includes(item.key);

          return (
            <li key={item.key} className={cn('flex min-h-11 items-center gap-2 rounded-xl border px-3 py-2 text-[13px] font-semibold', on ? item.activeClass : 'border-cx-line bg-white text-cx-text-3')}>
              <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
              <span className="min-w-0">
                {item.label}
                <span className="sr-only">{on ? ': yes' : ': no'}</span>
                {item.key === 'other' && other.length > 0 && <span className="block text-[12px] font-medium text-cx-text-2 [overflow-wrap:anywhere]">{other.map((condition) => condition.label).join(', ')}</span>}
              </span>
            </li>
          );
        })}
      </ul>

      <div className="mt-4 rounded-xl bg-cx-slate-bg/60 p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-teal-ink">Medical history notes & remarks</p>
          {!readOnly && safetyEditor && (
            <Button size="sm" tone="ghost" icon={Pencil} onClick={() => setEditing('notes')}>
              Edit notes
            </Button>
          )}
        </div>
        {context.medical_notes ? (
          <>
            <p className="mt-1 whitespace-pre-line text-[13.5px] text-cx-text [overflow-wrap:anywhere]">{context.medical_notes}</p>
            <p className="mt-1 text-[12px] text-cx-text-3">{[context.medical_notes_updated_by, shortDate(context.medical_notes_updated_on)].filter(Boolean).join(' · ')}</p>
          </>
        ) : (
          <p className="mt-1 text-[13px] text-cx-text-2">No notes recorded.</p>
        )}
      </div>

      {editing === 'conditions' && conditionEditor && <ConditionsDialog editor={conditionEditor} conditions={conditions} onClose={() => setEditing(null)} />}
      {editing === 'notes' && safetyEditor && <NotesDialog url={safetyEditor.notes} notes={context.medical_notes} onClose={() => setEditing(null)} />}
    </Frame>
  );
}

/** The New Case tiles as an editor. The whole list is saved (the endpoint replaces it), so recorded "other" conditions are kept unless removed. */
function ConditionsDialog({ editor, conditions, onClose }: { editor: ConditionEditor; conditions: MedicalCondition[]; onClose: () => void }) {
  const initial = useMemo(() => split(conditions), [conditions]);
  const [chosen, setChosen] = useState<string[]>(() => initial.fixed.map((condition) => condition.key as string));
  const [others, setOthers] = useState<MedicalCondition[]>(initial.other);
  const [otherOpen, setOtherOpen] = useState(initial.other.length > 0);
  const [typed, setTyped] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toggle = (key: string) => setChosen((list) => (list.includes(key) ? list.filter((item) => item !== key) : [...list, key]));

  const save = () => {
    const name = typed.trim();
    const extra = otherOpen && name !== '' ? [{ key: null, label: name, severity: null }] : [];
    const keep = (key: string) => conditions.find((condition) => condition.key === key);
    const entries = [
      ...chosen.map((key) => ({ key, label: FIXED.find((item) => item.key === key)?.label ?? key, severity: keep(key)?.severity ?? null })),
      ...(otherOpen ? others.map((condition) => ({ key: condition.key, label: condition.label, severity: condition.severity })) : []),
      ...extra,
    ];
    router.put(editor.url, { entries }, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not saved.'),
      onSuccess: onClose,
    });
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Edit medical history"
      description="The same choices as the New Case form."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} onClick={save}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-2">
        {SUPPORTED_CONDITIONS.map((item) => {
          const Icon = item.icon;
          const on = item.key === 'other' ? otherOpen : chosen.includes(item.key);

          return (
            <button
              key={item.key}
              type="button"
              aria-pressed={on}
              onClick={() => (item.key === 'other' ? setOtherOpen((value) => !value) : toggle(item.key))}
              className={cn('flex min-h-11 items-center gap-2 rounded-xl border px-3 text-left text-[13px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35', on ? item.activeClass : 'border-cx-line bg-white text-cx-text-2 hover:border-[#B9C6D8]')}
            >
              <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
              {item.label}
            </button>
          );
        })}
      </div>
      {otherOpen && (
        <div className="mt-3 space-y-2">
          {others.length > 0 && (
            <ul className="flex flex-wrap gap-1.5">
              {others.map((condition) => (
                <li key={`${condition.key ?? ''}-${condition.label}`} className="inline-flex items-center gap-1 rounded-full bg-cx-slate-bg px-2.5 py-0.5 text-[12.5px] font-semibold text-cx-nav-ink">
                  {condition.label}
                  <button type="button" onClick={() => setOthers((list) => list.filter((item) => item !== condition))} aria-label={`Remove ${condition.label}`} className="rounded-full hover:text-cx-rose-ink">
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ul>
          )}
          {editor.custom_allowed ? (
            <Field label="Other condition" htmlFor="mh-other">
              <Input id="mh-other" value={typed} maxLength={160} onChange={(event) => setTyped(event.target.value)} />
            </Field>
          ) : (
            <p className="text-[12.5px] text-cx-text-2">This clinic records conditions from its own list only.</p>
          )}
        </div>
      )}
      {problem && (
        <p role="alert" className="mt-2 text-[12.5px] font-medium text-cx-rose-ink">
          {problem}
        </p>
      )}
    </Modal>
  );
}

function NotesDialog({ url, notes, onClose }: { url: string; notes: string | null; onClose: () => void }) {
  const [text, setText] = useState(notes ?? '');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Medical history notes & remarks"
      description="The earlier version is kept in the patient's history."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            tone="primary"
            loading={busy}
            disabled={busy || text === (notes ?? '')}
            onClick={() =>
              router.put(url, { notes: text }, {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not saved.'),
                onSuccess: onClose,
              })
            }
          >
            Save notes
          </Button>
        </>
      }
    >
      <Field label="Notes & remarks" htmlFor="mh-notes" error={problem}>
        <Textarea id="mh-notes" value={text} rows={6} maxLength={5000} onChange={(event) => setText(event.target.value)} />
      </Field>
    </Modal>
  );
}
