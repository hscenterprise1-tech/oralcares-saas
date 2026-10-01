<?php

namespace App\Services\Appointments;

use App\Models\Branch;
use App\Models\Clinic;
use App\Services\AppointmentConflictService;
use App\Services\Reception\PatientFlowConfigService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Picks the room or chair for a booking, so Reception never has to.
 *
 * A provider's resources are the active rooms they are assigned to at the
 * branch (Staff > rooms, `clinic_employee_rooms`). A provider with none
 * books without a resource. A provider with some gets the first one free for
 * the time; when all of them are taken, the time is not bookable.
 *
 * Only while the clinic uses rooms (Settings > Patient Flow).
 */
class AppointmentResourceResolver
{
    public function __construct(
        protected PatientFlowConfigService $flowConfig,
        protected AppointmentConflictService $conflicts,
    ) {}

    /**
     * Each provider's rooms at the branch, in one query.
     *
     * @param  iterable<int>  $providerIds
     * @return array<int, list<int>> provider id => room ids
     */
    public function roomsFor(Clinic $clinic, Branch $branch, iterable $providerIds): array
    {
        $ids = collect($providerIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty() || ! $this->flowConfig->roomsEnabled($clinic)) {
            return [];
        }

        return DB::table('clinic_employee_rooms')
            ->join('clinic_rooms', 'clinic_rooms.id', '=', 'clinic_employee_rooms.clinic_room_id')
            ->whereIn('clinic_employee_rooms.clinic_employee_id', $ids)
            ->where('clinic_rooms.branch_id', $branch->id)
            ->where('clinic_rooms.is_active', true)
            ->orderBy('clinic_rooms.name')
            ->get(['clinic_employee_rooms.clinic_employee_id as provider_id', 'clinic_rooms.id as room_id'])
            ->groupBy('provider_id')
            ->map(fn ($rows) => $rows->pluck('room_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * The room for this booking: null when the provider has none, otherwise
     * the first of theirs that is free.
     *
     * @return array{room_id: int|null, available: bool}
     */
    public function resolve(Clinic $clinic, Branch $branch, int $providerId, CarbonInterface $start, int $durationMinutes, ?int $ignoreAppointmentId = null): array
    {
        $rooms = $this->roomsFor($clinic, $branch, [$providerId])[$providerId] ?? [];

        if ($rooms === []) {
            return ['room_id' => null, 'available' => true];
        }

        foreach ($rooms as $roomId) {
            if ($this->conflicts->roomIsFree($branch, $roomId, $start, $durationMinutes, $ignoreAppointmentId)) {
                return ['room_id' => $roomId, 'available' => true];
            }
        }

        return ['room_id' => null, 'available' => false];
    }
}
