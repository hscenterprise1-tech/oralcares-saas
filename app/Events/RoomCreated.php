<?php

namespace App\Events;

use App\Models\ClinicRoom;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicRoom $room
    ) {}
}
