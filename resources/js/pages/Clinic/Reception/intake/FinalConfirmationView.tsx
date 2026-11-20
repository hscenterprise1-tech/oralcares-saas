import { ArrowRight, CheckCircle2, Clock, FileText, Printer, RotateCcw, ShieldCheck, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { IconChip, type Tone } from './IconChip';
import type { CheckInResult } from './types';

interface FinalConfirmationViewProps {
  result: CheckInResult;
  registered: boolean;
  onClose: () => void;
  onReset: () => void;
}

/** One fact of the check-in, as the Owner Dashboard lists them: a soft icon tile, a quiet label, the value, one line of detail. */
function Fact({ icon, tone, label, value, detail, valueClassName }: { icon: typeof Stethoscope; tone: Tone; label: string; value: string; detail?: string | null; valueClassName?: string }) {
  return (
    <div className="flex min-w-0 items-start gap-3 rounded-md bg-white p-3.5 ring-1 ring-inset ring-cx-line/80">
      <IconChip icon={icon} tone={tone} size="sm" variant="soft" />
      <div className="min-w-0">
        <span className="block text-[12px] leading-4 text-cx-text-3">{label}</span>
        <span className={cn('mt-0.5 block text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]', valueClassName)}>{value}</span>
        {detail && <span className="block text-[12px] leading-4 text-cx-text-2">{detail}</span>}
      </div>
    </div>
  );
}

/**
 * The check-in is done: who, their place in the queue, the doctor, the bill
 * and what was paid — each said once, in the Owner Dashboard's language —
 * then print, the next patient, or done. Nothing here sends anything: the
 * receipt's messages are the server's to deliver.
 */
export function FinalConfirmationView({ result, registered, onClose, onReset }: FinalConfirmationViewProps) {
  const patient = result.patient;
  const checkIn = result.check_in;
  const billing = result.billing;
  const receipt = billing?.receipt;
  const position = checkIn?.position;
  // Physiotherapy keeps no waiting queue: the patient is checked in for the therapist, with this year's case number.
  const physio = (checkIn?.context?.module?.key ?? checkIn?.context?.specialty?.key ?? '').includes('physio');
  // The patient's place in today's queue, not a token (the UHID stays the patient-facing identifier); only where the clinic shows a queue.
  const queuePlace = !physio && checkIn?.queue && position !== null && position !== undefined ? `#${String(position).padStart(2, '0')}` : null;
  const caseNumber = queuePlace === null ? (checkIn?.case_number ?? null) : null;
  const initials = patient.name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
  const printUrl = receipt?.print_url || billing?.print_url || null;

  const settlement = receipt
    ? { value: `Receipt ${receipt.number}`, detail: `Paid ₹${receipt.amount.toLocaleString('en-IN')} · ${receipt.method}`, className: 'text-cx-green-ink' }
    : billing && billing.outstanding > 0
      ? { value: 'To be paid later', detail: `₹${billing.outstanding.toLocaleString('en-IN')} outstanding`, className: 'text-cx-amber-ink' }
      : { value: 'Nothing to pay', detail: null, className: undefined };

  return (
    <div className="space-y-5">
      {/* The outcome, then who and where they wait: one tinted header, as the dashboard's greeting. */}
      <div className="rounded-2xl bg-gradient-to-br from-[#EAFAF6] via-[#F3F7FF] to-[#EEF5FF] p-4 ring-1 ring-inset ring-[#DCEFEA] sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex min-w-0 items-center gap-3.5">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-[14px] bg-white text-[15px] font-semibold text-cx-teal-ink shadow-cx-card" aria-hidden="true">
              {initials}
            </span>
            <div className="min-w-0">
              <p className="flex items-center gap-1.5 text-[12.5px] font-semibold text-cx-green-ink">
                <CheckCircle2 className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
                {registered ? 'Registered and checked in' : 'Checked in'}
              </p>
              <h3 className="mt-0.5 text-[18px] font-semibold leading-6 tracking-tight text-cx-nav-ink [overflow-wrap:anywhere]">{patient.name}</h3>
              {patient.uhid && <span className="mt-1 inline-flex rounded-md bg-white px-1.5 py-0.5 font-mono text-[11.5px] font-semibold text-cx-text-2 ring-1 ring-inset ring-cx-line">{patient.uhid}</span>}
            </div>
          </div>

          {(queuePlace || caseNumber) && (
            <div className="rounded-md bg-white px-4 py-2.5 text-right shadow-cx-card">
              <span className="block text-[12px] font-medium text-cx-teal-ink">{queuePlace ? 'Place in Queue' : 'Case number'}</span>
              <span className={cn('block font-semibold tracking-tight text-cx-nav-ink tabular-nums', queuePlace ? 'text-[26px] leading-8' : 'text-[18px] leading-7')}>{queuePlace ?? caseNumber}</span>
            </div>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Fact icon={Stethoscope} tone="blue" label={physio ? 'Therapist' : 'Doctor'} value={checkIn?.doctor || (physio ? 'Any therapist' : 'Next available doctor')} detail={checkIn?.context?.module?.label || null} />
        <Fact icon={Clock} tone="teal" label="Status" value={physio ? 'Checked in, session to start' : 'Waiting'} detail={`Checked in at ${new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}`} />
        <Fact icon={FileText} tone="violet" label="Bill" value={billing?.billing_number || 'No bill raised'} detail={billing ? `Total ₹${(billing.total || 0).toLocaleString('en-IN')}` : null} />
        <Fact icon={ShieldCheck} tone="mint" label="Payment" value={settlement.value} detail={settlement.detail} valueClassName={settlement.className} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-cx-line pt-4">
        <div>
          {printUrl && (
            <button
              type="button"
              onClick={() => window.open(printUrl, '_blank', 'noopener,noreferrer')}
              className="inline-flex h-10 items-center gap-2 rounded-full border border-[#DCE6F3] bg-white px-4 text-[13px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30"
            >
              <Printer className="h-4 w-4 text-cx-text-2" strokeWidth={1.75} aria-hidden="true" />
              {receipt ? 'Print receipt' : 'Print bill'}
            </button>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={onReset}
            className="inline-flex h-10 items-center gap-2 rounded-full border border-[#DCE6F3] bg-white px-4 text-[13px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30"
          >
            <RotateCcw className="h-4 w-4 text-cx-text-2" strokeWidth={1.75} aria-hidden="true" />
            Next patient
          </button>
          <button
            type="button"
            onClick={onClose}
            className="inline-flex h-10 items-center gap-2 rounded-full bg-[#1769AA] px-5 text-[13px] font-semibold text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] transition-colors hover:bg-[#135A93] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/40 focus-visible:ring-offset-2 motion-safe:active:scale-[0.98]"
          >
            Done
            <ArrowRight className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>
  );
}
