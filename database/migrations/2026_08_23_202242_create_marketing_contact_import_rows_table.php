<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each uploaded row actually did.
 *
 * Until now a contact carried a single `import_id`, set once by the file that
 * created it. That answers "which file first added this person" but not the
 * question the batch page exists for: *"I uploaded this file — what happened to
 * the contacts in it?"* If Dr. Rahul arrives again in Batch B, the importer
 * recognises him and moves on, and Batch B loses all record of having contained
 * him. Its detail page could only ever say "42 duplicates" as a number with
 * nothing behind it.
 *
 * A contact legitimately appears in more than one file over time. That is a
 * many-to-many fact, so it gets its own table rather than being flattened onto
 * the contact. Each row records the outcome the importer decided:
 *
 *   created  — this file added the person
 *   existing — they were already on the list; the file still contained them
 *   invalid  — the row could not be read, so there is no contact to point at
 *
 * `marketing_contacts.import_id` is deliberately left in place and still set to
 * the creating batch. It is the answer to "where did this person first come
 * from", which is a different and still-useful question, and leaving it means
 * every existing query keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_contact_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_id')
                ->constrained('marketing_contact_imports')
                ->cascadeOnDelete();

            /*
             * Null for an invalid row — there is no contact to point at, and
             * that absence is itself the record of what happened.
             */
            $table->foreignId('contact_id')
                ->nullable()
                ->constrained('marketing_contacts')
                ->nullOnDelete();

            $table->unsignedInteger('row_number')->nullable();

            /** created | existing | invalid */
            $table->string('outcome', 20);

            /*
             * A snapshot of what the file said, kept because an invalid row has
             * no contact to read it from and because a later edit to the contact
             * should not rewrite history.
             */
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('normalized_phone')->nullable();

            /** Why an invalid row was rejected. */
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['import_id', 'outcome'], 'mcir_import_outcome_idx');
            $table->index(['contact_id'], 'mcir_contact_idx');

            /*
             * One row per contact per import. A file listing the same number
             * twice is a duplicate *within* the file and is recorded once —
             * counting it twice would inflate the batch's membership.
             */
            $table->unique(['import_id', 'contact_id'], 'mcir_import_contact_unique');
        });

        /*
         * Backfill, so batches that already exist are not left empty.
         *
         * Every contact currently stamped with an `import_id` is recorded as
         * `created` by that batch — which is exactly what it was. Historical
         * duplicate and invalid rows cannot be reconstructed (nothing recorded
         * them at the time), so the stored `duplicate_count` and
         * `invalid_count` on the batch remain the only account of those, and
         * the batch page reads them from there. Nothing that already worked
         * changes its meaning; only rows going forward gain the detail.
         */
        DB::statement('
            INSERT INTO marketing_contact_import_rows
                (import_id, contact_id, outcome, name, phone, normalized_phone, created_at, updated_at)
            SELECT
                c.import_id, c.id, \'created\', c.name, c.phone, c.normalized_phone, c.created_at, c.created_at
            FROM marketing_contacts c
            WHERE c.import_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contact_import_rows');
    }
};
