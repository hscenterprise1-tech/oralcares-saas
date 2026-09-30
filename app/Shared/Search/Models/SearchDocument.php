<?php

namespace App\Shared\Search\Models;

use App\Models\Branch;
use App\Models\Clinic;
use App\Shared\Search\Enums\SearchModule;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchDocument extends Model
{
    use HasUuids;

    protected $table = 'ent_search_documents';

    protected $fillable = [
        'module',
        'model_type',
        'model_id',
        'clinic_id',
        'branch_id',
        'title',
        'subtitle',
        'url',
        'searchable_text',
        'metadata',
    ];

    protected $casts = [
        'module' => SearchModule::class,
        'metadata' => 'array',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
