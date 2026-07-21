<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ent_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id')->index(); // References ent_notifications.id
            $table->string('channel')->index();
            $table->string('status')->index(); // sent, delivered, failed
            $table->string('recipient_contact')->nullable(); // email address, phone number
            $table->text('provider_response')->nullable(); // JSON response from SES, Twilio, etc
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ent_notification_logs');
    }
};
