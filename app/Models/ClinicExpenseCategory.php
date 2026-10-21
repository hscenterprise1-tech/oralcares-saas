<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;

/** A heading the clinic files its spending under (rent, consumables, lab…). */
class ClinicExpenseCategory extends Model
{
    use ClinicScoped;

    protected $fillable = ['clinic_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
