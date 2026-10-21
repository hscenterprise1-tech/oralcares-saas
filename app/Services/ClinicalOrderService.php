<?php

namespace App\Services;

use App\ClinicDocument;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\ClinicInvestigation;
use App\Models\DiagnosticResult;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\Communication\PatientMessaging;
use App\Services\Numbering\ClinicDocumentNumbers;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of investigations: Order → Fulfilment → Result → Review.
 *
 * An order is placed from an open consultation and numbered from the
 * clinic's order series. Each item then moves on its own:
 *
 *     ordered ──collect──▶ collected ──result──▶ resulted ──review──▶ reviewed
 *        └──────────────result──────────────▲
 *     cancel: ordered | collected ──▶ cancelled
 *
 * A result can be re-entered until a clinician reviews it; after review it
 * is the record and is never changed. The order completes when every item
 * is reviewed or cancelled. Every test ordered is remembered on the clinic's
 * own investigation list.
 *
 * Authorisation is the caller's (`ClinicalOrderPolicy`); this class enforces
 * ownership, state and order.
 */
class ClinicalOrderService
{
    /** Flags on a result value that make the result abnormal. */
    private const ABNORMAL_FLAGS = ['high', 'low', 'abnormal', 'critical', 'positive'];

    public function __construct(
        protected ClinicDocumentNumbers $numbers,
        protected PatientMessaging $messages,
    ) {}

    /**
     * @param  array{order_type?: string, priority?: string, clinical_instructions?: ?string, details?: array<string, mixed>}  $orderData
     * @param  array<int, array{investigation_id?: ?int, item_name?: ?string, item_code?: ?string, category?: ?string, body_site?: ?string, external_provider?: ?string, notes?: ?string, details?: array<string, mixed>}>  $items
     */
    public function placeOrder(ClinicalEncounter $encounter, array $orderData, array $items, ?ClinicEmployee $orderer = null): ClinicalOrder
    {
        if ($items === []) {
            throw new InvalidArgumentException('Add at least one investigation to the order.');
        }

        return DB::transaction(function () use ($encounter, $orderData, $items, $orderer) {
            /** @var ClinicalEncounter $encounter */
            $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (! $encounter->isEditable()) {
                throw new DomainException('This consultation is signed. Order investigations from a new consultation.');
            }

            $orderer ??= ClinicEmployee::query()->withoutGlobalScopes()
                ->where('clinic_id', $encounter->clinic_id)->where('user_id', auth()->id())->first();
            $lines = array_map(fn (array $item) => $this->line($encounter->clinic_id, $item), $items);
            $categories = array_values(array_unique(array_column($lines, 'category')));

            $order = ClinicalOrder::query()->create([
                'organization_id' => $encounter->organization_id,
                'clinic_id' => $encounter->clinic_id,
                'branch_id' => $encounter->branch_id,
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordered_by' => auth()->id(),
                'orderer_id' => $orderer?->id ?? $encounter->doctor_id,
                'order_number' => $this->numbers->next(ClinicDocument::ClinicalOrder, Clinic::query()->findOrFail($encounter->clinic_id)),
                'order_type' => $orderData['order_type'] ?? (count($categories) === 1 ? $categories[0] : 'mixed'),
                'status' => ClinicalOrder::ACTIVE,
                'priority' => in_array($orderData['priority'] ?? 'routine', ['routine', 'urgent', 'stat'], true) ? ($orderData['priority'] ?? 'routine') : 'routine',
                'clinical_instructions' => $orderData['clinical_instructions'] ?? null,
                'details' => $orderData['details'] ?? [],
            ]);

            foreach ($lines as $line) {
                $order->items()->create([...$line, 'status' => ClinicalOrderItem::ORDERED]);
            }

            $this->timeline($order, 'clinical_order_placed', [
                'order_type' => $order->order_type,
                'item_count' => count($lines),
                'items' => array_column($lines, 'item_name'),
            ]);

            return $order;
        });
    }

