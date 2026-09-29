import React, { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Phone, Plus, Search, UserRoundSearch } from 'lucide-react';
import { CrmAvatar, CrmButton, CrmModal, CrmStatusPill } from '@/pages/Company/design';
import { PatientCreateProps } from '../types';
import { PatientFields, PatientFormData } from './PatientFields';

/**
 * The primary way a receptionist adds a patient — search first, register
 * only if nothing comes back. Opens over the directory rather than
 * navigating to `/clinic/patients/create`: that full page still exists
 * (a direct link, and the fallback this modal's form is built from), but
 * the fast path most registrations actually take is "is this person
 * already here?" before "let's create a new record."
 */

interface SearchResult {
  id: number;
  uhid: string;
  name: string;
  phone: string | null;
  gender: string | null;
  age: number | null;
}

const EMPTY_FORM: PatientFormData = {
  first_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  age: '',
  blood_group: '',
  phone: '',
  alternate_phone: '',
  email: '',
  address: '',
  city: '',
  state: '',
  pincode: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  allergies: [],
  medical_alerts: [],
  doctor_id: '',
};

export function QuickAddPatientModal({
  open,
  onClose,
  practitioners,
  allergyCatalogue,
  fieldConfig,
}: { open: boolean; onClose: () => void } & PatientCreateProps) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [hasSearched, setHasSearched] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const { data, setData, post, processing, errors, reset, clearErrors } = useForm<PatientFormData>(EMPTY_FORM);

  // Every open starts fresh — a modal left mounted between opens must not
  // carry the last patient's search or half-filled form into the next one.
  useEffect(() => {
    if (open) {
      setQuery('');
      setResults([]);
      setHasSearched(false);
      setShowForm(false);
      reset();
      clearErrors();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => () => clearTimeout(searchTimer.current), []);

  const handleQueryChange = (value: string) => {
    setQuery(value);
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

  const openRegistrationForm = () => {
    const trimmed = query.trim();
    const isPhoneLike = /^[\d\s+-]+$/.test(trimmed) && trimmed !== '';

    if (isPhoneLike) {
      setData('phone', trimmed.replace(/\s+/g, ''));
    } else if (trimmed !== '') {
      const [first, ...rest] = trimmed.split(/\s+/);
      setData('first_name', first);
      if (rest.length > 0) setData('last_name', rest.join(' '));
    }

    setShowForm(true);
  };

  const selectExisting = (patientId: number) => {
    onClose();
    router.visit(route('clinic.patients.show', patientId));
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('clinic.patients.store'), {
      onSuccess: () => onClose(),
    });
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={showForm ? 'Register patient' : 'Add patient'}
      description={
        showForm ? 'A UHID is generated automatically once this is saved.' : "Search first — it's faster than finding a duplicate later."
      }
      size="lg"
      footer={
        showForm ? (
          <>
            <CrmButton onClick={() => setShowForm(false)}>Back to search</CrmButton>
            <CrmButton
              tone="primary"
              loading={processing}
              disabled={!data.first_name.trim() || !data.phone.trim() || (fieldConfig.gender === 'required' && !data.gender)}
              onClick={submit}
            >
              Register patient
            </CrmButton>
          </>
        ) : (
          <CrmButton tone="primary" icon={Plus} onClick={openRegistrationForm}>
            {query.trim() ? 'None of these — register new' : 'Register new patient'}
          </CrmButton>
        )
      }
    >
      {!showForm ? (
        <div className="space-y-4">
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

          {!hasSearched && !searching && (
            <div className="flex flex-col items-center gap-2 py-10 text-center">
              <UserRoundSearch className="h-8 w-8 text-ovs-crm-text-muted" aria-hidden="true" />
              <p className="text-[13px] text-ovs-crm-text-muted">Type a phone number or name to check for an existing patient first.</p>
            </div>
          )}

          {hasSearched && results.length > 0 && (
            <div className="space-y-2">
              {results.map((patient) => (
                <button
                  key={patient.id}
                  type="button"
                  onClick={() => selectExisting(patient.id)}
                  className="flex w-full flex-wrap items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border bg-white px-3.5 py-3 text-left transition-colors hover:border-ovs-crm-border-strong hover:bg-ovs-crm-surface-inset"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <CrmAvatar name={patient.name} size="md" />
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">{patient.name}</p>
                      <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                        <span className="tabular-nums">{patient.uhid}</span>
                        {patient.age !== null && ` · ${patient.age}yrs`}
                        {patient.gender && ` · ${patient.gender.charAt(0).toUpperCase()}${patient.gender.slice(1)}`}
                      </p>
                    </div>
                  </div>
                  {patient.phone && (
                    <span className="flex items-center gap-1.5 text-[12.5px] text-ovs-crm-text-secondary">
                      <Phone className="h-3.5 w-3.5 text-ovs-crm-text-muted" aria-hidden="true" />
                      {patient.phone}
                    </span>
                  )}
                </button>
              ))}
            </div>
          )}

          {hasSearched && results.length === 0 && !searching && (
            <div className="flex flex-col items-center gap-2 rounded-[16px] bg-ovs-crm-surface-inset py-10 text-center">
              <CrmStatusPill tone="neutral">No match found</CrmStatusPill>
              <p className="text-[13px] text-ovs-crm-text-muted">No existing patient matches "{query.trim()}" — register them as new below.</p>
            </div>
          )}
        </div>
      ) : (
        <PatientFields
          data={data}
          setData={setData}
          errors={errors}
          practitioners={practitioners}
          allergyCatalogue={allergyCatalogue}
          fieldConfig={fieldConfig}
        />
      )}
    </CrmModal>
  );
}

export default QuickAddPatientModal;
