<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;

/** Someone the clinic pays: a landlord, a lab, a supplier. */
class ClinicVendor extends Model
{
    use ClinicScoped;

    protected $fillable = ['clinic_id', 'name', 'phone', 'email', 'tax_number', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
