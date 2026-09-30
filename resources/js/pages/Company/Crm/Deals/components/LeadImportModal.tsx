import React from 'react';
import { router } from '@inertiajs/react';
import { CrmModal, CrmButton, CrmStatusPill, type CrmStatusTone } from '@/pages/Company/design';
import { Upload, ArrowRight, Loader2, CheckCircle2, AlertTriangle, Users } from 'lucide-react';

/*
 * Bulk lead creation from a CSV file — see `LeadImportService` for the
 * exact rules this preview/process pair is a window onto. Same
 * upload → preview → process → poll shape as Marketing's contact importer,
 * rebuilt on the CRM design kit rather than copying its markup.
 */

export interface LeadImportModalProps {
  open: boolean;
  onClose: () => void;
}

interface PreviewRow {
  row_number: number;
  status: 'valid' | 'duplicate' | 'invalid';
  reason: string | null;
  company_name: string;
  contact_name: string;
  phone: string;
  city: string;
  healthcare_specialty: string;
  email: string;
}

interface PreviewData {
  total_rows: number;
  valid_rows: number;
  duplicate_rows: number;
  invalid_rows: number;
  columns: string[];
  missing_columns: string[];
  preview: PreviewRow[];
  rows: PreviewRow[];
}

interface ImportResult {
  imported_count: number;
  duplicate_count: number;
  invalid_count: number;
}

const ROW_STATUS: Record<PreviewRow['status'], { label: string; tone: CrmStatusTone }> = {
  valid: { label: 'New', tone: 'good' },
  duplicate: { label: 'Already on file', tone: 'warn' },
  invalid: { label: 'Cannot be imported', tone: 'bad' },
};

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
}

