<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer support, from the vendor's side of the desk.
 *
 * Nothing like this existed: no table, no route, no service, and a
 * `view-support` permission held by Super Admin that pointed at nothing at all.
 *
 * Deliberately not CRM tasks. A task is internal work somebody owns; a ticket
 * is a customer waiting for an answer, with their organisation attached, an SLA
 * clock and a conversation. They share no lifecycle worth merging.
 *
 * The SLA targets are configuration, seeded with nothing. How long a customer
 * should wait for a first reply is a commitment this business makes, not a
 * constant this file gets to choose — so the structure is here, the clock runs
 * off it, and until somebody sets a target no ticket has a due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * How long each priority is allowed to take. Empty until configured.
         */
        Schema::create('company_support_sla_targets', function (Blueprint $table) {
            $table->id();
            $table->string('priority')->unique();
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            /*
             * Who it is for.
             *
             * The organisation is the account; the clinic narrows it to the
             * tenant when the customer named one. Both nullable because a
             * ticket can arrive before anybody has worked out which account it
             * belongs to, and refusing it then would lose the customer.
             */
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();

            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('category')->nullable();
            $table->string('channel')->default('portal');

            $table->string('status')->default('open');
            $table->string('priority')->default('normal');

            $table->foreignId('assigned_to')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            /*
             * The SLA clock. Both null when no target is configured for the
             * priority — a ticket with no due date is honest about it rather
             * than being measured against an interval nobody chose.
             */
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('organization_id');
            $table->index('assigned_to');
        });

        /*
         * Everything that has happened to a ticket, in order.
         *
         * Notes, replies and every status or assignment change land in the same
         * stream, because a history split across three tables is a history
         * nobody reads. Rows are written and never edited.
         */
        Schema::create('company_support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('company_support_tickets')->cascadeOnDelete();
            /* note | reply | status | priority | assignment | opened | resolved */
            $table->string('event_type');
            $table->text('body')->nullable();
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            /* Whether the customer can see it, or it is an internal note. */
            $table->boolean('is_internal')->default(true);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_support_ticket_events');
        Schema::dropIfExists('company_support_tickets');
        Schema::dropIfExists('company_support_sla_targets');
    }
};
