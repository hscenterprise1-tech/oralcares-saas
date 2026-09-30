<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingAutomation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'marketing_automations';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'status',
        'trigger_type',
        'definition',
        'created_by_user_id',
        'updated_by_user_id',
        'activated_at',
        'paused_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(MarketingAutomationExecution::class, 'automation_id');
    }
}
