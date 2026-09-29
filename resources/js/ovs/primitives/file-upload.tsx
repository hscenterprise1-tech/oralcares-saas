import * as React from 'react';
import { UploadCloud, File, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface FileUploadProps {
  onFileSelect?: (files: File[]) => void;
  accept?: string;
  multiple?: boolean;
  maxSizeMb?: number;
  className?: string;
}

export function FileUpload({
  onFileSelect,
  accept,
  multiple = false,
  maxSizeMb = 10,
  className,
}: FileUploadProps) {
  const [dragActive, setDragActive] = React.useState(false);
  const [selectedFiles, setSelectedFiles] = React.useState<File[]>([]);
  const inputRef = React.useRef<HTMLInputElement>(null);

  const handleFiles = (files: FileList | null) => {
    if (!files) return;
    const fileArray = Array.from(files).filter(
      (f) => f.size <= maxSizeMb * 1024 * 1024
    );
    setSelectedFiles((prev) => (multiple ? [...prev, ...fileArray] : fileArray));
    if (onFileSelect) onFileSelect(fileArray);
  };

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      handleFiles(e.dataTransfer.files);
    }
  };

  const removeFile = (index: number) => {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
  };

  return (
    <div className={cn('w-full space-y-3 font-sans', className)}>
      <div
        onDragEnter={handleDrag}
        onDragLeave={handleDrag}
        onDragOver={handleDrag}
        onDrop={handleDrop}
        className={cn(
          'flex flex-col items-center justify-center p-6 border-2 border-dashed rounded-xl transition-all duration-200 text-center font-sans',
          dragActive
            ? 'border-teal-500 bg-teal-50/40'
            : 'border-slate-300 bg-white'
        )}
      >
        <input
          ref={inputRef}
          type="file"
          accept={accept}
          multiple={multiple}
          onChange={(e) => handleFiles(e.target.files)}
          className="hidden"
        />
        <div className="w-10 h-10 rounded-xl bg-teal-50 border border-teal-100 flex items-center justify-center mb-3 text-teal-600 shadow-2xs">
          <UploadCloud className="w-5 h-5" />
        </div>
        <p className="text-xs font-bold text-slate-900 mb-0.5">
          Drag and drop your files here
        </p>
        <p className="text-[11px] text-slate-500 mb-3">
          SVG, PNG, JPG, PDF or DICOM (max {maxSizeMb}MB)
        </p>
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={() => inputRef.current?.click()}
        >
          Browse Files
        </Button>
      </div>

      {selectedFiles.length > 0 && (
        <div className="space-y-2">
          {selectedFiles.map((file, i) => (
            <div
              key={i}
              className="flex items-center justify-between p-2.5 rounded-lg border border-slate-200/80 bg-white text-xs shadow-2xs"
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <File className="w-4 h-4 text-teal-600 flex-shrink-0" />
                <span className="font-medium text-slate-900 truncate">{file.name}</span>
                <span className="text-slate-400 text-[11px]">
                  ({(file.size / 1024).toFixed(1)} KB)
                </span>
              </div>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={(e) => {
                  e.stopPropagation();
                  removeFile(i);
                }}
                aria-label="Remove file"
                className="h-7 w-7 min-h-0 min-w-0 text-slate-400 hover:text-slate-900"
              >
                <X className="w-3.5 h-3.5" />
              </Button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
