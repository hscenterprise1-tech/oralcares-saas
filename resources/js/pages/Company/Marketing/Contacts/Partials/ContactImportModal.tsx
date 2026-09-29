import React, { useState, useRef, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton as Button } from '@/pages/Company/design/CrmControls';
import { CrmStatusPill, type CrmStatusTone } from '@/pages/Company/design';
import { Upload, FileSpreadsheet, Download, CheckCircle2, AlertTriangle, ArrowRight, Loader2, Users } from 'lucide-react';

interface ContactImportModalProps {
  open: boolean;
  onClose: () => void;
}

interface ExistingContact {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
  added_on: string | null;
}

interface PreviewRow {
  row_number: number;
  name: string;
  phone: string;
  normalized_phone: string;
  organization: string;
  city: string;
  tags: string;
  status: 'VALID' | 'INVALID' | 'FILE_DUPLICATE' | 'EXISTS_IN_DB' | 'POSSIBLE_DUPLICATE_ORG';
  error_message: string | null;
  existing_contact: ExistingContact | null;
}

interface PreviewData {
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  duplicate_rows: number;
  new_contacts: number;
  columns: string[];
  rows: PreviewRow[];
}

/**
 * What the importer decided about each row.
 *
 * A row that already exists is not an error — it is a duplicate the importer
 * caught, which is the whole point of the preview. The tones say that: only a
 * genuinely unusable row reads as bad.
 */
const ROW_STATUS: Record<PreviewRow['status'], { label: string; tone: CrmStatusTone }> = {
  VALID: { label: 'New', tone: 'good' },
  POSSIBLE_DUPLICATE_ORG: { label: 'Same organisation — check', tone: 'warn' },
  EXISTS_IN_DB: { label: 'Already on your list', tone: 'neutral' },
  FILE_DUPLICATE: { label: 'Repeated in this file', tone: 'warn' },
  INVALID: { label: 'Cannot be imported', tone: 'bad' },
};

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
}

