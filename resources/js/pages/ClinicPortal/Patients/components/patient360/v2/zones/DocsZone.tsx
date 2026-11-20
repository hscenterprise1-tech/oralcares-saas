import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Download, Eye, FileImage, FileSignature, FileText, FlaskConical, Pill as PillIcon, Printer, Upload } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { RecordConsent, RevokeConsent, type ConsentRow, type ConsentsPayload } from '../../RecordSections';
import { RemoveDocumentDialog, type DocumentRow } from '../../Sections';
import type { Patient360Props } from '../../types';
import { useEndpoint } from '../store';
import { BTN_SM, Card, Chips, Empty, Pager, Pending, Pill, Split, day, humanize, type Paged } from '../ui';

type DocumentsPage = Paged<DocumentRow> & { upload: { url: string; types: { value: string; label: string }[]; max_mb: number; accept: string } | null };
type PrescriptionRow = {
  id: number;
  number: string | null;
  status: string;
  date: string | null;
  practitioner: string | null;
  notes: string | null;
  cancellation_reason: string | null;
  consultation: string | null;
  items: { name: string; strength: string | null; form: string | null; dosage: string | null; frequency: string | null; duration: string | null; timing: string | null; instructions: string | null }[];
  print_url: string | null;
  pdf_url: string | null;
};
type InvestigationRow = {
  id: number;
  name: string;
  category: string;
  status: string;
  order_number: string | null;
  date: string | null;
  practitioner?: string | null;
  result?: { summary: string | null; values: { name: string; value: string; unit?: string; flag?: string }[]; is_abnormal: boolean; final: boolean; review_note: string | null } | null;
  attachments?: { name: string; url: string }[];
};

const ORDER: Record<string, [string, 'slate' | 'blue' | 'amber' | 'mint' | 'teal']> = {
  ordered: ['Ordered', 'slate'],
  collected: ['Sample collected', 'blue'],
  resulted: ['Result in, not reviewed', 'amber'],
  reviewed: ['Reviewed', 'mint'],
  result_in: ['Result in', 'teal'],
  cancelled: ['Cancelled', 'slate'],
};

