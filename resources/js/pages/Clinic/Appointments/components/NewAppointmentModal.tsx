import React, { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Phone, Search, UserRoundSearch } from 'lucide-react';
import { CrmAvatar, CrmButton, CrmFormField, CrmInput, CrmModal, CrmSelect, CrmTextarea } from '@/pages/Company/design';
import { AppointmentPractitioner, AppointmentRoom } from '../types';

/**
 * A centred `CrmModal`, not a slide-over drawer — the design system has a
 * deliberate no-drawer rule (`CrmOverlay.tsx`), and every other "create X"
 * flow this project has built (patients' Quick-Add, Transfer) already used
 * `CrmModal`. Patient selection is search-only (reusing the exact debounced
 * pattern `QuickAddPatientModal` already established against
 * `clinic.patients.search`) — registering a brand-new patient stays the
 * Patients page's job, not duplicated here.
 */

interface SearchResult {
  id: number;
  uhid: string;
  name: string;
  phone: string | null;
  gender: string | null;
  age: number | null;
}

/** Treatment type → default duration (minutes). Hardcoded for now, per the plan — not admin-configurable yet. */
const TREATMENT_DURATIONS: Record<string, number> = {
  consultation: 15,
  follow_up: 15,
  procedure: 45,
  emergency: 30,
  general: 20,
};

const TREATMENT_TYPE_OPTIONS = [
  { value: 'consultation', label: 'Consultation' },
  { value: 'follow_up', label: 'Follow-up' },
  { value: 'procedure', label: 'Procedure' },
  { value: 'emergency', label: 'Emergency' },
  { value: 'general', label: 'General' },
];

interface AppointmentFormData {
  patient_id: string;
  practitioner_id: string;
  room_id: string;
  time: string;
  duration_minutes: string;
  appointment_type: string;
  notes: string;
}

