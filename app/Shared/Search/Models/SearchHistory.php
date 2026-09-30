<?php

namespace App\Shared\Search\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchHistory extends Model
{
    protected $table = 'ent_search_history';

    public $timestamps = false; // We only use searched_at

    protected $fillable = [
        'user_id',
        'query_string',
        'results_count',
        'searched_at',
    ];

    protected $casts = [
        'searched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