function sizeOf(bytes: number | null): string | null {
  if (!bytes) {
    return null;
  }

  return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function Documents({ endpoint, governance }: { endpoint: string; governance: { request: string; direct: string } | null }) {
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState<string>('all');
  const [uploading, setUploading] = useState(false);
  const [removing, setRemoving] = useState<DocumentRow | null>(null);
  const state = useEndpoint<DocumentsPage>(endpoint, page > 1 ? { page } : undefined);
  const rows = (state.data?.data ?? []).filter((doc) => category === 'all' || doc.category === category);
  const categories = Array.from(new Set((state.data?.data ?? []).map((doc) => doc.category)));

  return (
    <Card
      title="Documents"
      caption={state.data ? `${state.data.meta.total} on the record · scanned for malware before anyone can open them` : undefined}
      icon={FileText}
      tone="blue"
      action={
        state.data?.upload ? (
          <button type="button" className={BTN_SM} onClick={() => setUploading(true)}>
            <Upload className="h-3.5 w-3.5" aria-hidden="true" /> Upload
          </button>
        ) : undefined
      }
    >
      {categories.length > 1 && (
        <div className="mb-3">
          <Chips
            label="Category"
            value={category}
            onChange={setCategory}
            options={[{ value: 'all', label: 'All', count: state.data?.data.length }, ...categories.map((value) => ({ value, label: value, count: (state.data?.data ?? []).filter((doc) => doc.category === value).length }))]}
          />
        </div>
      )}
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : rows.length === 0 ? (
        <Empty>No documents{category !== 'all' ? ' in this category' : ''}.</Empty>
      ) : (
        <ul className="grid auto-rows-fr gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {rows.map((doc) => {
            const image = /image|jpg|jpeg|png|webp/i.test(doc.file_type);

            return (
              <li key={doc.id} className="flex flex-col rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-4">
                <div className="flex items-start gap-3">
                  <span className={cn('relative grid h-12 w-11 shrink-0 place-items-center rounded-[12px]', image ? 'bg-cx-violet-bg text-cx-violet-ink' : 'bg-cx-blue-bg text-cx-blue-ink')} aria-hidden="true">
                    {image ? <FileImage className="h-5 w-5" /> : <FileText className="h-5 w-5" />}
                    <span className="absolute -bottom-1.5 rounded-md bg-white px-1 text-[9px] font-bold uppercase tracking-wide shadow-sm">{doc.file_type.split('/').pop()?.slice(0, 4)}</span>
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="line-clamp-2 text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{doc.name}</p>
                    <p className="mt-1 flex flex-wrap items-center gap-1.5">
                      <Pill tone={image ? 'violet' : 'blue'}>{doc.category}</Pill>
                      {doc.status !== 'clean' && doc.status !== 'cleared' && <Pill tone="amber">{humanize(doc.status)}</Pill>}
                    </p>
                  </div>
                </div>
                <dl className="mt-3 flex-1 space-y-0.5 text-[12px]">
                  {(
                    [
                      ['Uploaded', [day(doc.date), sizeOf(doc.size)].filter(Boolean).join(' · ')],
                      ['By', doc.uploaded_by],
                      ['Source', doc.source],
                    ] as const
                  )
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                      <div key={label} className="flex gap-2">
                        <dt className="w-16 shrink-0 text-cx-text-3">{label}</dt>
                        <dd className="min-w-0 text-cx-text-2 [overflow-wrap:anywhere]">{value}</dd>
                      </div>
                    ))}
                </dl>
                <div className="mt-3 flex flex-wrap gap-1.5 border-t border-[#EDF1F7] pt-3">
                  {doc.preview_url && (
                    <a href={doc.preview_url} target="_blank" rel="noopener" className={BTN_SM}>
                      <Eye className="h-3.5 w-3.5" aria-hidden="true" /> Preview
                    </a>
                  )}
                  {doc.download_url && (
                    <a href={doc.download_url} className={BTN_SM}>
                      <Download className="h-3.5 w-3.5" aria-hidden="true" /> Download
                    </a>
                  )}
                  {doc.remove_mode && governance && (
                    <button type="button" className={cn(BTN_SM, 'text-cx-rose-ink')} onClick={() => setRemoving(doc)}>
                      {doc.remove_mode === 'direct' ? 'Remove' : 'Ask to remove'}
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {state.data && <Pager page={state.data.meta.page} last={state.data.meta.last_page} onPage={setPage} busy={state.loading} />}
      {uploading && state.data?.upload && <UploadDialog upload={state.data.upload} onClose={() => setUploading(false)} onDone={() => { setUploading(false); void state.reload(); }} />}
      {removing && governance && removing.remove_mode && (
        <RemoveDocumentDialog doc={removing} mode={removing.remove_mode} governance={governance} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); void state.reload(); }} />
      )}
    </Card>
  );
}

function UploadDialog({ upload, onClose, onDone }: { upload: NonNullable<DocumentsPage['upload']>; onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [type, setType] = useState(upload.types[0]?.value ?? 'other');
  const [title, setTitle] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement | null>(null);
  const submit = () => {
    if (!file) {
      return;
    }
    if (file.size > upload.max_mb * 1024 * 1024) {
      setProblem(`The file is larger than ${upload.max_mb} MB.`);

      return;
    }
    router.post(upload.url, { document: file, document_type: type, title: title.trim() || null } as never, {
      preserveScroll: true,
      preserveState: true,
      forceFormData: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'The file was not uploaded.'),
      onSuccess: onDone,
    });
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title="Upload a document"
      description={`PDF or image, up to ${upload.max_mb} MB. It opens once the malware check passes.`}
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={!file} onClick={submit}>Upload</Button>
        </>
      }
    >
      <div className="space-y-3">
        <button type="button" onClick={() => input.current?.click()} className="flex w-full flex-col items-center gap-1 rounded-2xl border-2 border-dashed border-[#CFE3F7] bg-cx-blue-bg/40 px-4 py-6 text-center text-[13px] text-cx-text-2 hover:bg-cx-blue-bg/70">
          <Upload className="h-6 w-6 text-cx-blue-ink" aria-hidden="true" />
          <span className="font-semibold text-cx-nav-ink">{file ? file.name : 'Choose a file'}</span>
          <span className="text-[12px]">{file ? sizeOf(file.size) : upload.accept}</span>
        </button>
        <input ref={input} type="file" accept={upload.accept} className="sr-only" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Type" htmlFor="up-type">
            <Select id="up-type" value={type} onChange={(event) => setType(event.target.value)} options={upload.types} />
          </Field>
          <Field label="Title (optional)" htmlFor="up-title">
            <Input id="up-title" value={title} maxLength={255} onChange={(event) => setTitle(event.target.value)} />
          </Field>
        </div>
        {problem && <p role="alert" className="text-[12.5px] font-medium text-cx-rose-ink">{problem}</p>}
      </div>
    </Modal>
  );
}