export function LeadImportModal({ open, onClose }: LeadImportModalProps) {
  const [step, setStep] = React.useState<'upload' | 'preview' | 'processing' | 'result'>('upload');
  const [file, setFile] = React.useState<File | null>(null);
  const [preview, setPreview] = React.useState<PreviewData | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [result, setResult] = React.useState<ImportResult | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const pollRef = React.useRef<ReturnType<typeof setInterval> | null>(null);

  React.useEffect(() => () => {
    if (pollRef.current) clearInterval(pollRef.current);
  }, []);

  const reset = () => {
    if (pollRef.current) clearInterval(pollRef.current);
    setStep('upload');
    setFile(null);
    setPreview(null);
    setResult(null);
    setError(null);
  };

  const handlePreview = async () => {
    if (!file) return;
    setBusy(true);
    setError(null);

    const formData = new FormData();
    formData.append('file', file);

    try {
      const res = await fetch(route('company.crm.leads.import.preview'), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken() },
        body: formData,
      });

      if (!res.ok) {
        throw new Error('Could not read that file. Check it has company_name, contact_name, phone, city and healthcare_specialty columns.');
      }

      setPreview(await res.json());
      setStep('preview');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error previewing the file.');
    } finally {
      setBusy(false);
    }
  };

  const pollStatus = (importId: number) => {
    pollRef.current = setInterval(async () => {
      try {
        const res = await fetch(route('company.crm.leads.import.status', importId), {
          headers: { 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();

        if (json.status === 'completed') {
          if (pollRef.current) clearInterval(pollRef.current);
          setResult(json.result);
          setStep('result');
          router.reload({ only: ['deals', 'queues'] });
        } else if (json.status === 'failed') {
          if (pollRef.current) clearInterval(pollRef.current);
          setError(json.error_message || 'Import failed while processing in the background.');
          setStep('preview');
        }
      } catch {
        // transient network hiccup — keep polling
      }
    }, 2500);
  };

  const handleProcess = async () => {
    if (!preview) return;
    setBusy(true);
    setError(null);

    const rows = preview.rows.filter((r) => r.status !== 'invalid');

    try {
      const res = await fetch(route('company.crm.leads.import.process'), {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ rows, original_filename: file?.name }),
      });

      if (!res.ok) {
        throw new Error('Failed to start the import.');
      }

      const json = await res.json();

      if (json.queued) {
        setStep('processing');
        pollStatus(json.import_id);
      } else {
        setResult(json.result);
        setStep('result');
        router.reload({ only: ['deals', 'queues'] });
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error running the import.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <CrmModal
      open={open}
      onClose={() => {
        reset();
        onClose();
      }}
      size="lg"
      title="Import deals"
      description="A CSV with company_name, contact_name, phone, city and healthcare_specialty columns. Anyone already on file is flagged rather than duplicated."
      footer={
        step === 'upload' ? (
          <>
            <CrmButton onClick={onClose}>Cancel</CrmButton>
            <CrmButton tone="primary" icon={ArrowRight} loading={busy} disabled={!file} onClick={handlePreview}>
              Preview
            </CrmButton>
          </>
        ) : step === 'preview' && preview ? (
          <>
            <CrmButton onClick={() => setStep('upload')}>Back</CrmButton>
            <CrmButton tone="primary" loading={busy} disabled={preview.valid_rows === 0} onClick={handleProcess}>
              Import {preview.valid_rows} new deal{preview.valid_rows === 1 ? '' : 's'}
            </CrmButton>
          </>
        ) : step === 'result' ? (
          <CrmButton
            tone="primary"
            onClick={() => {
              reset();
              onClose();
            }}
          >
            Done
          </CrmButton>
        ) : undefined
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="flex items-center gap-2 rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset px-3 py-2.5 text-[12px] text-ovs-crm-stage-rose">
            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{error}</span>
          </div>
        )}

        {step === 'upload' && (
          <div className="space-y-3 rounded-2xl border-2 border-dashed border-ovs-crm-border bg-ovs-crm-surface p-6 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-ovs-crm-surface-inset text-ovs-crm-text-primary">
              <Upload className="h-6 w-6" aria-hidden="true" />
            </div>
            <div>
              <p className="text-[12.5px] font-semibold text-ovs-crm-text-primary">
                {file ? file.name : 'Select a CSV file to upload'}
              </p>
              <p className="mt-1 text-[11.5px] text-ovs-crm-text-secondary">
                Required columns: company_name, contact_name, phone, city, healthcare_specialty. Optional: email.
              </p>
            </div>
            <input
              type="file"
              aria-label="Choose a CSV file"
              accept=".csv,.txt"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null);
                setError(null);
              }}
              className="mx-auto min-w-0 max-w-xs text-[11.5px] text-ovs-crm-text-secondary file:mr-2 file:rounded-full file:border-0 file:bg-ovs-crm-surface-inset file:px-3 file:py-1.5 file:text-[11.5px] file:text-ovs-crm-text-primary"
            />
          </div>
        )}

        {step === 'preview' && preview && (
          <div className="space-y-3">
            {preview.missing_columns.length > 0 && (
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset px-3 py-2.5 text-[12px] text-ovs-crm-stage-rose">
                Missing column{preview.missing_columns.length === 1 ? '' : 's'}: {preview.missing_columns.join(', ')}
              </div>
            )}

            <div className="grid grid-cols-4 gap-2 text-[11.5px]">
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface p-3">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-ovs-crm-text-muted">Total rows</p>
                <p className="mt-0.5 text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">{preview.total_rows}</p>
              </div>
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface p-3">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-ovs-crm-text-muted">New</p>
                <p className="mt-0.5 text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">{preview.valid_rows}</p>
              </div>
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface p-3">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-ovs-crm-text-muted">Duplicates</p>
                <p className="mt-0.5 text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">{preview.duplicate_rows}</p>
              </div>
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface p-3">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-ovs-crm-text-muted">Invalid</p>
                <p className="mt-0.5 text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">{preview.invalid_rows}</p>
              </div>
            </div>

            <div className="max-h-72 overflow-y-auto rounded-xl border border-ovs-crm-border">
              <table className="w-full text-left text-[11.5px]">
                <thead className="sticky top-0 border-b border-ovs-crm-border bg-ovs-crm-surface-inset text-ovs-crm-text-muted">
                  <tr>
                    <th className="p-2 font-semibold">Company</th>
                    <th className="p-2 font-semibold">Contact</th>
                    <th className="p-2 font-semibold">Phone</th>
                    <th className="p-2 font-semibold">Status</th>
                    <th className="p-2 font-semibold">Detail</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ovs-crm-border bg-ovs-crm-surface">
                  {preview.preview.map((row) => {
                    const badge = ROW_STATUS[row.status];
                    return (
                      <tr key={row.row_number}>
                        <td className="p-2 font-medium text-ovs-crm-text-primary">{row.company_name || '—'}</td>
                        <td className="p-2 text-ovs-crm-text-secondary">{row.contact_name || '—'}</td>
                        <td className="p-2 text-ovs-crm-text-secondary">{row.phone || '—'}</td>
                        <td className="p-2">
                          <CrmStatusPill tone={badge.tone}>{badge.label}</CrmStatusPill>
                        </td>
                        <td className="p-2 text-ovs-crm-text-muted">{row.reason || ''}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {preview.rows.length > preview.preview.length && (
              <p className="text-[11px] text-ovs-crm-text-muted">
                Showing the first {preview.preview.length} of {preview.rows.length} rows.
              </p>
            )}
          </div>
        )}

        {step === 'processing' && (
          <div className="space-y-3 py-8 text-center">
            <Loader2 className="mx-auto h-8 w-8 animate-spin text-ovs-crm-text-primary" aria-hidden="true" />
            <div>
              <h3 className="text-[14px] font-semibold text-ovs-crm-text-primary">Importing in the background</h3>
              <p className="mt-1 text-[12px] text-ovs-crm-text-secondary">
                This file is large, so it&apos;s processing behind the scenes. You can leave this open or close it — the pipeline updates when it&apos;s done.
              </p>
            </div>
          </div>
        )}

        {step === 'result' && result && (
          <div className="space-y-3 py-4 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-stage-emerald">
              <CheckCircle2 className="h-6 w-6" aria-hidden="true" />
            </div>
            <div>
              <h3 className="text-[14px] font-semibold text-ovs-crm-text-primary">Import completed</h3>
              <p className="mt-1 flex items-center justify-center gap-1.5 text-[12.5px] text-ovs-crm-text-secondary">
                <Users className="h-3.5 w-3.5 text-ovs-crm-text-muted" aria-hidden="true" />
                <span className="font-semibold text-ovs-crm-text-primary">{result.imported_count}</span> new deal
                {result.imported_count === 1 ? '' : 's'} added
                {result.duplicate_count > 0 && <> · {result.duplicate_count} skipped as duplicates</>}
                {result.invalid_count > 0 && <> · {result.invalid_count} invalid</>}
              </p>
            </div>
          </div>
        )}
      </div>
    </CrmModal>
  );
}
