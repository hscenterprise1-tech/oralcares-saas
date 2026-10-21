<?php

namespace App\Services\Governance\Actions;

use App\Models\EndodonticRecord;
use Illuminate\Database\Eloquent\Model;

class VoidEndodonticRecord extends VoidDentalRecord
{
    public function key(): string
    {
        return 'endodontic.void';
    }

    public function label(): string
    {
        return 'Void root canal record';
    }

    public function targetType(): string
    {
        return 'endodontic';
    }

    protected function model(): string
    {
        return EndodonticRecord::class;
    }

    protected function noun(Model $target): string
    {
        return "Root canal record on tooth {$target->tooth_number}";
    }
}