function Prescriptions({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const state = useEndpoint<Paged<PrescriptionRow>>(endpoint, page > 1 ? { page } : undefined);

  return (
    <Card title="Prescriptions" icon={PillIcon} tone="teal">
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No prescriptions.</Empty>
      ) : (
        <ul className="space-y-3">
          {state.data.data.map((rx) => (
            <li key={rx.id} className="rounded-2xl bg-cx-teal-bg/30 p-3.5">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-[13.5px] font-semibold text-cx-nav-ink">{[rx.number, day(rx.date)].filter(Boolean).join(' · ')}</p>
                  <p className="text-[12px] text-cx-text-2">{[rx.practitioner, rx.consultation ? `Consultation ${rx.consultation}` : null].filter(Boolean).join(' · ')}</p>
                </div>
                <span className="flex flex-wrap gap-1.5">
                  <Pill tone={rx.status === 'cancelled' ? 'slate' : 'mint'}>{humanize(rx.status)}</Pill>
                  {rx.pdf_url && (
                    <a href={rx.pdf_url} target="_blank" rel="noopener" className={BTN_SM}>
                      <Eye className="h-3.5 w-3.5" aria-hidden="true" /> View
                    </a>
                  )}
                  {rx.print_url && (
                    <a href={rx.print_url} target="_blank" rel="noopener" className={BTN_SM}>
                      <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Print
                    </a>
                  )}
                </span>
              </div>
              <ul className="mt-2 divide-y divide-white/80 rounded-xl bg-white/80">
                {rx.items.map((item, index) => (
                  <li key={index} className="px-3 py-2 text-[12.5px]">
                    <span className="font-semibold text-cx-nav-ink">{[item.name, item.strength, item.form].filter(Boolean).join(' ')}</span>
                    <span className="block text-cx-text-2">{[item.dosage, item.frequency, item.duration, item.timing ? humanize(item.timing) : null, item.instructions].filter(Boolean).join(' · ')}</span>
                  </li>
                ))}
              </ul>
              {rx.cancellation_reason && <p className="mt-1.5 text-[12px] text-cx-text-3">Cancelled: {rx.cancellation_reason}</p>}
            </li>
          ))}
        </ul>
      )}
      {state.data && <Pager page={state.data.meta.page} last={state.data.meta.last_page} onPage={setPage} busy={state.loading} />}
    </Card>
  );
}

