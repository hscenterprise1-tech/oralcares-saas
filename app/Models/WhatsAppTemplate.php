<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved WhatsApp template on a connection, for one automation. A
 * template with no clinic serves every clinic on that connection; a clinic's
 * own copy overrides it for that clinic only.
 */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = ['whatsapp_connection_id', 'clinic_id', 'automation_key', 'name', 'language', 'category', 'body', 'variables', 'status'];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
