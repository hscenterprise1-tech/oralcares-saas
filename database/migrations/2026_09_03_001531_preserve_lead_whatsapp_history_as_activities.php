<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keep the WhatsApp history that lived in a table nothing reads.
 *
 * `lead_whatsapp_messages` was one of two WhatsApp systems: the CRM wrote to
 * it, and the deal page rendered the Marketing conversation instead. Every
 * writer has now been moved onto the Marketing account, so the table is
 * finished — but it holds real messages sent to real prospects, and the deal
 * timeline is where somebody would look for them.
 *
 * They are copied in as activities, marked so the origin is never in doubt.
 * The rows themselves are left in place: the table is dropped only after
 * provider delivery has been verified in production, so there is something to
 * fall back to if it has not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('lead_whatsapp_messages')) {
            return;
        }

        $messages = DB::table('lead_whatsapp_messages')->orderBy('id')->get();

        foreach ($messages as $message) {
            $exists = DB::table('activities')
                ->where('subject_type', 'lead')
                ->where('subject_id', $message->lead_id)
                ->where('properties->migrated_whatsapp_message_id', $message->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $direction = $message->direction === 'inbound' ? 'received' : 'sent';

            DB::table('activities')->insert([
                'subject_type' => 'lead',
                'subject_id' => $message->lead_id,
                'user_id' => null,
                'type' => 'whatsapp',
                'description' => "WhatsApp {$direction} — ".$message->message_body,
                'properties' => json_encode([
                    'migrated_whatsapp_message_id' => $message->id,
                    'direction' => $message->direction,
                    'status' => $message->status,
                    'source' => 'lead_whatsapp_messages',
                ]),
                'created_at' => $message->created_at,
                'updated_at' => $message->created_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('activities')
            ->where('type', 'whatsapp')
            ->whereNotNull('properties->migrated_whatsapp_message_id')
            ->delete();
    }
};