function Reports({ endpoint }: { endpoint: string }) {
  const [pending, setPending] = useState(false);
  const state = useEndpoint<Paged<InvestigationRow>>(endpoint, pending ? { status: 'pending' } : undefined);

  return (
    <Card
      title="Reports & investigations"
      icon={FlaskConical}
      tone="violet"
      action={
        <button type="button" aria-pressed={pending} onClick={() => setPending((value) => !value)} className={cn(BTN_SM, pending && 'border-transparent bg-cx-violet-bg text-cx-violet-ink')}>
          Awaited only
        </button>
      }
    >
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No investigations{pending ? ' awaited' : ''}.</Empty>
      ) : (
        <ul className="space-y-2.5">
          {state.data.data.map((row) => {
            const [label, tone] = ORDER[row.status] ?? [humanize(row.status), 'slate' as const];

            return (
              <li key={row.id} className="rounded-xl bg-cx-slate-bg/50 px-3 py-2.5">
                <p className="flex flex-wrap items-center justify-between gap-2 text-[13px] font-semibold text-cx-nav-ink">
                  {row.name}
                  <span className="flex gap-1.5">
                    {row.result?.is_abnormal && <Pill tone="rose">Abnormal</Pill>}
                    <Pill tone={tone}>{label}</Pill>
                  </span>
                </p>
                <p className="text-[12px] text-cx-text-2">{[row.category, day(row.date) ? `ordered ${day(row.date)}` : null, row.practitioner ? `by ${row.practitioner}` : null].filter(Boolean).join(' · ')}</p>
                {row.result && (
                  <div className="mt-1.5 text-[12.5px] text-cx-text">
                    {row.result.summary && <p>{row.result.summary}</p>}
                    {row.result.values.length > 0 && (
                      <p className="text-cx-text-2">{row.result.values.map((value) => `${value.name}: ${value.value}${value.unit ? ` ${value.unit}` : ''}${value.flag ? ` (${value.flag})` : ''}`).join(' · ')}</p>
                    )}
                  </div>
                )}
                {(row.attachments ?? []).length > 0 && (
                  <p className="mt-1.5 flex flex-wrap gap-1.5">
                    {row.attachments!.map((file) => (
                      <a key={file.url} href={file.url} target="_blank" rel="noopener" className={BTN_SM}>
                        <FileText className="h-3.5 w-3.5" aria-hidden="true" /> {file.name}
                      </a>
                    ))}
                  </p>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function Consents({ endpoint }: { endpoint: string }) {
  const state = useEndpoint<ConsentsPayload>(endpoint);
  const [recording, setRecording] = useState(false);
  const [revoking, setRevoking] = useState<ConsentRow | null>(null);
  const label = (key: string) => state.data?.options.find((option) => option.key === key)?.label ?? humanize(key);

  return (
    <Card
      title="Consents"
      icon={FileSignature}
      tone="amber"
      action={
        state.data?.can_manage ? (
          <span className="flex flex-wrap gap-1.5">
            <a href={`${endpoint}/print`} target="_blank" rel="noopener" className={BTN_SM}>Print consent form</a>
            <button type="button" className={BTN_SM} onClick={() => setRecording(true)}>Record consent</button>
          </span>
        ) : undefined
      }
    >
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No consents recorded.</Empty>
      ) : (
        <ul className="space-y-2">
          {state.data.data.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-cx-slate-bg/50 px-3 py-2.5">
              <span className="min-w-0 text-[13px]">
                <span className="block font-semibold text-cx-nav-ink">
                  {label(row.type)}
                  {row.version ? <span className="font-normal text-cx-text-2"> · v{row.version}</span> : null}
                </span>
                <span className="block text-[12px] text-cx-text-2">{[row.method ? humanize(row.method) : null, row.captured_by, day(row.captured_at)].filter(Boolean).join(' · ')}</span>
                {row.revoked_at && <span className="block text-[12px] text-cx-text-3">Withdrawn {day(row.revoked_at)}{row.revocation_reason ? ` — ${row.revocation_reason}` : ''}</span>}
              </span>
              <span className="flex items-center gap-1.5">
                <Pill tone={row.status === 'revoked' ? 'slate' : row.status === 'granted' ? 'mint' : 'amber'}>{row.status === 'revoked' ? 'Withdrawn' : humanize(row.status)}</Pill>
                {state.data!.can_manage && row.status !== 'revoked' && (
                  <button type="button" className={BTN_SM} onClick={() => setRevoking(row)}>Withdraw</button>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
      {recording && state.data && <RecordConsent payload={state.data} endpoint={endpoint} onClose={() => setRecording(false)} onDone={() => { setRecording(false); void state.reload(); }} />}
      {revoking && <RevokeConsent row={revoking} label={label(revoking.type)} endpoint={endpoint} onClose={() => setRevoking(null)} onDone={() => { setRevoking(null); void state.reload(); }} />}
    </Card>
  );
}

/**
 * Docs (zone spec §5): documents by category with upload, then prescriptions
 * beside investigations and consents. Patient-wide: no document is shown as a
 * case's unless the server links it.
 */
export function DocsZone({ props, sections }: { props: Patient360Props; sections: string[] }) {
  const { endpoints } = props;
  const governance = endpoints.governance_request && endpoints.governance_direct ? { request: endpoints.governance_request, direct: endpoints.governance_direct } : null;
  const rest = ['prescriptions', 'investigations', 'consents'].filter((key) => sections.includes(key) && endpoints[key]);

  return (
    <div className="space-y-5">
      {sections.includes('documents') && endpoints.documents && <Documents endpoint={endpoints.documents} governance={governance} />}
      <Split
        mainSpan={6}
        main={rest.includes('prescriptions') ? <Prescriptions endpoint={endpoints.prescriptions as string} /> : null}
        side={[
          rest.includes('investigations') && <Reports key="reports" endpoint={endpoints.investigations as string} />,
          rest.includes('consents') && <Consents key="consents" endpoint={endpoints.consents as string} />,
        ]}
      />
    </div>
  );
}