export function NewAppointmentModal({
  open,
  onClose,
  date,
  defaultTime,
  defaultPractitionerId,
  practitioners,
  rooms,
}: {
  open: boolean;
  onClose: () => void;
  date: string;
  defaultTime?: string;
  defaultPractitionerId?: number;
  practitioners: AppointmentPractitioner[];
  rooms: AppointmentRoom[];
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [hasSearched, setHasSearched] = useState(false);
  const [selectedPatient, setSelectedPatient] = useState<SearchResult | null>(null);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  // `defaultPractitionerId`/`defaultTime` seed the form's *initial* state
  // directly, rather than being patched in by an effect after mount — an
  // effect-based `setData()` on `open` raced against this component's own
  // mount inside `CrmModal`'s portal/animation and the doctor field lost
  // silently (confirmed live: the prop arrived correctly, `data.time`
  // picked up its default, `data.practitioner_id` didn't — a mount-timing
  // issue, not a data one). The parent gives this component a fresh `key`
  // each time it opens (see `Index.tsx`), so a full remount — and this
  // initial state — runs every time, the same guarantee `reset()` was
  // trying to provide imperatively.
  const { data, setData, post, processing, errors, clearErrors } = useForm<AppointmentFormData>({
    patient_id: '',
    practitioner_id: defaultPractitionerId ? String(defaultPractitionerId) : '',
    room_id: '',
    time: defaultTime ?? '09:00',
    duration_minutes: '15',
    appointment_type: 'consultation',
    notes: '',
  });

  useEffect(() => {
    if (open) {
      setQuery('');
      setResults([]);
      setHasSearched(false);
      setSelectedPatient(null);
      clearErrors();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => () => clearTimeout(searchTimer.current), []);

  const handleQueryChange = (value: string) => {
    setQuery(value);
    setSelectedPatient(null);
    setData('patient_id', '');
    clearTimeout(searchTimer.current);

    if (value.trim().length < 2) {
      setResults([]);
      setHasSearched(false);
      return;
    }

    searchTimer.current = setTimeout(async () => {
      setSearching(true);
      try {
        const res = await fetch(`${route('clinic.patients.search')}?q=${encodeURIComponent(value.trim())}`, {
          headers: { Accept: 'application/json' },
        });
        const json: SearchResult[] = await res.json();
        setResults(json);
      } catch {
        setResults([]);
      } finally {
        setHasSearched(true);
        setSearching(false);
      }
    }, 300);
  };

  const selectPatient = (patient: SearchResult) => {
    setSelectedPatient(patient);
    setData('patient_id', String(patient.id));
    setQuery(patient.name);
    setResults([]);
    setHasSearched(false);
  };

  const handleTypeChange = (type: string) => {
    setData('appointment_type', type);
    setData('duration_minutes', String(TREATMENT_DURATIONS[type] ?? 15));
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(
      route('clinic.appointments.store', {
        patient_id: data.patient_id,
        practitioner_id: data.practitioner_id,
        room_id: data.room_id || undefined,
        scheduled_at: `${date} ${data.time}:00`,
        duration_minutes: data.duration_minutes,
        appointment_type: data.appointment_type,
        notes: data.notes || undefined,
      }),
      { onSuccess: () => onClose() }
    );
  };

  const canSubmit = !!data.patient_id && !!data.practitioner_id && !!data.time;

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="New appointment"
      description={`Scheduling for ${date}.`}
      size="lg"
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" loading={processing} disabled={!canSubmit} onClick={submit}>
            Schedule appointment
          </CrmButton>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <CrmFormField label="Patient" required error={errors.patient_id}>
          {selectedPatient ? (
            <div className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-3.5 py-2.5">
              <div className="flex min-w-0 items-center gap-2.5">
                <CrmAvatar name={selectedPatient.name} size="sm" />
                <div className="min-w-0">
                  <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">{selectedPatient.name}</p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    <span className="tabular-nums">{selectedPatient.uhid}</span>
                    {selectedPatient.phone && ` · ${selectedPatient.phone}`}
                  </p>
                </div>
              </div>
              <CrmButton size="sm" onClick={() => handleQueryChange('')}>
                Change
              </CrmButton>
            </div>
          ) : (
            <div className="space-y-2">
              <div className="relative">
                <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ovs-crm-text-muted" aria-hidden="true" />
                <input
                  autoFocus
                  value={query}
                  onChange={(e) => handleQueryChange(e.target.value)}
                  placeholder="Search by phone or name…"
                  className="h-11 w-full rounded-full border border-ovs-crm-border bg-white pl-11 pr-4 text-[13px] text-ovs-crm-text-primary placeholder:text-ovs-crm-text-muted focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15"
                />
              </div>

              {!hasSearched && !searching && query.trim().length === 0 && (
                <div className="flex flex-col items-center gap-1.5 py-6 text-center">
                  <UserRoundSearch className="h-6 w-6 text-ovs-crm-text-muted" aria-hidden="true" />
                  <p className="text-[12.5px] text-ovs-crm-text-muted">Type a phone number or name to find the patient.</p>
                </div>
              )}

              {results.length > 0 && (
                <div className="max-h-[220px] space-y-1.5 overflow-y-auto">
                  {results.map((patient) => (
                    <button
                      key={patient.id}
                      type="button"
                      onClick={() => selectPatient(patient)}
                      className="flex w-full items-center justify-between gap-3 rounded-[14px] border border-ovs-crm-border bg-white px-3 py-2.5 text-left transition-colors hover:border-ovs-crm-border-strong hover:bg-ovs-crm-surface-inset"
                    >
                      <div className="flex min-w-0 items-center gap-2.5">
                        <CrmAvatar name={patient.name} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">{patient.name}</p>
                          <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{patient.uhid}</p>
                        </div>
                      </div>
                      {patient.phone && (
                        <span className="flex shrink-0 items-center gap-1 text-[12px] text-ovs-crm-text-secondary">
                          <Phone className="h-3 w-3" aria-hidden="true" />
                          {patient.phone}
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              )}

              {hasSearched && results.length === 0 && !searching && (
                <p className="rounded-[14px] bg-ovs-crm-surface-inset px-3.5 py-3 text-[12.5px] text-ovs-crm-text-muted">
                  No patient matches "{query.trim()}" — register them from the{' '}
                  <a href={route('clinic.patients.index')} className="font-medium text-ovs-crm-text-primary underline underline-offset-2">
                    Patients page
                  </a>{' '}
                  first, then come back here.
                </p>
              )}
            </div>
          )}
        </CrmFormField>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="Doctor" required error={errors.practitioner_id}>
            <CrmSelect value={data.practitioner_id} onValueChange={(v) => setData('practitioner_id', v)} placeholder="Select a doctor">
              {practitioners.map((p) => (
                <option key={p.id} value={String(p.id)}>
                  {p.name} — {p.specialty}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>

          {/* The server sends no rooms while rooms are off (Settings > Patient Flow). */}
          {rooms.length > 0 && (
            <CrmFormField label="Room / chair" error={errors.room_id} hint="Optional">
              <CrmSelect value={data.room_id} onValueChange={(v) => setData('room_id', v)} placeholder="No room assigned">
                {rooms.map((r) => (
                  <option key={r.id} value={String(r.id)}>
                    {r.name}
                  </option>
                ))}
              </CrmSelect>
            </CrmFormField>
          )}

          {/* The server validates/conflict-checks the combined value as `scheduled_at` (see `AppointmentController::store()`), a field name this client-side form doesn't have of its own — read via an index signature. */}
          <CrmFormField label="Time" required error={(errors as Record<string, string | undefined>).scheduled_at}>
            <CrmInput type="time" value={data.time} onChange={(e) => setData('time', e.target.value)} />
          </CrmFormField>

          <CrmFormField label="Duration (minutes)" required error={errors.duration_minutes}>
            <CrmInput
              type="number"
              min={5}
              max={240}
              value={data.duration_minutes}
              onChange={(e) => setData('duration_minutes', e.target.value)}
            />
          </CrmFormField>

          <CrmFormField label="Treatment type" required error={errors.appointment_type} className="sm:col-span-2">
            <CrmSelect value={data.appointment_type} onValueChange={handleTypeChange} options={TREATMENT_TYPE_OPTIONS} />
          </CrmFormField>
        </div>

        <CrmFormField label="Notes" error={errors.notes}>
          <CrmTextarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} placeholder="Optional" />
        </CrmFormField>
      </form>
    </CrmModal>
  );
}

export default NewAppointmentModal;
