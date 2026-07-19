<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XrayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'xray_number' => $this->xray_number,
            'status' => $this->status,
            'patient_id' => $this->patient_id,
            'encounter_id' => $this->encounter_id,
        ];
    }
}