export function ContactImportModal({ open, onClose }: ContactImportModalProps) {
  const [step, setStep] = useState<'upload' | 'preview' | 'processing' | 'result'>('upload');
  const [file, setFile] = useState<File | null>(null);
  const [previewData, setPreviewData] = useState<PreviewData | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);
  const [resultData, setResultData] = useState<{ imported_count: number; duplicate_count: number; invalid_count: number } | null>(null);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, []);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      setFile(e.target.files[0]);
      setErrorMsg(null);
    }
  };

  const handlePreview = async () => {
    if (!file) return;
    setIsProcessing(true);
    setErrorMsg(null);

    const formData = new FormData();
    formData.append('file', file);

    try {
      const res = await fetch('/company/marketing/contacts/import/preview', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken() },
        body: formData,
      });

      if (!res.ok) {
        throw new Error('Could not read that file. Check it matches the template formatting.');
      }

      const json: PreviewData = await res.json();
      setPreviewData(json);
      setStep('preview');
    } catch (err: any) {
      setErrorMsg(err.message || 'Error previewing the file.');
    } finally {
      setIsProcessing(false);
    }
  };

  const pollStatus = (importId: number) => {
    pollRef.current = setInterval(async () => {
      try {
        const res = await fetch(`/company/marketing/contacts/import/status/${importId}`, {
          headers: { 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();

        if (json.status === 'completed') {
          if (pollRef.current) clearInterval(pollRef.current);
          setResultData(json.result);
          setStep('result');
          router.reload({ only: ['contacts', 'metrics'] });
        } else if (json.status === 'failed') {
          if (pollRef.current) clearInterval(pollRef.current);
          setErrorMsg(json.error_message || 'Import failed while processing in the background.');
          setStep('preview');
        }
      } catch {
        // transient network hiccup — keep polling
      }
    }, 2500);
  };

  const handleProcess = async () => {
    if (!previewData) return;
    setIsProcessing(true);
    setErrorMsg(null);

    // Only send rows worth importing — invalid rows and in-file duplicates are
    // dropped client-side; existing/possible-duplicate rows are still sent so
    // the user's review decision (they've seen the "already exists" flag) is respected.
    const importableRows = previewData.rows.filter((r) => r.status !== 'INVALID' && r.status !== 'FILE_DUPLICATE');

    try {
      const res = await fetch('/company/marketing/contacts/import/process', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          rows: importableRows,
          original_filename: file?.name,
        }),
      });

      if (!res.ok) {
        throw new Error('Failed to process the import.');
      }

      const json = await res.json();

      if (json.queued) {
        setStep('processing');
        pollStatus(json.import_id);
      } else {
        setResultData(json.result);
        setStep('result');
        router.reload({ only: ['contacts', 'metrics'] });
      }
    } catch (err: any) {
      setErrorMsg(err.message || 'Error executing import.');
    } finally {
      setIsProcessing(false);
    }
  };

  const handleReset = () => {
    if (pollRef.current) clearInterval(pollRef.current);
    setStep('upload');
    setFile(null);
    setPreviewData(null);
    setResultData(null);
    setErrorMsg(null);
  };

  return (
    <CrmModal
      open={open}
      onClose={() => {
        handleReset();
        onClose();
      }}
      size="lg"
      title="Import contacts"
      description="An Excel or CSV file. Anyone already on your list is flagged rather than duplicated — you decide what to do with them."
    >

        {errorMsg && (
          <div className="p-3 rounded-xl bg-ovs-semantic-danger-bg border border-ovs-semantic-danger/30 text-xs text-ovs-semantic-danger-text flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 shrink-0" />
            <span>{errorMsg}</span>
          </div>
        )}

        {/* STEP 1: UPLOAD */}
        {step === 'upload' && (
          <div className="space-y-4 py-2">
            <div className="flex items-center justify-between p-3 rounded-xl bg-ovs-background border border-ovs-border-subtle">
              <div className="flex items-center gap-2 text-xs">
                <FileSpreadsheet className="w-4 h-4 text-ovs-accent" />
                <span className="font-semibold text-ovs-primary">Need sample formatting?</span>
              </div>
              <a
                href="/company/marketing/contacts/import/template"
                download
                className="inline-flex items-center gap-1 text-2xs font-bold text-ovs-accent hover:underline"
              >
                <Download className="w-3.5 h-3.5" /> Download Template CSV
              </a>
            </div>

            <div className="border-2 border-dashed border-ovs-border-subtle hover:border-ovs-accent/50 rounded-2xl p-6 text-center space-y-3 bg-ovs-surface transition-all">
              <div className="h-12 w-12 rounded-2xl bg-ovs-accent/10 text-ovs-accent mx-auto flex items-center justify-center font-bold">
                <Upload className="w-6 h-6" />
              </div>

              <div>
                <p className="text-xs font-bold text-ovs-primary">
                  {file ? file.name : 'Select or drop a file to upload'}
                </p>
                {/*
                  * The full header row, not a shortened version of it.
                  *
                  * This previously listed five columns and left out `state` and
                  * `area` — the two the contact list's location filter is built
                  * on. Anyone who took the screen at its word instead of opening
                  * the template imported a batch that could never be filtered
                  * down to a city or a neighbourhood.
                  */}
                <p className="text-2xs text-ovs-tertiary mt-0.5">
                  .xlsx or .csv, with a header row
                </p>
                <p className="text-2xs text-ovs-tertiary mt-1">
                  <span className="font-bold text-ovs-secondary">Required:</span> name, phone
                  <span className="mx-1.5 text-ovs-border-subtle">·</span>
                  <span className="font-bold text-ovs-secondary">Optional:</span> organization,
                  state, city, area, tags
                </p>
              </div>

              <input
                type="file"
                accept=".csv,.txt,.xlsx"
                id="csv-file-input"
                className="hidden"
                onChange={handleFileChange}
              />
              <label htmlFor="csv-file-input">
                <Button type="button" size="sm" className="cursor-pointer">
                  Browse File
                </Button>
              </label>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-ovs-crm-border pt-4">
              <Button type="button" size="sm" onClick={onClose}>
                Cancel
              </Button>
              <Button
                type="button"
                size="sm"
                disabled={!file || isProcessing}
                onClick={handlePreview}
                className="gap-1.5"
              >
                {isProcessing ? 'Analyzing...' : 'Preview & Check Duplicates'} <ArrowRight className="w-4 h-4" />
              </Button>
            </div>
          </div>
        )}

        {/* STEP 2: PREVIEW */}
        {step === 'preview' && previewData && (
          <div className="space-y-4 py-2">
            <div className="grid grid-cols-4 gap-2 text-xs">
              <div className="p-3 rounded-xl bg-ovs-surface border border-ovs-border-subtle">
                <p className="text-2xs text-ovs-tertiary uppercase font-semibold">Total Rows</p>
                <p className="text-lg font-bold text-ovs-primary font-display">{previewData.total_rows}</p>
              </div>
              <div className="p-3 rounded-xl bg-ovs-semantic-success-bg border border-ovs-semantic-success/20">
                <p className="text-2xs text-ovs-semantic-success-text uppercase font-semibold">New Contacts</p>
                <p className="text-lg font-bold text-ovs-semantic-success-text font-display">{previewData.new_contacts}</p>
              </div>
              <div className="p-3 rounded-xl bg-ovs-semantic-warning-bg border border-ovs-semantic-warning/20">
                <p className="text-2xs text-ovs-semantic-warning-text uppercase font-semibold">Duplicates</p>
                <p className="text-lg font-bold text-ovs-semantic-warning-text font-display">{previewData.duplicate_rows}</p>
              </div>
              <div className="p-3 rounded-xl bg-ovs-semantic-danger-bg border border-ovs-semantic-danger/20">
                <p className="text-2xs text-ovs-semantic-danger-text uppercase font-semibold">Invalid</p>
                <p className="text-lg font-bold text-ovs-semantic-danger-text font-display">{previewData.invalid_rows}</p>
              </div>
            </div>

            <div className="space-y-1.5">
              <p className="text-2xs font-bold text-ovs-tertiary uppercase">Row-by-row review</p>
              <div className="overflow-y-auto rounded-xl border border-ovs-border-subtle max-h-72">
                <table className="w-full text-2xs text-left">
                  <thead className="bg-ovs-background text-ovs-tertiary border-b border-ovs-border-subtle sticky top-0">
                    <tr>
                      <th className="p-2 font-semibold">Name</th>
                      <th className="p-2 font-semibold">Phone</th>
                      <th className="p-2 font-semibold">Organization</th>
                      <th className="p-2 font-semibold">Status</th>
                      <th className="p-2 font-semibold">Detail</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ovs-border-subtle bg-white">
                    {previewData.rows.map((row) => {
                      const badge = ROW_STATUS[row.status];
                      return (
                        <tr key={row.row_number}>
                          <td className="p-2 text-ovs-secondary font-medium">{row.name || '—'}</td>
                          <td className="p-2 text-ovs-secondary">{row.phone || '—'}</td>
                          <td className="p-2 text-ovs-secondary">{row.organization || '—'}</td>
                          <td className="p-2">
                            <CrmStatusPill tone={badge.tone}>{badge.label}</CrmStatusPill>
                          </td>
                          <td className="p-2 text-ovs-tertiary">
                            {row.existing_contact ? (
                              <span>
                                Already have <span className="font-semibold text-ovs-secondary">{row.existing_contact.name}</span>
                                {row.existing_contact.added_on && <> — added on {row.existing_contact.added_on}</>}
                              </span>
                            ) : (
                              row.error_message || ''
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-ovs-crm-border pt-4">
              <Button type="button" size="sm" onClick={() => setStep('upload')}>
                Back
              </Button>
              <Button
                type="button"
                size="sm"
                disabled={isProcessing || previewData.new_contacts === 0}
                onClick={handleProcess}
                className="gap-1.5"
              >
                {isProcessing ? 'Starting...' : `Import ${previewData.new_contacts} New Contacts`}
              </Button>
            </div>
          </div>
        )}

        {/* STEP 2b: BACKGROUND PROCESSING */}
        {step === 'processing' && (
          <div className="space-y-3 py-8 text-center">
            <Loader2 className="w-8 h-8 text-ovs-accent mx-auto animate-spin" />
            <div>
              <h3 className="text-base font-bold text-ovs-primary font-display">Importing in the background</h3>
              <p className="text-xs text-ovs-tertiary mt-1">
                This is a large file, so it's processing behind the scenes. You can leave this open or close it — the contact list will update when it's done.
              </p>
            </div>
          </div>
        )}

        {/* STEP 3: RESULT */}
        {step === 'result' && resultData && (
          <div className="space-y-4 py-4 text-center">
            <div className="h-12 w-12 rounded-full bg-ovs-semantic-success-bg text-ovs-semantic-success mx-auto flex items-center justify-center">
              <CheckCircle2 className="w-6 h-6" />
            </div>
            <div>
              <h3 className="text-base font-bold text-ovs-primary font-display">Import Completed</h3>
              <p className="text-xs text-ovs-secondary mt-1 flex items-center justify-center gap-1.5">
                <Users className="w-3.5 h-3.5 text-ovs-tertiary" />
                <span className="font-bold text-ovs-primary">{resultData.imported_count}</span> new contacts added
                {resultData.duplicate_count > 0 && <> · {resultData.duplicate_count} skipped as duplicates</>}
              </p>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-ovs-crm-border pt-4">
              <Button
                type="button"
                size="sm"
                onClick={() => {
                  handleReset();
                  onClose();
                }}
              >
                Done
              </Button>
            </div>
          </div>
        )}
    </CrmModal>
  );
}
