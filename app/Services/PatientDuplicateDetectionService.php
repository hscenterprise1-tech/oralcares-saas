<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Support\Collection;

class PatientDuplicateDetectionService
{
    /**
     * Scan the database for potential duplicate patient records.
     */
    public function detectDuplicates(Clinic $clinic, array $criteria): Collection
    {
        $query = Patient::where('clinic_id', $clinic->id);

        $query->where(function ($q) use ($criteria) {
            if (! empty($criteria['phone'])) {
                $q->orWhere('phone', $criteria['phone']);
            }

            if (! empty($criteria['email'])) {
                $q->orWhere('email', $criteria['email']);
            }

            if (! empty($criteria['name']) && ! empty($criteria['date_of_birth'])) {
                $q->orWhere(function ($sub) use ($criteria) {
                    $sub->where('name', $criteria['name'])
                        ->where('date_of_birth', $criteria['date_of_birth']);
                });
            }
        });

        return $query->get();
    }
}