    /**
     * Fulfilment: the sample is collected, the images taken, or the referral
     * sent out.
     */
    public function markCollected(ClinicalOrderItem $item, ?ClinicEmployee $by, ?string $note = null): ClinicalOrderItem
    {
        return DB::transaction(function () use ($item, $by, $note) {
            $item = $this->lock($item);
            $this->expect($item, [ClinicalOrderItem::ORDERED], 'Only an ordered investigation can be marked collected.');

            $item->update([
                'status' => ClinicalOrderItem::COLLECTED,
                'collected_at' => now(),
                'collected_by' => $by?->id,
                'notes' => $this->append($item->notes, $note),
            ]);

            return $item;
        });
    }

    /**
     * Enter (or correct, until it is reviewed) the result of an
     * investigation. An external result can arrive without a collection step.
     *
     * @param  array{summary?: ?string, values?: array<int, array{name: string, value: mixed, unit?: ?string, reference_range?: ?string, flag?: ?string}>, is_abnormal?: bool, content?: array<string, mixed>}  $data
     */
    public function recordResult(ClinicalOrderItem $item, ?ClinicEmployee $by, array $data, ?User $reporter = null): DiagnosticResult
    {
        return DB::transaction(function () use ($item, $by, $data, $reporter) {
            $item = $this->lock($item);
            $this->expect($item, ClinicalOrderItem::OPEN, 'This investigation is closed: it was reviewed or cancelled.');

            $values = array_values(array_filter($data['values'] ?? [], fn (array $value) => trim((string) ($value['name'] ?? '')) !== ''));
            $summary = ($summary = trim((string) ($data['summary'] ?? ''))) === '' ? null : $summary;
            if ($summary === null && $values === [] && empty($data['content'])) {
                throw new InvalidArgumentException('Enter the report or at least one value.');
            }

            $abnormal = (bool) ($data['is_abnormal'] ?? false)
                || collect($values)->contains(fn (array $value) => in_array(strtolower((string) ($value['flag'] ?? '')), self::ABNORMAL_FLAGS, true));

            $result = $item->result()->first() ?? new DiagnosticResult([
                'clinical_order_item_id' => $item->id,
                'organization_id' => $item->order()->withoutGlobalScopes()->value('organization_id'),
                'patient_id' => $item->order()->withoutGlobalScopes()->value('patient_id'),
            ]);
            $result->fill([
                'status' => DiagnosticResult::DRAFT,
                'result_type' => $this->resultType($item->category),
                'summary' => $summary,
                'is_abnormal' => $abnormal,
                'content' => [...($data['content'] ?? []), 'values' => $values],
                'reported_by' => $reporter?->id ?? auth()->id(),
                'reporter_id' => $by?->id,
                'reported_at' => now(),
            ])->save();

            $item->update(['status' => ClinicalOrderItem::RESULTED, 'resulted_at' => now()]);

            $this->timeline($item->order()->withoutGlobalScopes()->firstOrFail(), 'investigation_resulted', [
                'item_id' => $item->id, 'item_name' => $item->item_name, 'is_abnormal' => $abnormal,
            ]);

            return $result;
        });
    }

    /**
     * A clinician reads the result. It is final from here.
     */
    public function review(ClinicalOrderItem $item, ?ClinicEmployee $by, ?string $note = null, ?User $verifier = null): DiagnosticResult
    {
        return DB::transaction(function () use ($item, $by, $note, $verifier) {
            $item = $this->lock($item);
            $this->expect($item, [ClinicalOrderItem::RESULTED], 'There is no result to review yet.');

            /** @var DiagnosticResult $result */
            $result = $item->result()->firstOrFail();
            $result->update([
                'status' => DiagnosticResult::FINAL,
                'verified_by' => $verifier?->id ?? $by?->user_id ?? auth()->id(),
                'reviewer_id' => $by?->id,
                'verified_at' => now(),
                'review_note' => ($note = trim((string) $note)) === '' ? null : $note,
            ]);

            $item->update(['status' => ClinicalOrderItem::REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $by?->id]);

            $order = $item->order()->withoutGlobalScopes()->firstOrFail();
            $this->timeline($order, 'investigation_reviewed', [
                'item_id' => $item->id, 'item_name' => $item->item_name, 'is_abnormal' => $result->is_abnormal,
            ]);
            $this->closeIfDone($order);
            DB::afterCommit(fn () => $this->messages->reportReviewed($item));

            return $result;
        });
    }

