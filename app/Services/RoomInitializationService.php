<?php

namespace App\Services;

use App\Events\RoomCreated;
use App\Models\Branch;
use App\Models\ClinicRoom;

class RoomInitializationService
{
    /**
     * Initialize default rooms for a branch.
     */
    public function initializeDefaultRooms(Branch $branch): void
    {
        $defaults = [
            [
                'key' => 'operatory_1',
                'name' => 'Operatory room 1',
                'type' => 'operatory',
            ],
            [
                'key' => 'consultation_1',
                'name' => 'Consultation Room 1',
                'type' => 'consultation',
            ],
            [
                'key' => 'xray_room',
                'name' => 'X-Ray Scanner Room',
                'type' => 'xray',
            ],
            [
                'key' => 'lab_room',
                'name' => 'Clinical Lab Room',
                'type' => 'lab',
            ],
            [
                'key' => 'waiting_area',
                'name' => 'Main Waiting Lounge',
                'type' => 'waiting',
            ],
        ];

        foreach ($defaults as $roomData) {
            $exists = ClinicRoom::where('branch_id', $branch->id)
                ->where('key', $roomData['key'])
                ->exists();

            if (! $exists) {
                $room = ClinicRoom::create([
                    'branch_id' => $branch->id,
                    'key' => $roomData['key'],
                    'name' => $roomData['name'],
                    'type' => $roomData['type'],
                    'is_active' => true,
                ]);

                event(new RoomCreated($room));
            }
        }
    }
}
