<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalyticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinic_id' => $this->clinic_id,
            'branch_id' => $this->branch_id,
            'metric_key' => $this->metric_key,
            'metric_value' => $this->metric_value,
            'dimension_date' => $this->dimension_date,
        ];
    }
}
