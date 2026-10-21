<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One patient WhatsApp message, always owned by one clinic, whichever number
 * carried it. Outbound messages are created by `ClinicWhatsApp` only, with an
 * idempotency key, and sent by `SendClinicWhatsAppMessage`.
 */
class ClinicWhatsAppMessage extends Model
{
    use ClinicScoped;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'clinic_whatsapp_messages';

    protected $fillable = [
        'clinic_id', 'branch_id', 'whatsapp_connection_id', 'whatsapp_template_id', 'patient_id', 'notification_id', 'direction', 'automation_key', 'event_key',
        'idempotency_key', 'recipient_phone', 'variables', 'preview', 'status', 'provider_message_id', 'attempts', 'failure_reason',
        'subject_type', 'subject_id', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
