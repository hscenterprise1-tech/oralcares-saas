import { useState } from 'react';
import { router, usePage, usePoll } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { CrmPageHead, useCrmConfirm } from '@/pages/Company/design';
import { ClinicShell } from '../components/ClinicShell';
import { QueueList } from './components/QueueList';
import { ActivityCard, AttentionPanel, BookingStatusCard, DoctorPanel, SummaryStrip, WaitTimeCard } from './components/ReceptionCards';
import { useClinicNow } from './queueUi';
import type { QueueAction, QueueRow, ReceptionPageProps } from './types';

/**
 * Reception Live Queue (Phase A2).
 *
 * Exceptions first — what needs a person now — then the queue in the order
 * the server decides, then who can see patients, then how the day is going.
 * Everything on it comes from real rows (`QueueBoard`); there is nothing
 * estimated or predicted. Refreshes itself every 15 seconds.
 */
export default function ReceptionIndex({ branch, board }: ReceptionPageProps) {
  const { props } = usePage<{ errors: Record<string, string> }>();
  const [busyId, setBusyId] = useState<number | null>(null);
  const [highlightId, setHighlightId] = useState<number | null>(null);
  const { confirm, dialog } = useCrmConfirm();
  const now = useClinicNow(board.now);

  usePoll(15_000, { only: ['board'] });

  const send = (row: QueueRow, action: QueueAction) => {
    setBusyId(row.id);
    router.patch(route('clinic.reception.queue.status', row.id), { action }, {
      preserveScroll: true,
      onFinish: () => setBusyId(null),
    });
  };

  const act = (row: QueueRow, action: QueueAction) => {
    if (action !== 'cancel') {
      send(row, action);

      return;
    }

    confirm({
      title: `${row.patient.short_name} left without being seen?`,
      description: 'They will be taken off today\'s queue. If they come back, check them in again.',
      confirmLabel: 'Take off the queue',
      onConfirm: () => send(row, action),
    });
  };

  const toggleEmergency = (row: QueueRow) => {
    setBusyId(row.id);
    router.patch(
      route('clinic.reception.queue.priority', row.id),
      { priority: row.priority === 'emergency' ? 'normal' : 'emergency' },
      { preserveScroll: true, onFinish: () => setBusyId(null) }
    );
  };

  const openItem = (itemId: number) => {
    setHighlightId(itemId);
    const visible = Array.from(document.querySelectorAll<HTMLElement>(`[data-queue-row="${itemId}"]`)).find((el) => el.offsetParent !== null);
    visible?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => setHighlightId(null), 2500);
  };

  return (
    <ClinicShell title="Live queue">
      <CrmPageHead eyebrow={`Reception · ${branch.name}`} title="Live queue" description="Updates by itself every 15 seconds." />

      <div className="space-y-4 pb-16">
        {props.errors?.status && (
          <div role="alert" className="flex items-start gap-3 rounded-[16px] bg-ovs-crm-accent-coral-soft px-4 py-3 text-ovs-crm-accent-coral-text">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p className="text-[13px] font-medium">{props.errors.status}</p>
          </div>
        )}

        <AttentionPanel entries={board.attention} onOpenItem={openItem} />

        <SummaryStrip summary={board.summary} />

        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
          <div className="xl:col-span-8">
            <QueueList rows={board.queue} now={now} busyId={busyId} highlightId={highlightId} onAction={act} onToggleEmergency={toggleEmergency} />
          </div>
          <div className="xl:col-span-4">
            <DoctorPanel doctors={board.doctors} roomsEnabled={board.rooms_enabled} />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
          <BookingStatusCard bookingStatus={board.booking_status} />
          <WaitTimeCard waitTimes={board.wait_times} />
          <div className="lg:col-span-2 xl:col-span-1">
            <ActivityCard activity={board.activity} />
          </div>
        </div>
      </div>

      {dialog}
    </ClinicShell>
  );
}
