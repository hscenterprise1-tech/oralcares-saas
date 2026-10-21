<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A raw delivery callback for a clinic connection, kept for reconciliation. */
class ClinicWhatsAppWebhookEvent extends Model
{
    protected $table = 'clinic_whatsapp_webhook_events';

    protected $fillable = ['whatsapp_connection_id', 'provider_event_id', 'provider_message_id', 'event_type', 'payload', 'signature_valid', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime'];
    }
}
