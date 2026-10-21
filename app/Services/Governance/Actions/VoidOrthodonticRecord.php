<?php

namespace App\Services\Governance\Actions;

use App\Models\OrthodonticCase;
use Illuminate\Database\Eloquent\Model;

class VoidOrthodonticRecord extends VoidDentalRecord
{
    public function key(): string
    {
        return 'orthodontic.void';
    }

    public function label(): string
    {
        return 'Void orthodontic case';
    }

    public function targetType(): string
    {
        return 'orthodontic';
    }

    protected function model(): string
    {
        return OrthodonticCase::class;
    }

    protected function noun(Model $target): string
    {
        return 'Orthodontic case';
    }
}
