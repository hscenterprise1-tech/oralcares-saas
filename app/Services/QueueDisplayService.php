<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\QueueDisplay;
use Illuminate\Database\Eloquent\Collection;

/**
 * @deprecated The first waiting-room display service. Only its own test calls it; no
 * route or page reaches it. Kept until that test is retired with approval;
 * build nothing on it.
 */
class QueueDisplayService
{
    /**
     * Register a new queue display session.
     */
    public function createSession(array $data): QueueDisplay
    {
        return QueueDisplay::create($data);
    }

    /**
     * Retrieve active displays matching branch, floor, and department rules.
     */
    public function getActiveDisplays(Branch $branch, ?string $floor = null, ?string $department = null): Collection
    {
        $query = QueueDisplay::where('branch_id', $branch->id)
            ->where('is_active', true);

        if ($floor) {
            $query->where('floor', $floor);
        }

        if ($department) {
            $query->where('department', $department);
        }

        return $query->get();
    }
}
