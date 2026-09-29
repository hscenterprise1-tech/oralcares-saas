<?php

namespace App\Shared\Search\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    protected $table = 'ent_saved_searches';

    protected $fillable = [
        'user_id',
        'name',
        'query_string',
        'filters',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
