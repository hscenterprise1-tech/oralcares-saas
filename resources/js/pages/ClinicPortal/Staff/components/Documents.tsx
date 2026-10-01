import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { BadgeCheck, ChevronDown, Download, FileText, RotateCcw, Upload, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Field, inputClass, Modal, selectClass, textareaClass } from '@/clinic/ui/Modal';
import type { ChecklistItem, ProfileProps, StaffDocumentVersion } from '../lib/types';
import { Chip, DOC_STATE, Empty, formatDate, relativeTime, Section } from '../lib/ui';

const REQUIREMENT: Record<string, string> = {
  required: 'Required',
  optional: 'Optional',
  not_applicable: 'Not needed',
};

/**
 * The staff member's documents against the clinic's checklist: what is
 * required, what is on file, what needs verifying or renewing. Files are
 * only ever downloaded through the authorised route.
 */
export function Documents({ props }: { props: ProfileProps }) {
  const { documents, documentTypes, can, links } = props;
  const [upload, setUpload] = useState<number | 'any' | null>(null);
  const [review, setReview] = useState<{ item: ChecklistItem; doc: StaffDocumentVersion } | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const { summary } = documents;
  const download = (doc: StaffDocumentVersion) => `${links.self}/documents/${doc.id}`;

  return (
    <div className="grid gap-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label="Required documents" value={`${summary.complete}/${summary.required}`} tone={summary.required > 0 && summary.complete === summary.required ? 'green' : 'amber'} />
        <Stat label="Need attention" value={String(summary.attention)} tone={summary.attention > 0 ? 'amber' : 'green'} />
        <Stat label="Missing required" value={summary.missing.length === 0 ? 'None' : String(summary.missing.length)} tone={summary.missing.length > 0 ? 'red' : 'green'} hint={summary.missing.join(', ')} />
      </div>

      <Section
        title="Documents"
        aside={can.edit && documentTypes.length > 0 && <Button size="sm" tone="primary" icon={Upload} onClick={() => setUpload('any')}>Upload document</Button>}
      >
        {documents.items.length === 0 ? (
          <Empty>No document types apply. Set them up in the document checklist.</Empty>
        ) : (
          <ul className="divide-y divide-clinic-border rounded-2xl ring-1 ring-clinic-border">
            {documents.items.map((item) => {
              const state = DOC_STATE[item.state];
              const current = item.current;
              const expanded = open === item.type.id;

              return (
                <li key={item.type.id} className="px-3.5 py-3">
                  <div className="flex flex-wrap items-center gap-3">
                    <span className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', item.state === 'verified' ? 'bg-emerald-100 text-emerald-700' : item.state === 'missing' ? 'bg-clinic-inset text-clinic-secondary' : item.state === 'pending_verification' ? 'bg-sky-100 text-sky-700' : 'bg-rose-100 text-rose-700')}>
                      {item.state === 'verified' ? <BadgeCheck className="h-5 w-5" aria-hidden="true" /> : <FileText className="h-5 w-5" aria-hidden="true" />}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[13.5px] font-bold text-clinic-ink">{item.type.name}</span>
                        <span className={cn('text-[11px] font-semibold', item.requirement === 'required' ? 'text-rose-700' : 'text-clinic-secondary')}>{REQUIREMENT[item.requirement]}</span>
                      </span>
                      <span className="block text-[12px] text-clinic-secondary">
                        {current
                          ? [current.title !== item.type.name ? current.title : null, current.expires_on ? `Expires ${formatDate(current.expires_on)}` : null, `Uploaded ${relativeTime(current.uploaded_at)}${current.uploaded_by ? ` by ${current.uploaded_by}` : ''}`].filter(Boolean).join(' · ')
                          : 'Not uploaded yet'}
                      </span>
                      {current?.review_note && (current.state === 'rejected' || current.replacement_requested) && (
                        <span className="mt-1 block text-[12px] font-medium text-rose-700">{current.replacement_requested ? 'Replacement requested: ' : 'Rejected: '}{current.review_note}</span>
                      )}
                    </span>
                    <Chip className={state.chip}>{state.label}</Chip>
                    <span className="flex shrink-0 items-center gap-1">
                      {current && (
                        <a href={download(current)} aria-label={`Download ${item.type.name}`} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink sm:h-9 sm:w-9">
                          <Download className="h-4 w-4" aria-hidden="true" />
                        </a>
                      )}
                      {can.verify && current && current.status === 'pending_verification' && (
                        <Button size="sm" onClick={() => setReview({ item, doc: current })}>Review</Button>
                      )}
                      {can.edit && (
                        <Button size="sm" tone="ghost" icon={current ? RotateCcw : Upload} onClick={() => setUpload(item.type.id)}>
                          {current ? 'Replace' : 'Upload'}
                        </Button>
                      )}
                      {item.history.length > 0 && (
                        <button type="button" aria-expanded={expanded} aria-label={`${expanded ? 'Hide' : 'Show'} earlier versions of ${item.type.name}`} onClick={() => setOpen(expanded ? null : item.type.id)} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset sm:h-9 sm:w-9">
                          <ChevronDown className={cn('h-4 w-4 motion-safe:transition-transform', expanded && 'rotate-180')} aria-hidden="true" />
                        </button>
                      )}
                    </span>
                  </div>
                  {expanded && (
                    <ul className="mt-2 space-y-1 rounded-xl bg-clinic-canvas p-2">
                      {item.history.map((version) => (
                        <li key={version.id} className="flex flex-wrap items-center gap-2 px-1.5 py-1 text-[12px] text-clinic-secondary">
                          <span className="font-semibold text-clinic-ink">{version.title}</span>
                          <span>{relativeTime(version.uploaded_at)}</span>
                          <Chip className={DOC_STATE[version.state]?.chip ?? DOC_STATE.missing.chip}>{version.status === 'replaced' ? 'Replaced' : DOC_STATE[version.state]?.label}</Chip>
                          <a href={download(version)} className="ml-auto inline-flex min-h-11 items-center gap-1 font-semibold text-clinic-brand-strong hover:underline sm:min-h-0">
                            <Download className="h-3.5 w-3.5" aria-hidden="true" /> Download
                          </a>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Section>

      {upload !== null && <UploadDialog props={props} typeId={upload === 'any' ? null : upload} onClose={() => setUpload(null)} />}
      {review && <ReviewDialog props={props} review={review} onClose={() => setReview(null)} />}
    </div>
  );
}

function Stat({ label, value, tone, hint }: { label: string; value: string; tone: 'green' | 'amber' | 'red'; hint?: string }) {
  const tones = { green: 'from-emerald-50 to-teal-50 text-emerald-900', amber: 'from-amber-50 to-orange-50 text-amber-900', red: 'from-rose-50 to-orange-50 text-rose-900' };

  return (
    <div className={cn('rounded-3xl border border-white/70 bg-gradient-to-br p-4', tones[tone])}>
      <p className="text-[12px] font-semibold opacity-80">{label}</p>
      <p className="mt-1 text-[22px] font-bold tabular-nums leading-none">{value}</p>
      {hint && <p className="mt-1.5 line-clamp-2 text-[11.5px] opacity-80">{hint}</p>}
    </div>
  );
}

function UploadDialog({ props, typeId, onClose }: { props: ProfileProps; typeId: number | null; onClose: () => void }) {
  const { documentTypes, options, links } = props;
  const [type, setType] = useState<string>(typeId ? String(typeId) : String(documentTypes[0]?.id ?? ''));
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState('');
  const [issued, setIssued] = useState('');
  const [expires, setExpires] = useState('');
  const [notes, setNotes] = useState('');
  const [progress, setProgress] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const chosen = documentTypes.find((item) => String(item.id) === type);
  const maxMb = Math.round(options.upload.max_kb / 1024);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!file) {
      setErrors({ file: 'Choose a file.' });

      return;
    }
    if (file.size > options.upload.max_kb * 1024) {
      setErrors({ file: `The file is larger than ${maxMb} MB.` });

      return;
    }
    setProgress(0);
    router.post(
      `${links.self}/documents`,
      { document_type_id: Number(type), file, title: title || null, issued_on: issued || null, expires_on: expires || null, notes: notes || null },
      {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (event) => setProgress(Math.round(event?.percentage ?? 0)),
        onSuccess: onClose,
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setProgress(null),
      },
    );
  };

  return (
    <Modal
      open
      onOpenChange={(value) => !value && progress === null && onClose()}
      title="Upload document"
      description={`PDF or image (${options.upload.mimes.join(', ')}), up to ${maxMb} MB. Every file is scanned before it is stored.`}
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={progress !== null}>Cancel</Button>
          <Button tone="primary" type="submit" form="staff-document-upload" icon={Upload} loading={progress !== null} loadingLabel={`Uploading ${progress ?? 0}%`}>Upload</Button>
        </>
      }
    >
      <form id="staff-document-upload" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        <Field label="Document type" htmlFor="du-type" error={errors.document_type_id} className="sm:col-span-2">
          <select id="du-type" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
            {documentTypes.map((item) => (
              <option key={item.id} value={item.id}>{item.name}</option>
            ))}
          </select>
        </Field>
        <Field label="File" htmlFor="du-file" error={errors.file} className="sm:col-span-2">
          <input
            id="du-file"
            type="file"
            accept={options.upload.mimes.map((mime) => `.${mime}`).join(',')}
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="block w-full rounded-xl border border-dashed border-clinic-border-strong bg-clinic-canvas px-3 py-2.5 text-[13px] file:mr-3 file:rounded-full file:border-0 file:bg-clinic-ink file:px-3.5 file:py-2 file:text-[12.5px] file:font-semibold file:text-white"
          />
        </Field>
        {progress !== null && (
          <div className="sm:col-span-2" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label="Upload progress">
            <div className="h-2 overflow-hidden rounded-full bg-clinic-inset">
              <div className="h-full rounded-full bg-clinic-brand transition-[width]" style={{ width: `${progress}%` }} />
            </div>
          </div>
        )}
        <Field label="Title (optional)" htmlFor="du-title" error={errors.title} className="sm:col-span-2">
          <input id="du-title" className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={chosen?.name} />
        </Field>
        <Field label="Issue date (optional)" htmlFor="du-issued" error={errors.issued_on}>
          <input id="du-issued" type="date" className={inputClass} value={issued} onChange={(e) => setIssued(e.target.value)} />
        </Field>
        <Field label={chosen?.has_expiry ? 'Expiry date' : 'Expiry date (optional)'} htmlFor="du-expires" error={errors.expires_on}>
          <input id="du-expires" type="date" className={inputClass} value={expires} onChange={(e) => setExpires(e.target.value)} />
        </Field>
        <Field label="Notes (optional)" htmlFor="du-notes" error={errors.notes} className="sm:col-span-2">
          <textarea id="du-notes" rows={2} maxLength={1000} className={textareaClass} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </form>
    </Modal>
  );
}

function ReviewDialog({ props, review, onClose }: { props: ProfileProps; review: { item: ChecklistItem; doc: StaffDocumentVersion }; onClose: () => void }) {
  const { links } = props;
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const url = `${links.self}/documents/${review.doc.id}`;

  const send = (action: 'verify' | 'reject' | 'replace') => {
    if (action !== 'verify' && reason.trim() === '') {
      setError('Say what is wrong, so they know what to fix.');

      return;
    }
    setBusy(action);
    setError(null);
    router.post(
      action === 'verify' ? `${url}/verify` : `${url}/reject`,
      action === 'verify' ? {} : { reason, request_replacement: action === 'replace' },
      { preserveScroll: true, onSuccess: onClose, onError: (errors) => setError(Object.values(errors)[0] ?? 'This could not be saved.'), onFinish: () => setBusy(null) },
    );
  };

  return (
    <Modal
      open
      onOpenChange={(value) => !value && onClose()}
      title={`Review ${review.item.type.name}`}
      description={`${review.doc.title} · uploaded ${relativeTime(review.doc.uploaded_at)}${review.doc.uploaded_by ? ` by ${review.doc.uploaded_by}` : ''}`}
      footer={
        <>
          <Button tone="ghost" icon={XCircle} loading={busy === 'reject'} onClick={() => send('reject')} className="text-clinic-danger hover:bg-clinic-danger-soft">Reject</Button>
          <Button icon={RotateCcw} loading={busy === 'replace'} onClick={() => send('replace')}>Request replacement</Button>
          <Button tone="primary" icon={BadgeCheck} loading={busy === 'verify'} onClick={() => send('verify')}>Verify</Button>
        </>
      }
    >
      <a href={`${url}`} className="mb-4 flex min-h-11 items-center gap-2 rounded-2xl bg-clinic-canvas px-3.5 py-3 text-[13px] font-semibold text-clinic-brand-strong hover:underline">
        <Download className="h-4 w-4" aria-hidden="true" /> Open {review.doc.file_name}
      </a>
      <dl className="mb-4 grid gap-3 sm:grid-cols-2">
        <div>
          <dt className="text-[11.5px] font-semibold text-clinic-secondary">Issued</dt>
          <dd className="text-[13px] text-clinic-ink">{formatDate(review.doc.issued_on) || '—'}</dd>
        </div>
        <div>
          <dt className="text-[11.5px] font-semibold text-clinic-secondary">Expires</dt>
          <dd className="text-[13px] text-clinic-ink">{formatDate(review.doc.expires_on) || '—'}</dd>
        </div>
      </dl>
      <Field label="Reason (needed to reject or request a replacement)" htmlFor="rv-reason" error={error}>
        <textarea id="rv-reason" rows={2} maxLength={500} className={textareaClass} value={reason} onChange={(e) => setReason(e.target.value)} />
      </Field>
    </Modal>
  );
}
