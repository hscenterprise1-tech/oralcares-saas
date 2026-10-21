<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;

/** A report exported to CSV in the background, for the person who asked. */
class ClinicReportExport extends Model
{
    use ClinicScoped;

    protected $fillable = ['clinic_id', 'requested_by', 'report', 'filters', 'status', 'path', 'rows', 'failure', 'finished_at'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'finished_at' => 'datetime'];
    }
}
