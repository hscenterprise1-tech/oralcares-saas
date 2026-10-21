<?php

namespace App\Services\Governance\Actions;

use App\Models\ImplantRecord;
use Illuminate\Database\Eloquent\Model;

class VoidImplantRecord extends VoidDentalRecord
{
    public function key(): string
    {
        return 'implant.void';
    }

    public function label(): string
    {
        return 'Void implant record';
    }

    public function targetType(): string
    {
        return 'implant';
    }

    protected function model(): string
    {
        return ImplantRecord::class;
    }

    protected function noun(Model $target): string
    {
        return "Implant record at {$target->tooth_number}";
    }
}
