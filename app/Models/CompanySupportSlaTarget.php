<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How long each priority is allowed to take.
 *
 * Seeded with nothing. How long a customer should wait for a first reply is a
 * commitment this business makes, not a constant shipped in a migration — so a
 * priority with no target here simply produces no due date, and the ticket says
 * "no target set" rather than being measured against an interval nobody chose.
 */
class CompanySupportSlaTarget extends Model
{
    protected $table = 'company_support_sla_targets';

    protected $fillable = ['priority', 'first_response_minutes', 'resolution_minutes', 'updated_by'];

    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }
}
