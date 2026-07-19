<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EncounterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'encounter_number' => $this->encounter_number,
            'status' => $this->status,
            'clinic_id' => $this->clinic_id,
            'branch_id' => $this->branch_id,
            'doctor_id' => $this->doctor_id,
            'patient_id' => $this->patient_id,
        ];
    }
}
