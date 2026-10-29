import { useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, FlaskConical, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Checkbox, Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { humanize } from '@/clinic/ui/visuals';
import { shortDate } from '../Patients/components/format';

/*
 * One investigation order (`InvestigationController@show`): each test or
 * imaging item moves ordered → collected → result in → reviewed (final), or
 * is cancelled with a reason. Entering a result saves it as preliminary; a
 * clinician who may review makes it final, after which it cannot change.
 * Re-entering a preliminary result keeps the earlier one in the timeline.
 * Nothing here invents a value: what is shown is what was entered.
 */

type ResultValue = { name: string; value: string; unit?: string | null; reference_range?: string | null; flag?: string | null };
type Item = {
  id: number;
  name: string;
  code: string | null;
  category: string;
  category_label: string;
  collect_label: string;
  status: string;
  body_site: string | null;
  external_provider: string | null;
  notes: string | null;
  collected: { at: string; by: string | null } | null;
  reviewed: { at: string; by: string | null } | null;
  cancellation_reason: string | null;
  result: { status: string; summary: string | null; values: ResultValue[]; is_abnormal: boolean; reported_at: string | null; reported_by: string | null; review_note: string | null } | null;
  documents: { id: number; name: string; url: string }[];
  links: { collect: string; result: string; review: string; cancel: string };
};
type Props = {
  order: { id: number; number: string | null; status: string; priority: string; instructions: string | null; ordered_at: string | null; doctor: string | null; branch: string | null; consultation_url: string | null };
  patient: { name: string; uhid: string | null; url: string };
  items: Item[];
  links: { index: string; upload: string };
  can: { fulfil: boolean; review: boolean; upload: boolean };
};

const STATUS: Record<string, [string, string]> = {
  ordered: ['Ordered', 'bg-cx-slate-bg text-cx-slate-ink'],
  collected: ['Sample collected', 'bg-cx-m-blue-bg text-cx-blue-ink'],
  resulted: ['Preliminary result', 'bg-cx-amber-bg text-cx-amber-ink'],
  reviewed: ['Final (reviewed)', 'bg-cx-green-bg text-cx-green-ink'],
  cancelled: ['Cancelled', 'bg-cx-slate-bg text-cx-slate-ink'],
};
const FLAGS = ['normal', 'high', 'low', 'abnormal', 'critical', 'positive', 'negative'];
const OPEN = ['ordered', 'collected', 'resulted'];

export default function OrderShow({ order, patient, items, links, can }: Props) {
  const errors = usePage().props.errors as Record<string, string>;
  const problem = Object.values(errors)[0];

  return (
    <ClinicPortalShell title={`${order.number ?? 'Order'} · ${patient.name}`}>
      <div className="mx-auto w-full max-w-[1100px] space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Link href={patient.url} className="inline-flex min-h-9 items-center gap-1.5 text-[14px] font-semibold text-cx-text-2 hover:text-cx-text">
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            {patient.name}
          </Link>
          <Link href={links.index} className="text-[13.5px] font-semibold text-cx-blue-ink hover:underline">
            All investigations
          </Link>
        </div>
        <Card as="section" aria-label="Order" className="p-4 sm:p-5">
          <h1 className="flex flex-wrap items-center gap-2 text-[21px] font-bold text-cx-text">
            <FlaskConical className="h-5 w-5 text-cx-m-blue" aria-hidden="true" />
            Order {order.number}
            {order.priority !== 'routine' && <span className="rounded-full bg-cx-amber-bg px-2.5 py-0.5 text-[12px] font-semibold text-cx-amber-ink">{humanize(order.priority)}</span>}
          </h1>
          <p className="mt-1 text-[13.5px] text-cx-text-2">
            {[patient.uhid, shortDate(order.ordered_at), order.doctor, order.branch].filter(Boolean).join(' · ')}
            {order.consultation_url && (
              <>
                {' · '}
                <Link href={order.consultation_url} className="font-semibold text-cx-blue-ink hover:underline">
                  Consultation
                </Link>
              </>
            )}
          </p>
          {order.instructions && <p className="mt-1 text-[13.5px] text-cx-text [overflow-wrap:anywhere]">Instructions: {order.instructions}</p>}
        </Card>
        {problem && <Notice tone="danger">{problem}</Notice>}
        {items.map((item) => (
          <OrderItem key={item.id} item={item} can={can} uploadUrl={links.upload} />
        ))}
      </div>
    </ClinicPortalShell>
  );
}

function OrderItem({ item, can, uploadUrl }: { item: Item; can: Props['can']; uploadUrl: string }) {
  const [entering, setEntering] = useState(false);
  const [reviewing, setReviewing] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [label, tone] = STATUS[item.status] ?? [humanize(item.status), 'bg-cx-slate-bg text-cx-slate-ink'];
  const post = (url: string, data: Record<string, unknown>, done?: () => void) => router.post(url, data as never, { preserveScroll: true, onSuccess: () => done?.() });
  const open = OPEN.includes(item.status);

  return (
    <Card as="section" aria-labelledby={`item-${item.id}`} className="p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 id={`item-${item.id}`} className="text-[16px] font-semibold text-cx-text [overflow-wrap:anywhere]">
            {item.name}
          </h2>
          <p className="text-[13px] text-cx-text-2">{[item.category_label, item.code, item.body_site, item.external_provider].filter(Boolean).join(' · ')}</p>
        </div>
        <span className={cn('inline-flex h-6 items-center rounded-full px-2.5 text-[12px] font-semibold', tone)}>{label}</span>
      </div>
      {item.notes && <p className="mt-1 text-[13px] text-cx-text-2 [overflow-wrap:anywhere]">{item.notes}</p>}
      <p className="mt-1 text-[12.5px] text-cx-text-2">
        {[
          item.collected ? `${item.collect_label} ${shortDate(item.collected.at)}${item.collected.by ? ` by ${item.collected.by}` : ''}` : null,
          item.reviewed ? `Reviewed ${shortDate(item.reviewed.at)}${item.reviewed.by ? ` by ${item.reviewed.by}` : ''}` : null,
        ]
          .filter(Boolean)
          .join(' · ')}
      </p>
      {item.cancellation_reason && <p className="mt-1 text-[13px] text-cx-rose-ink">Cancelled: {item.cancellation_reason}</p>}

      {item.result && (
        <div className={cn('mt-3 rounded-[12px] border p-3', item.result.is_abnormal ? 'border-[#F2C4C1] bg-cx-rose-bg/40' : 'border-cx-line')}>
          <p className="text-[13px] font-semibold text-cx-text">
            {item.result.status === 'final' ? 'Final result' : 'Preliminary result — not yet reviewed'}
            {item.result.is_abnormal && <span className="ml-2 text-cx-rose-ink">Abnormal</span>}
          </p>
          {item.result.values.length > 0 && (
            <div className="mt-2 overflow-x-auto">
              <table className="w-full min-w-[420px] text-[13.5px]">
                <thead>
                  <tr className="border-b border-cx-line text-left text-[12px] text-cx-text-2">
                    <th className="py-1 font-medium">Test</th>
                    <th className="py-1 font-medium">Value</th>
                    <th className="py-1 font-medium">Reference</th>
                    <th className="py-1 font-medium">Flag</th>
                  </tr>
                </thead>
                <tbody>
                  {item.result.values.map((value, index) => (
                    <tr key={index} className="border-b border-cx-line/60">
                      <td className="py-1 pr-2 [overflow-wrap:anywhere]">{value.name}</td>
                      <td className={cn('py-1 pr-2 tabular-nums', value.flag && value.flag !== 'normal' && value.flag !== 'negative' && 'font-semibold text-cx-rose-ink')}>
                        {value.value} {value.unit}
                      </td>
                      <td className="py-1 pr-2 text-cx-text-2">{value.reference_range ?? '—'}</td>
                      <td className="py-1">{value.flag ? humanize(value.flag) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {item.result.summary && <p className="mt-2 whitespace-pre-line text-[14px] text-cx-text [overflow-wrap:anywhere]">{item.result.summary}</p>}
          <p className="mt-1 text-[12.5px] text-cx-text-2">{[item.result.reported_by ? `Entered by ${item.result.reported_by}` : null, shortDate(item.result.reported_at)].filter(Boolean).join(' · ')}</p>
          {item.result.review_note && <p className="text-[13px] text-cx-text">Review: {item.result.review_note}</p>}
        </div>
      )}

      {item.documents.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-2">
          {item.documents.map((doc) => (
            <li key={doc.id}>
              <a href={doc.url} target="_blank" rel="noopener" className="inline-flex h-8 items-center gap-1.5 rounded-[9px] border border-cx-line px-2.5 text-[13px] font-semibold text-cx-blue-ink hover:bg-cx-m-blue-bg">
                <FileText className="h-4 w-4" aria-hidden="true" />
                {doc.name}
              </a>
            </li>
          ))}
        </ul>
      )}

      {open && (can.fulfil || can.review) && (
        <div className="mt-3 flex flex-wrap gap-2 border-t border-cx-line pt-3">
          {can.fulfil && item.status === 'ordered' && <Button onClick={() => post(item.links.collect, {})}>Mark {item.collect_label.toLowerCase()}</Button>}
          {can.fulfil && (
            <Button tone={item.result ? 'secondary' : 'primary'} onClick={() => setEntering(true)}>
              {item.result ? 'Correct result' : 'Enter result'}
            </Button>
          )}
          {can.review && item.status === 'resulted' && (
            <Button tone="primary" onClick={() => setReviewing(true)}>
              Review and finalise
            </Button>
          )}
          {can.fulfil && <ReportUpload itemId={item.id} url={uploadUrl} enabled={can.upload} />}
          {can.fulfil && (
            <Button tone="ghost" onClick={() => setCancelling(true)}>
              Cancel investigation
            </Button>
          )}
        </div>
      )}

      {entering && <ResultDialog item={item} onClose={() => setEntering(false)} />}
      {reviewing && (
        <ReasonDialog
          title={`Review: ${item.name}`}
          description="The result becomes final and can no longer be changed."
          label="Review note (optional)"
          required={false}
          confirm="Finalise"
          onClose={() => setReviewing(false)}
          onConfirm={(note) => post(item.links.review, { note: note || null }, () => setReviewing(false))}
        />
      )}
      {cancelling && (
        <ReasonDialog
          title={`Cancel: ${item.name}`}
          description="It stays on the order, marked cancelled with your reason."
          label="Reason"
          required
          confirm="Cancel investigation"
          onClose={() => setCancelling(false)}
          onConfirm={(reason) => post(item.links.cancel, { reason }, () => setCancelling(false))}
        />
      )}
    </Card>
  );
}

/** Enter or correct a result: values (with unit, reference range and flag) and/or a written report. Saved as preliminary until reviewed. */
function ResultDialog({ item, onClose }: { item: Item; onClose: () => void }) {
  const [values, setValues] = useState<ResultValue[]>(item.result?.values.length ? item.result.values : [{ name: '', value: '', unit: '', reference_range: '', flag: '' }]);
  const [summary, setSummary] = useState(item.result?.summary ?? '');
  const [abnormal, setAbnormal] = useState(item.result?.is_abnormal ?? false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const filled = values.filter((value) => value.name.trim() && value.value.trim());

  const save = () =>
    router.post(
      item.links.result,
      {
        summary: summary.trim() || null,
        is_abnormal: abnormal,
        values: filled.map((value) => ({ name: value.name.trim(), value: value.value.trim(), unit: value.unit?.trim() || null, reference_range: value.reference_range?.trim() || null, flag: value.flag || null })),
      } as never,
      { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: (bag) => setErrors(bag as Record<string, string>), onSuccess: onClose },
    );

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={`${item.result ? 'Correct' : 'Enter'} result: ${item.name}`}
      description="Saved as preliminary. It becomes final when a clinician reviews it."
      size="lg"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} disabled={busy || (filled.length === 0 && summary.trim() === '')} onClick={save}>
            Save result
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {Object.keys(errors).length > 0 && <Notice tone="danger">{Object.values(errors)[0]}</Notice>}
        <div className="space-y-2">
          {values.map((value, index) => (
            <div key={index} className="grid gap-2 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_90px_minmax(0,1fr)_130px_auto] sm:items-end">
              <Field label={index === 0 ? 'Test' : ''} htmlFor={`v-name-${index}`}>
                <Input
                  id={`v-name-${index}`}
                  aria-label="Test"
                  value={value.name}
                  maxLength={100}
                  onChange={(event) => setValues((list) => list.map((row, at) => (at === index ? { ...row, name: event.target.value } : row)))}
                />
              </Field>
              <Field label={index === 0 ? 'Value' : ''} htmlFor={`v-value-${index}`}>
                <Input
                  id={`v-value-${index}`}
                  aria-label="Value"
                  value={value.value}
                  maxLength={60}
                  onChange={(event) => setValues((list) => list.map((row, at) => (at === index ? { ...row, value: event.target.value } : row)))}
                />
              </Field>
              <Field label={index === 0 ? 'Unit' : ''} htmlFor={`v-unit-${index}`}>
                <Input
                  id={`v-unit-${index}`}
                  aria-label="Unit"
                  value={value.unit ?? ''}
                  maxLength={30}
                  onChange={(event) => setValues((list) => list.map((row, at) => (at === index ? { ...row, unit: event.target.value } : row)))}
                />
              </Field>
              <Field label={index === 0 ? 'Reference range' : ''} htmlFor={`v-range-${index}`}>
                <Input
                  id={`v-range-${index}`}
                  aria-label="Reference range"
                  value={value.reference_range ?? ''}
                  maxLength={60}
                  onChange={(event) => setValues((list) => list.map((row, at) => (at === index ? { ...row, reference_range: event.target.value } : row)))}
                />
              </Field>
              <Field label={index === 0 ? 'Flag' : ''} htmlFor={`v-flag-${index}`}>
                <Select
                  id={`v-flag-${index}`}
                  aria-label="Flag"
                  value={value.flag ?? ''}
                  onChange={(event) => setValues((list) => list.map((row, at) => (at === index ? { ...row, flag: event.target.value } : row)))}
                  placeholder="—"
                  options={FLAGS.map((flag) => ({ value: flag, label: humanize(flag) }))}
                />
              </Field>
              <button
                type="button"
                aria-label="Remove this value"
                onClick={() => setValues((list) => (list.length > 1 ? list.filter((_, at) => at !== index) : [{ name: '', value: '', unit: '', reference_range: '', flag: '' }]))}
                className="grid h-11 w-11 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          ))}
          <Button size="sm" icon={Plus} onClick={() => setValues((list) => [...list, { name: '', value: '', unit: '', reference_range: '', flag: '' }])}>
            Add a value
          </Button>
        </div>
        <Field label="Report" htmlFor="result-summary" hint="The written report or impression. Required when there are no values.">
          <Textarea id="result-summary" value={summary} maxLength={20000} onChange={(event) => setSummary(event.target.value)} />
        </Field>
        <Checkbox checked={abnormal} onCheckedChange={setAbnormal} label="Abnormal result (also set automatically by a high, low, abnormal, critical or positive flag)" />
      </div>
    </Modal>
  );
}

function ReasonDialog({
  title,
  description,
  label,
  required,
  confirm,
  onClose,
  onConfirm,
}: {
  title: string;
  description: string;
  label: string;
  required: boolean;
  confirm: string;
  onClose: () => void;
  onConfirm: (text: string) => void;
}) {
  const [text, setText] = useState('');

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={title}
      description={description}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Back
          </Button>
          <Button tone="primary" disabled={required && text.trim() === ''} onClick={() => onConfirm(text.trim())}>
            {confirm}
          </Button>
        </>
      }
    >
      <Field label={label} htmlFor="reason-text" required={required}>
        <Input id="reason-text" value={text} maxLength={required ? 255 : 2000} onChange={(event) => setText(event.target.value)} />
      </Field>
    </Modal>
  );
}

/** Attach the report file to this investigation (malware-scanned like every upload). */
function ReportUpload({ itemId, url, enabled }: { itemId: number; url: string; enabled: boolean }): ReactNode {
  const [busy, setBusy] = useState(false);
  if (!enabled) {
    return null;
  }

  return (
    <label
      className={cn(
        'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-full border border-clinic-border bg-clinic-surface px-4 text-[14px] font-semibold text-clinic-ink hover:border-clinic-border-strong',
        busy && 'opacity-60',
      )}
    >
      <FileText className="h-4 w-4" aria-hidden="true" />
      {busy ? 'Uploading…' : 'Attach report'}
      <input
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,.webp"
        className="sr-only"
        disabled={busy}
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (!file) {
            return;
          }
          router.post(url, { document: file, document_type: 'lab_report', clinical_order_item_id: itemId } as never, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
          });
        }}
      />
    </label>
  );
}
