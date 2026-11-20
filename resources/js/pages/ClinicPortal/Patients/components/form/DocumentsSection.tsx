import { useRef, useState, type ChangeEvent } from 'react';
import { Download, FileText, Image as ImageIcon, Plus, Trash2, UploadCloud } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Select } from '@/clinic/ui/controls';
import { SectionCard } from './SectionCard';

export interface PendingDocument {
  id: string;
  file: File;
  name: string;
  size: number;
  type: string;
}

export interface ExistingDocument {
  id: number;
  file_name: string;
  document_type: string;
  size_bytes?: number;
  created_at?: string;
  download_url: string;
}

interface DocumentsSectionProps {
  pendingFiles: PendingDocument[];
  onAddPending: (docs: PendingDocument[]) => void;
  onRemovePending: (id: string) => void;
  existingDocuments?: ExistingDocument[];
  patientId?: number;
  isEditMode?: boolean;
}

const DOCUMENT_TYPES = [
  { value: 'prescription', label: 'Previous Prescription' },
  { value: 'medical_report', label: 'Medical Report' },
  { value: 'xray', label: 'X-ray / Diagnostic Scan' },
  { value: 'lab_report', label: 'Lab Report' },
  { value: 'id_proof', label: 'Identity Proof' },
  { value: 'other', label: 'Other Document' },
];

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

export function DocumentsSection({
  pendingFiles,
  onAddPending,
  onRemovePending,
  existingDocuments = [],
  patientId,
  isEditMode = false,
}: DocumentsSectionProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [selectedType, setSelectedType] = useState('medical_report');
  const [isDragging, setIsDragging] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  const handleFiles = (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setErrorMsg(null);

    const newDocs: PendingDocument[] = [];
    const maxSizeBytes = 10 * 1024 * 1024; // 10MB
    const validExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    Array.from(files).forEach((file) => {
      const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
      if (!validExtensions.includes(ext)) {
        setErrorMsg(`"${file.name}" has an unsupported format. Allowed: PDF, JPG, PNG, WEBP.`);
        return;
      }
      if (file.size > maxSizeBytes) {
        setErrorMsg(`"${file.name}" exceeds the maximum 10MB limit.`);
        return;
      }

      newDocs.push({
        id: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
        file,
        name: file.name,
        size: file.size,
        type: selectedType,
      });
    });

    if (newDocs.length > 0) {
      onAddPending(newDocs);
    }
  };

  const onFileInputChange = (e: ChangeEvent<HTMLInputElement>) => {
    handleFiles(e.target.files);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const getDocTypeLabel = (type: string) =>
    DOCUMENT_TYPES.find((t) => t.value === type)?.label ?? type;

  return (
    <SectionCard
      id="section-documents"
      title="Patient Documents"
      subtitle="Prescriptions, medical reports, X-rays, and external laboratory findings."
      icon={FileText}
      tone="yellow"
      badge="Records"
    >
      <div className="space-y-5">
        {/* Dropzone & Type Selector */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="w-full sm:w-64 shrink-0">
            <label className="mb-1 block text-[12px] font-semibold text-slate-700">
              Document Category
            </label>
            <Select
              value={selectedType}
              onChange={(e) => setSelectedType(e.target.value)}
              options={DOCUMENT_TYPES}
              className="h-10 text-[13.5px]"
            />
          </div>

          <div className="flex-1">
            <label className="mb-1 block text-[12px] font-semibold text-slate-700">
              Upload Files
            </label>
            <div
              onDragOver={(e) => {
                e.preventDefault();
                setIsDragging(true);
              }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={(e) => {
                e.preventDefault();
                setIsDragging(false);
                handleFiles(e.dataTransfer.files);
              }}
              onClick={() => fileInputRef.current?.click()}
              className={cn(
                'flex h-10 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-4 text-center transition-all',
                isDragging
                  ? 'border-amber-500 bg-amber-50 text-amber-900 ring-2 ring-amber-300'
                  : 'border-amber-300 bg-amber-50/50 text-amber-900 hover:border-amber-400 hover:bg-amber-50',
              )}
            >
              <UploadCloud className="h-4 w-4 text-amber-700" />
              <span className="text-[13px] font-semibold">
                Click to attach files or drop here (PDF, JPG, PNG up to 10MB)
              </span>
            </div>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept=".pdf,.jpg,.jpeg,.png,.webp"
              onChange={onFileInputChange}
              className="hidden"
            />
          </div>
        </div>

        {errorMsg && (
          <p className="rounded-lg bg-rose-50 px-3 py-2 text-[12.5px] font-medium text-rose-700">
            {errorMsg}
          </p>
        )}

        {/* Pending Uploads List */}
        {pendingFiles.length > 0 && (
          <div>
            <h4 className="mb-2 text-[12px] font-bold uppercase tracking-wider text-slate-600">
              New Files to Attach ({pendingFiles.length})
            </h4>
            <ul className="space-y-2">
              {pendingFiles.map((doc) => (
                <li
                  key={doc.id}
                  className="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/40 p-2.5 sm:px-4"
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-800">
                      {doc.name.endsWith('.pdf') ? (
                        <FileText className="h-4 w-4" />
                      ) : (
                        <ImageIcon className="h-4 w-4" />
                      )}
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-[13.5px] font-bold text-slate-800">{doc.name}</p>
                      <p className="text-[12px] text-slate-500">
                        {formatBytes(doc.size)} ·{' '}
                        <span className="font-medium text-amber-800">
                          {getDocTypeLabel(doc.type)}
                        </span>
                      </p>
                    </div>
                  </div>

                  <button
                    type="button"
                    onClick={() => onRemovePending(doc.id)}
                    aria-label={`Remove ${doc.name}`}
                    className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* Existing Attached Documents (for Edit view) */}
        {isEditMode && existingDocuments.length > 0 && (
          <div className="border-t border-slate-100 pt-4">
            <h4 className="mb-2.5 text-[12px] font-bold uppercase tracking-wider text-slate-600">
              Existing Attached Documents ({existingDocuments.length})
            </h4>
            <ul className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              {existingDocuments.map((doc) => (
                <li
                  key={doc.id}
                  className="flex items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-slate-50/50 p-3"
                >
                  <div className="flex items-center gap-2.5 min-w-0">
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white text-slate-600 shadow-sm border border-slate-200">
                      <FileText className="h-4 w-4 text-amber-700" />
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-bold text-slate-900">{doc.file_name}</p>
                      <p className="text-[11.5px] text-slate-500">
                        {doc.created_at ?? 'Saved on record'} ·{' '}
                        <span className="font-semibold text-slate-700">
                          {getDocTypeLabel(doc.document_type)}
                        </span>
                      </p>
                    </div>
                  </div>

                  <a
                    href={doc.download_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 text-[12px] font-bold text-slate-700 hover:bg-slate-100"
                  >
                    <Download className="h-3.5 w-3.5" />
                    View
                  </a>
                </li>
              ))}
            </ul>
          </div>
        )}

        {pendingFiles.length === 0 && (!isEditMode || existingDocuments.length === 0) && (
          <p className="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-4 text-center text-[12.5px] text-slate-500">
            No previous records attached yet. You can attach initial prescriptions, scans, or
            medical reports above.
          </p>
        )}
      </div>
    </SectionCard>
  );
}
