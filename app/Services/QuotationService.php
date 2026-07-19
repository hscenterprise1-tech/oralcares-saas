<?php

namespace App\Services;

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\QuotationStatus;
use Illuminate\Support\Facades\DB;
use LogicException;

class QuotationService
{
    public function __construct(
        protected QuotationNumberService $numberService,
        protected QuotationActivityService $activityService
    ) {}

    /**
     * Create a new quotation.
     */
    public function createQuotation(array $data, array $items, CompanyEmployee $preparedBy): CompanyQuotation
    {
        return DB::transaction(function () use ($data, $items, $preparedBy) {
            $quotation = new CompanyQuotation($data);
            $quotation->quotation_number = $this->numberService->generateNextNumber();
            $quotation->version = 1;
            $quotation->prepared_by_id = $preparedBy->id;
            $quotation->status = QuotationStatus::Draft;
            $quotation->save();

            $this->saveItemsAndCalculateTotals($quotation, $items);

            $this->activityService->logActivity(
                $quotation,
                'created',
                'Quotation '.$quotation->quotation_number.' created in draft mode.',
                $preparedBy
            );

            return $quotation;
        });
    }

    /**
     * Update an existing draft quotation.
     */
    public function updateQuotation(CompanyQuotation $quotation, array $data, array $items, CompanyEmployee $updatedBy): CompanyQuotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new LogicException('Only draft quotations can be updated directly. Create a new version for revisions.');
        }

        return DB::transaction(function () use ($quotation, $data, $items, $updatedBy) {
            $quotation->update($data);

            $quotation->items()->delete();
            $this->saveItemsAndCalculateTotals($quotation, $items);

            $this->activityService->logActivity(
                $quotation,
                'updated',
                'Quotation updated.',
                $updatedBy
            );

            return $quotation;
        });
    }

    /**
     * Create a new revised version of a quotation.
     */
    public function createRevision(CompanyQuotation $parentQuotation, CompanyEmployee $revisedBy): CompanyQuotation
    {
        return DB::transaction(function () use ($parentQuotation, $revisedBy) {
            $baseParentId = $parentQuotation->parent_id ?: $parentQuotation->id;
            $maxVersion = CompanyQuotation::where('quotation_number', $parentQuotation->quotation_number)->max('version');

            $newVersion = new CompanyQuotation;
            $newVersion->quotation_number = $parentQuotation->quotation_number;
            $newVersion->version = $maxVersion + 1;
            $newVersion->parent_id = $baseParentId;
            $newVersion->lead_id = $parentQuotation->lead_id;
            $newVersion->clinic_id = $parentQuotation->clinic_id;
            $newVersion->prepared_by_id = $revisedBy->id;
            $newVersion->assigned_id = $parentQuotation->assigned_id;
            $newVersion->status = QuotationStatus::Draft;
            $newVersion->valid_until = $parentQuotation->valid_until;
            $newVersion->notes = $parentQuotation->notes;
            $newVersion->subtotal = $parentQuotation->subtotal;
            $newVersion->discount = $parentQuotation->discount;
            $newVersion->tax = $parentQuotation->tax;
            $newVersion->grand_total = $parentQuotation->grand_total;
            $newVersion->save();

            foreach ($parentQuotation->items as $parentItem) {
                $newVersion->items()->create([
                    'title' => $parentItem->title,
                    'description' => $parentItem->description,
                    'quantity' => $parentItem->quantity,
                    'unit_price' => $parentItem->unit_price,
                    'discount' => $parentItem->discount,
                    'total' => $parentItem->total,
                ]);
            }

            $this->activityService->logActivity(
                $newVersion,
                'created',
                'New version (v'.$newVersion->version.') created from version v'.$parentQuotation->version.'.',
                $revisedBy
            );

            return $newVersion;
        });
    }

    /**
     * Transition the status of a quotation.
     */
    public function transitionStatus(CompanyQuotation $quotation, QuotationStatus|string $newStatus, ?CompanyEmployee $employee = null, ?string $notes = null): void
    {
        $statusEnum = $newStatus instanceof QuotationStatus ? $newStatus : QuotationStatus::from($newStatus);

        $oldStatus = $quotation->status;
        $quotation->status = $statusEnum;
        $quotation->save();

        $this->activityService->logActivity(
            $quotation,
            $statusEnum->value,
            "Quotation status transitioned from {$oldStatus->value} to {$statusEnum->value}. ".($notes ?? ''),
            $employee
        );
    }

    /**
     * Save line items and update quotation calculation totals.
     */
    protected function saveItemsAndCalculateTotals(CompanyQuotation $quotation, array $items): void
    {
        $subtotal = 0;
        $discount = 0;

        foreach ($items as $itemData) {
            $qty = (int) ($itemData['quantity'] ?? 1);
            $price = (float) ($itemData['unit_price'] ?? 0.00);
            $itemDiscount = (float) ($itemData['discount'] ?? 0.00);

            $total = ($price * $qty) - $itemDiscount;

            $quotation->items()->create([
                'title' => $itemData['title'],
                'description' => $itemData['description'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $itemDiscount,
                'total' => $total,
            ]);

            $subtotal += ($price * $qty);
            $discount += $itemDiscount;
        }

        $tax = (float) ($quotation->tax ?? 0.00);
        $grandTotal = $subtotal - $discount + $tax;

        $quotation->update([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'grand_total' => $grandTotal,
        ]);
    }
}
