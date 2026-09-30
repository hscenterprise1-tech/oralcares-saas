import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRightLeft,
  CalendarClock,
  FileText,
  IndianRupee,
  Mail,
  MapPin,
  Phone,
  Pencil,
  Pill,
  Printer,
  ShieldAlert,
  Stethoscope,
  Upload,
  UserCheck,
} from 'lucide-react';
import {
  CrmAvatar,
  CrmButton,
  CrmCard,
  CrmCardIcon,
  CrmDataState,
  CrmIconButton,
  CrmPageHead,
  CrmPagination,
  CrmSection,
  CrmStatusPill,
  CrmTabs,
  inr,
  toneHex,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { ClinicShell } from '../components/ClinicShell';
import { PatientTimelineFeed } from './components/PatientTimelineFeed';
import { PrintSummary } from './components/PrintSummary';
import { TransferPatientModal } from './components/TransferPatientModal';
import { SEVERITY_TONE, STATUS_LABEL, STATUS_TONE } from './patientTheme';
import { PatientShowProps } from './types';

/*
 * Patient 360 — demographics is open to any clinic user who can see the
 * patient at all; Clinical and Billing are their own tabs that are simply
 * absent from the tab list when the acting user lacks the permission,
 * matching how the backend already withholds the data rather than sending
 * it and hiding it with CSS.
 */

const APPOINTMENT_STATUS_TONE: Record<string, CrmStatusTone> = {
  scheduled: 'blue',
  checked_in: 'warn',
  completed: 'good',
  cancelled: 'neutral',
  no_show: 'bad',
};

type TabKey = 'overview' | 'appointments' | 'visits' | 'clinical' | 'billing' | 'documents';

export default function PatientShow({
  patient,
  todayStatus,
  upcomingAppointment,
  timeline,
  appointments,
  visits,
  billing,
  prescriptions,
  documents,
  practitioners,
  permissions,
}: PatientShowProps) {
  const [activeTab, setActiveTab] = useState<TabKey>('overview');
  const [transferOpen, setTransferOpen] = useState(false);

  const tabs: { key: TabKey; label: string; count?: number }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'appointments', label: 'Appointments', count: appointments.total },
    { key: 'visits', label: 'Visits', count: visits.total },
    ...(permissions.can_view_clinical ? [{ key: 'clinical' as TabKey, label: 'Clinical', count: prescriptions.items.total }] : []),
    ...(permissions.can_view_billing ? [{ key: 'billing' as TabKey, label: 'Billing', count: billing.invoices.total }] : []),
    { key: 'documents', label: 'Documents', count: documents.total },
  ];

  const uploadForm = useForm<{ document: File | null; document_type: string; title: string }>({
    document: null,
    document_type: 'medical_report',
    title: '',
  });

  const submitUpload = (e: React.FormEvent) => {
    e.preventDefault();
    uploadForm.post(route('clinic.patients.documents.store', patient.id), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => uploadForm.reset(),
    });
  };

  return (
    <ClinicShell title={patient.name}>
      <div className="mb-2 print:hidden">
        <CrmIconButton icon={ArrowLeft} label="Back to patients" tone="ghost" href={route('clinic.patients.index')} />
      </div>

      <CrmPageHead
        eyebrow="Clinic"
        title={patient.name}
        className="print:hidden"
        stats={
          <div className="flex flex-wrap items-center gap-3">
            <CrmAvatar name={patient.name} size="lg" />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <CrmStatusPill tone={STATUS_TONE[patient.status]}>{STATUS_LABEL[patient.status]}</CrmStatusPill>
                <span className="text-[12px] tabular-nums text-ovs-crm-text-muted">{patient.uhid}</span>
              </div>
              <p className="mt-1 text-[12.5px] text-ovs-crm-text-secondary">
                {patient.age ?? '—'} yrs · {patient.gender ?? '—'}
                {patient.blood_group && ` · ${patient.blood_group}`}
              </p>
            </div>
          </div>
        }
        actions={
          <div className="flex items-center gap-2">
            <CrmButton icon={Printer} onClick={() => window.print()}>
              Print Summary
            </CrmButton>
            {permissions.can_transfer_patient && (
              <CrmButton icon={ArrowRightLeft} onClick={() => setTransferOpen(true)}>
                Transfer
              </CrmButton>
            )}
            {permissions.can_edit_patient && (
              <CrmButton tone="primary" icon={Pencil} href={route('clinic.patients.edit', patient.id)}>
                Edit
              </CrmButton>
            )}
          </div>
        }
      />

      {patient.alerts.length > 0 && (
        <div className="mb-4 flex flex-wrap gap-2 print:hidden">
          {patient.alerts.map((alert) => (
            <span
              key={alert.id}
              className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-stage-coral-soft px-3 py-1.5 text-[12px] font-medium text-ovs-crm-stage-coral-text"
            >
              <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
              {alert.description}
            </span>
          ))}
        </div>
      )}

      <div className="mb-4 print:hidden">
        <CrmTabs tabs={tabs} value={activeTab} onChange={setActiveTab} />
      </div>

      <div className="space-y-4 pb-16 print:hidden">
        {activeTab === 'overview' && (
          <>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <CrmCard frostHue={todayStatus.has_active_today ? toneHex('violet') : undefined}>
                <div className="mb-3 flex items-center gap-3">
                  <CrmCardIcon icon={CalendarClock} hue={toneHex('violet')} />
                  <p className="text-[13px] font-semibold text-ovs-crm-text-primary">Today</p>
                </div>
                {todayStatus.appointment ? (
                  <div className="space-y-1">
                    <p className="text-[13.5px] text-ovs-crm-text-primary">
                      {todayStatus.appointment.scheduled_time} with {todayStatus.appointment.practitioner_name}
                    </p>
                    <CrmStatusPill tone={APPOINTMENT_STATUS_TONE[todayStatus.appointment.status] ?? 'neutral'}>
                      {todayStatus.appointment.status.replace(/_/g, ' ')}
                    </CrmStatusPill>
                  </div>
                ) : todayStatus.visit ? (
                  <div className="space-y-1">
                    <p className="text-[13.5px] text-ovs-crm-text-primary">
                      In with {todayStatus.visit.doctor_name}
                      {todayStatus.visit.queue_position && ` · Queue #${todayStatus.visit.queue_position}`}
                    </p>
                    <CrmStatusPill tone={APPOINTMENT_STATUS_TONE[todayStatus.visit.visit_status] ?? 'neutral'}>
                      {todayStatus.visit.visit_status.replace(/_/g, ' ')}
                    </CrmStatusPill>
                  </div>
                ) : (
                  <p className="text-[12.5px] text-ovs-crm-text-muted">Nothing today.</p>
                )}
              </CrmCard>

              <CrmCard>
                <div className="mb-3 flex items-center gap-3">
                  <CrmCardIcon icon={Stethoscope} hue={toneHex('blue')} />
                  <p className="text-[13px] font-semibold text-ovs-crm-text-primary">Upcoming</p>
                </div>
                {upcomingAppointment ? (
                  <div className="space-y-1">
                    <p className="text-[13.5px] text-ovs-crm-text-primary">
                      {upcomingAppointment.date}, {upcomingAppointment.time}
                    </p>
                    <p className="text-[12px] text-ovs-crm-text-secondary">
                      {upcomingAppointment.practitioner_name} · {upcomingAppointment.branch_name}
                    </p>
                  </div>
                ) : (
                  <p className="text-[12.5px] text-ovs-crm-text-muted">No upcoming appointment.</p>
                )}
              </CrmCard>
            </div>

            <CrmSection title="Demographics" icon={UserCheck} hue={toneHex('slate')}>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="flex items-center gap-2 text-[13px] text-ovs-crm-text-secondary">
                  <Phone className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                  {patient.phone ?? 'No phone on file'}
                </div>
                <div className="flex items-center gap-2 text-[13px] text-ovs-crm-text-secondary">
                  <Mail className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                  {patient.email ?? 'No email on file'}
                </div>
                {patient.profile?.address && (
                  <div className="flex items-start gap-2 text-[13px] text-ovs-crm-text-secondary sm:col-span-2">
                    <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    {patient.profile.address}, {patient.profile.city} {patient.profile.state} {patient.profile.pincode}
                  </div>
                )}
                {patient.profile?.emergency_contact_name && (
                  <div className="flex items-center gap-2 text-[13px] text-ovs-crm-text-secondary sm:col-span-2">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    Emergency: {patient.profile.emergency_contact_name} ({patient.profile.emergency_contact_phone})
                  </div>
                )}
                {patient.allergies.length > 0 && (
                  <div className="flex items-center gap-2 text-[13px] text-ovs-crm-text-secondary sm:col-span-2">
                    <ShieldAlert className="h-3.5 w-3.5 shrink-0 text-ovs-crm-stage-coral-text" aria-hidden="true" />
                    Allergies: {patient.allergies.join(', ')}
                  </div>
                )}
                <div className="flex items-center gap-2 text-[13px] text-ovs-crm-text-secondary sm:col-span-2">
                  <Stethoscope className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                  {patient.current_practitioner ? `Assigned to ${patient.current_practitioner.name}` : 'No doctor assigned yet'}
                </div>
              </div>
            </CrmSection>

            <CrmSection title="Activity" description="Everything recorded for this patient, most recent first." icon={CalendarClock} hue={toneHex('blue')}>
              <PatientTimelineFeed events={timeline} limit={12} />
            </CrmSection>
          </>
        )}

        {activeTab === 'appointments' && (
          <CrmSection flush>
            {appointments.data.length === 0 ? (
              <CrmDataState kind="empty" compact icon={CalendarClock} title="No appointments yet" description="Appointments booked for this patient will appear here." />
            ) : (
              <div className="divide-y divide-ovs-crm-border/60">
                {appointments.data.map((appt) => (
                  <div key={appt.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                    <div className="flex min-w-0 items-center gap-3">
                      <CrmCardIcon icon={CalendarClock} hue={toneHex('violet')} />
                      <div className="min-w-0">
                        <p className="text-[13.5px] font-medium text-ovs-crm-text-primary">
                          {appt.scheduled_at ? new Date(appt.scheduled_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}
                        </p>
                        <p className="text-[12px] text-ovs-crm-text-muted">
                          {appt.practitioner?.name ?? 'Unassigned'} · {appt.branch?.name ?? 'Main Clinic'} · {appt.appointment_type ?? 'Consultation'}
                        </p>
                      </div>
                    </div>
                    <CrmStatusPill tone={APPOINTMENT_STATUS_TONE[appt.status] ?? 'neutral'}>{appt.status.replace(/_/g, ' ')}</CrmStatusPill>
                  </div>
                ))}
              </div>
            )}
            {appointments.links.length > 3 && (
              <CrmPagination links={appointments.links} from={appointments.from} to={appointments.to} total={appointments.total} className="px-5 py-4" />
            )}
          </CrmSection>
        )}

        {activeTab === 'visits' && (
          <CrmSection flush>
            {visits.data.length === 0 ? (
              <CrmDataState kind="empty" compact icon={Stethoscope} title="No visits yet" description="Clinical visits recorded for this patient will appear here." />
            ) : (
              <div className="divide-y divide-ovs-crm-border/60">
                {visits.data.map((visit) => (
                  <div key={visit.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                    <div className="flex min-w-0 items-center gap-3">
                      <CrmCardIcon icon={Stethoscope} hue={toneHex('teal')} />
                      <div className="min-w-0">
                        <p className="text-[13.5px] font-medium text-ovs-crm-text-primary">
                          {visit.visit_date ? new Date(visit.visit_date).toLocaleDateString('en-IN', { dateStyle: 'medium' }) : '—'}
                        </p>
                        <p className="text-[12px] text-ovs-crm-text-muted">
                          {visit.doctor?.name ?? 'Practitioner'} · {visit.branch?.name ?? 'Main Clinic'}
                        </p>
                      </div>
                    </div>
                    <CrmStatusPill tone={APPOINTMENT_STATUS_TONE[visit.visit_status ?? ''] ?? 'neutral'}>
                      {(visit.visit_status ?? 'recorded').replace(/_/g, ' ')}
                    </CrmStatusPill>
                  </div>
                ))}
              </div>
            )}
            {visits.links.length > 3 && (
              <CrmPagination links={visits.links} from={visits.from} to={visits.to} total={visits.total} className="px-5 py-4" />
            )}
          </CrmSection>
        )}

        {activeTab === 'clinical' && permissions.can_view_clinical && (
          <CrmSection title="Prescriptions" icon={Pill} hue={toneHex('teal')}>
            {prescriptions.items.data.length === 0 ? (
              <CrmDataState kind="empty" compact icon={Pill} title="No prescriptions yet" description="Prescriptions issued for this patient will appear here." />
            ) : (
              <div className="space-y-3">
                {prescriptions.items.data.map((rx) => (
                  <div key={rx.id} className="rounded-[16px] border border-ovs-crm-border/70 bg-white p-3.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-[13px] font-semibold text-ovs-crm-text-primary">{rx.prescription_number}</p>
                      <span className="text-[11.5px] text-ovs-crm-text-muted">{rx.created_at} · {rx.prescribed_by}</span>
                    </div>
                    <div className="mt-2 space-y-1">
                      {rx.items.map((item) => (
                        <p key={item.id} className="text-[12.5px] text-ovs-crm-text-secondary">
                          {item.name} — {item.dosage}, {item.frequency}, {item.duration}
                        </p>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
            {prescriptions.items.links.length > 3 && (
              <CrmPagination
                links={prescriptions.items.links}
                from={prescriptions.items.from}
                to={prescriptions.items.to}
                total={prescriptions.items.total}
                className="pt-4"
              />
            )}
          </CrmSection>
        )}

        {activeTab === 'billing' && permissions.can_view_billing && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <CrmCard>
                <p className="text-[11.5px] text-ovs-crm-text-muted">Total Billed</p>
                <p className="mt-1 text-[22px] font-light text-ovs-crm-text-primary">{inr(billing.summary.total_billed)}</p>
              </CrmCard>
              <CrmCard>
                <p className="text-[11.5px] text-ovs-crm-text-muted">Total Paid</p>
                <p className="mt-1 text-[22px] font-light text-ovs-crm-accent-green-text">{inr(billing.summary.total_paid)}</p>
              </CrmCard>
              <CrmCard>
                <p className="text-[11.5px] text-ovs-crm-text-muted">Balance Due</p>
                <p className="mt-1 text-[22px] font-light text-ovs-crm-accent-coral-text">{inr(billing.summary.total_balance)}</p>
              </CrmCard>
            </div>

            <CrmSection title="Invoices" icon={IndianRupee} hue={toneHex('green')}>
              {billing.invoices.data.length === 0 ? (
                <CrmDataState kind="empty" compact icon={IndianRupee} title="No invoices yet" description="Invoices raised for this patient will appear here." />
              ) : (
                <div className="space-y-2">
                  {billing.invoices.data.map((inv) => (
                    <div key={inv.id} className="flex flex-wrap items-center justify-between gap-3 rounded-[16px] px-2 py-2.5 hover:bg-ovs-crm-surface-inset">
                      <div className="flex min-w-0 items-center gap-3">
                        <CrmCardIcon icon={IndianRupee} hue={toneHex(inv.balance_due > 0 ? 'amber' : 'green')} />
                        <div>
                          <p className="text-[13px] font-medium text-ovs-crm-text-primary">{inv.invoice_number}</p>
                          <p className="text-[11.5px] text-ovs-crm-text-muted">{inv.issued_at}</p>
                        </div>
                      </div>
                      <div className="text-right">
                        <p className="text-[13px] font-medium text-ovs-crm-text-primary">{inr(inv.total_amount)}</p>
                        <Badge variant={inv.balance_due > 0 ? 'warning' : 'success'} size="compact">
                          {inv.balance_due > 0 ? `${inr(inv.balance_due)} due` : 'Paid'}
                        </Badge>
                      </div>
                    </div>
                  ))}
                </div>
              )}
              {billing.invoices.links.length > 3 && (
                <CrmPagination
                  links={billing.invoices.links}
                  from={billing.invoices.from}
                  to={billing.invoices.to}
                  total={billing.invoices.total}
                  className="pt-4"
                />
              )}
            </CrmSection>
          </div>
        )}

        {activeTab === 'documents' && (
          <div className="space-y-4">
            {permissions.can_manage_docs && (
              <CrmSection title="Upload a document" icon={Upload} hue={toneHex('amber')}>
                <form onSubmit={submitUpload} className="flex flex-wrap items-center gap-3">
                  <label className="crm-lift flex h-9 cursor-pointer items-center gap-2 rounded-full border border-ovs-crm-border bg-white px-3.5 text-[12.5px] font-medium text-ovs-crm-text-secondary shadow-ovs-crmPanel hover:text-ovs-crm-text-primary">
                    <Upload className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="max-w-[180px] truncate">{uploadForm.data.document?.name ?? 'Choose a file'}</span>
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png,.webp"
                      onChange={(e) => uploadForm.setData('document', e.target.files?.[0] ?? null)}
                      className="sr-only"
                    />
                  </label>
                  <select
                    value={uploadForm.data.document_type}
                    onChange={(e) => uploadForm.setData('document_type', e.target.value)}
                    className="h-9 rounded-[14px] border border-ovs-crm-border bg-white px-2.5 text-[12.5px]"
                  >
                    <option value="medical_report">Medical Report</option>
                    <option value="id_proof">ID Proof</option>
                    <option value="xray">X-Ray</option>
                    <option value="lab_report">Lab Report</option>
                    <option value="other">Other</option>
                  </select>
                  <CrmButton tone="primary" type="submit" disabled={! uploadForm.data.document || uploadForm.processing}>
                    Upload
                  </CrmButton>
                </form>
                {uploadForm.errors.document && <p className="mt-2 text-[12px] text-ovs-crm-danger-text">{uploadForm.errors.document}</p>}
              </CrmSection>
            )}

            <CrmSection flush>
              {documents.data.length === 0 ? (
                <CrmDataState kind="empty" compact icon={FileText} title="No documents yet" description="Reports, ID proofs and X-rays attached for this patient will appear here." />
              ) : (
                <div className="divide-y divide-ovs-crm-border/60">
                  {documents.data.map((doc) => (
                    <div key={doc.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                      <div className="flex items-center gap-3 min-w-0">
                        <CrmCardIcon icon={FileText} hue={toneHex('amber')} />
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">{doc.file_name}</p>
                          <p className="text-[11.5px] text-ovs-crm-text-muted">{doc.uploaded_by} · {doc.created_at}</p>
                        </div>
                      </div>
                      {doc.download_url ? (
                        <a href={doc.download_url} target="_blank" rel="noreferrer" className="text-[12px] font-medium text-ovs-crm-text-primary underline underline-offset-2">
                          Download
                        </a>
                      ) : (
                        <span className="text-[12px] font-medium text-ovs-crm-text-muted">Not available: awaiting malware scan</span>
                      )}
                    </div>
                  ))}
                </div>
              )}
              {documents.links.length > 3 && (
                <CrmPagination links={documents.links} from={documents.from} to={documents.to} total={documents.total} className="px-5 py-4" />
              )}
            </CrmSection>
          </div>
        )}
      </div>

      <PrintSummary patient={patient} timeline={timeline} />

      {permissions.can_transfer_patient && (
        <TransferPatientModal
          open={transferOpen}
          onClose={() => setTransferOpen(false)}
          patientId={patient.id}
          patientName={patient.name}
          currentPractitionerName={patient.current_practitioner?.name ?? null}
          practitioners={practitioners}
        />
      )}
    </ClinicShell>
  );
}
