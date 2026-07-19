<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaboratoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lab_order_number' => $this->lab_order_number,
            'status' => $this->status,
            'patient_id' => $this->patient_id,
            'encounter_id' => $this->encounter_id,
        ];
    }
}