    public function cancelItem(ClinicalOrderItem $item, ?ClinicEmployee $by, string $reason): ClinicalOrderItem
    {
        return DB::transaction(function () use ($item, $reason) {
            $item = $this->lock($item);
            $this->expect($item, [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED], 'A resulted investigation cannot be cancelled; review it instead.');
            if (trim($reason) === '') {
                throw new InvalidArgumentException('Say why the investigation is cancelled.');
            }

            $item->update(['status' => ClinicalOrderItem::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason)]);
            $this->closeIfDone($item->order()->withoutGlobalScopes()->firstOrFail());

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function line(int $clinicId, array $item): array
    {
        $known = null;
        if (! empty($item['investigation_id'])) {
            $known = ClinicInvestigation::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($item['investigation_id'])->first()
                ?? throw new InvalidArgumentException('That investigation is not on this clinic\'s list.');
        }

        $name = trim((string) ($item['item_name'] ?? $known?->name ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name the investigation.');
        }

        $category = $item['category'] ?? $known?->category ?? 'lab';
        if (! array_key_exists($category, ClinicalOrderItem::CATEGORIES)) {
            throw new InvalidArgumentException('Unknown kind of investigation.');
        }

        $known ??= $this->remember($clinicId, $name, $category, $item['item_code'] ?? null);
        $known->increment('use_count');
        $clean = fn (string $key) => ($value = trim((string) ($item[$key] ?? ''))) === '' ? null : $value;

        return [
            'investigation_id' => $known->id,
            'item_code' => $clean('item_code') ?? $known->code,
            'item_name' => $name,
            'category' => $category,
            'body_site' => $clean('body_site'),
            'external_provider' => $clean('external_provider'),
            'notes' => $clean('notes'),
            'details' => $item['details'] ?? [],
        ];
    }

    private function remember(int $clinicId, string $name, string $category, ?string $code): ClinicInvestigation
    {
        $key = ClinicInvestigation::lookupKey($name, $category);

        return ClinicInvestigation::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where('lookup_key', $key)->first()
            ?? ClinicInvestigation::query()->create([
                'clinic_id' => $clinicId,
                'name' => $name,
                'code' => $code,
                'category' => $category,
                'lookup_key' => $key,
            ]);
    }

    private function closeIfDone(ClinicalOrder $order): void
    {
        $statuses = $order->items()->withoutGlobalScopes()->pluck('status');
        if ($statuses->contains(fn (string $status) => in_array($status, ClinicalOrderItem::OPEN, true))) {
            return;
        }

        $allCancelled = $statuses->every(fn (string $status) => $status === ClinicalOrderItem::CANCELLED);
        $order->update(['status' => $allCancelled ? ClinicalOrder::CANCELLED : ClinicalOrder::COMPLETED, 'completed_at' => now()]);

        if (! $allCancelled) {
            $this->timeline($order, 'clinical_order_completed', ['order_type' => $order->order_type]);
        }
    }

    private function lock(ClinicalOrderItem $item): ClinicalOrderItem
    {
        return ClinicalOrderItem::query()->withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function expect(ClinicalOrderItem $item, array $allowed, string $refusal): void
    {
        if (! in_array($item->status, $allowed, true)) {
            throw new DomainException($refusal);
        }
    }

    private function resultType(string $category): string
    {
        return match ($category) {
            'lab' => 'laboratory',
            'imaging' => 'imaging',
            'external' => 'external',
            default => 'other',
        };
    }

    private function append(?string $notes, ?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? $notes : trim(($notes ? $notes."\n" : '').$note);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(ClinicalOrder $order, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => $order->organization_id,
            'clinic_id' => $order->clinic_id,
            'patient_id' => $order->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['order_id' => $order->id, 'encounter_id' => $order->encounter_id, 'order_number' => $order->order_number, ...$context],
        ]);
    }
}
