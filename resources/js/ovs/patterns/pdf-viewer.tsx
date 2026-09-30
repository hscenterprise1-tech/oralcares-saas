import React, { useState } from 'react';
import { Document, Page, pdfjs } from 'react-pdf';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut, Download, FileText, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { Spinner } from '@/ovs/feedback/spinner';
import { EmptyState } from '@/ovs/feedback/empty-state';

import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

// Configure pdfjs worker locally via Vite asset URL (100% offline & hospital network safe)
pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export interface PDFViewerProps {
  fileUrl: string;
  initialPage?: number;
  title?: string;
  className?: string;
}

export function PDFViewer({
  fileUrl,
  initialPage = 1,
  title = 'Document Viewer',
  className,
}: PDFViewerProps) {
  const [numPages, setNumPages] = useState<number | null>(null);
  const [pageNumber, setPageNumber] = useState<number>(initialPage);
  const [scale, setScale] = useState<number>(1.0);
  const [hasError, setHasError] = useState<boolean>(false);

  function onDocumentLoadSuccess({ numPages }: { numPages: number }) {
    setNumPages(numPages);
    setHasError(false);
  }

  function onDocumentLoadError() {
    setHasError(true);
  }

  const handlePrevPage = () => {
    setPageNumber((prev) => Math.max(1, prev - 1));
  };

  const handleNextPage = () => {
    if (numPages) {
      setPageNumber((prev) => Math.min(numPages, prev + 1));
    }
  };

  const handleZoomIn = () => {
    setScale((prev) => Math.min(2.0, prev + 0.2));
  };

  const handleZoomOut = () => {
    setScale((prev) => Math.max(0.6, prev - 0.2));
  };

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs overflow-hidden space-y-0', className)}>
      {/* Top Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3 p-3 bg-ovs-background border-b border-ovs-border-subtle">
        <div className="flex items-center gap-2">
          <FileText className="w-4 h-4 text-ovs-accent" />
          <span className="text-xs font-bold text-ovs-primary">{title}</span>
        </div>

        {/* Controls */}
        <div className="flex items-center gap-2">
          {/* Pagination */}
          <div className="flex items-center gap-1 bg-white px-2 py-1 rounded-xl border border-ovs-border-subtle shadow-2xs">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={handlePrevPage}
              disabled={pageNumber <= 1 || !numPages}
              className="w-6 h-6 text-ovs-secondary"
            >
              <ChevronLeft className="w-3.5 h-3.5" />
            </Button>

            <span className="text-[11px] font-mono font-bold text-ovs-primary px-1">
              {numPages ? `${pageNumber} / ${numPages}` : '-'}
            </span>

            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={handleNextPage}
              disabled={!numPages || pageNumber >= numPages}
              className="w-6 h-6 text-ovs-secondary"
            >
              <ChevronRight className="w-3.5 h-3.5" />
            </Button>
          </div>

          {/* Zoom */}
          <div className="flex items-center gap-1 bg-white px-2 py-1 rounded-xl border border-ovs-border-subtle shadow-2xs">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={handleZoomOut}
              disabled={scale <= 0.6}
              className="w-6 h-6 text-ovs-secondary"
            >
              <ZoomOut className="w-3 h-3" />
            </Button>
            <span className="text-[10px] font-mono font-semibold text-ovs-secondary px-1">
              {Math.round(scale * 100)}%
            </span>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={handleZoomIn}
              disabled={scale >= 2.0}
              className="w-6 h-6 text-ovs-secondary"
            >
              <ZoomIn className="w-3 h-3" />
            </Button>
          </div>

          {/* Download */}
          {fileUrl && (
            <a href={fileUrl} download target="_blank" rel="noreferrer">
              <Button type="button" variant="secondary" size="sm" className="gap-1.5 h-8 text-xs font-semibold">
                <Download className="w-3.5 h-3.5" />
                <span>Download</span>
              </Button>
            </a>
          )}
        </div>
      </div>

      {/* Main View Area */}
      <div className="p-6 flex items-center justify-center min-h-[320px] bg-slate-100/50 overflow-auto">
        {!fileUrl || hasError ? (
          <EmptyState
            icon={AlertCircle}
            title="PDF Document Unavailable"
            description={hasError ? "Unable to parse or render the requested PDF file." : "No valid document URL specified."}
          />
        ) : (
          <Document
            file={fileUrl}
            onLoadSuccess={onDocumentLoadSuccess}
            onLoadError={onDocumentLoadError}
            loading={
              <div className="py-12 flex flex-col items-center gap-2 text-xs text-ovs-secondary font-medium">
                <Spinner size="md" />
                <span>Loading PDF document...</span>
              </div>
            }
            error={
              <EmptyState
                icon={AlertCircle}
                title="Error Loading Document"
                description="Failed to load PDF file. Please check file URL."
              />
            }
          >
            <Page
              pageNumber={pageNumber}
              scale={scale}
              renderTextLayer={false}
              renderAnnotationLayer={false}
              className="shadow-ovs-floating rounded-lg overflow-hidden border border-ovs-border-subtle bg-white"
            />
          </Document>
        )}
      </div>
    </div>
  );
}
