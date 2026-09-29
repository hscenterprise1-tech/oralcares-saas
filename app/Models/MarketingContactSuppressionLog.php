<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingContactSuppressionLog extends Model
{
    use HasFactory;

    protected $table = 'marketing_contact_suppression_logs';

    protected $fillable = [
        'organization_id',
        'contact_id',
        'old_status',
        'new_status',
        'reason',
        'source',
        'changed_by_user_id',
        'notes',
    ];

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
