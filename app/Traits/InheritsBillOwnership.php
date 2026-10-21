<?php

namespace App\Traits;

use App\Models\PatientBilling;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * For the rows that hang off a clinic bill (lines, payments, refunds): the
 * clinic, and the branch where the row has one, come from the bill. A row
 * that names a different clinic or branch than its bill is refused. The
 * composite foreign key enforces the same rule in the database.
 *
 * Runs in `performInsert`, like `InheritsClinicOwnership`, so it holds even
 * when model events are faked.
 */
trait InheritsBillOwnership
{
    protected function performInsert(Builder $query)
    {
        $billId = $this->getAttribute('patient_billing_id');
        $bill = $billId ? PatientBilling::query()->withoutGlobalScopes()->whereKey($billId)->first(['id', 'clinic_id', 'branch_id']) : null;

        if ($bill) {
            foreach (['clinic_id', 'branch_id'] as $column) {
                if (! in_array($column, $this->getFillable(), true)) {
                    continue;
                }

                $own = $this->getAttribute($column);
                if ($own !== null && (int) $own !== (int) $bill->getAttribute($column)) {
                    throw new LogicException(class_basename($this)." names a {$column} other than its bill's.");
                }

                $this->setAttribute($column, $bill->getAttribute($column));
            }
        }

        return parent::performInsert($query);
    }
}
