<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make tenant storage measurable.
 *
 * The plan sells a storage allowance and the licence records one, but nothing
 * could compute what a tenant was actually using: three of the five attachment
 * tables carry `file_size_bytes` and two do not. A usage figure that silently
 * omitted clinical and lab attachments would be wrong in exactly the direction
 * that matters, so telemetry could not honestly report storage at all.
 *
 * Nullable, so existing rows stay unmeasured rather than being reported as
 * zero — an unknown size and a zero-byte file are not the same claim.
 */
return new class extends Migration
{
    /** @var list<string> */
    protected array $tables = ['clinical_attachments', 'lab_order_attachments'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'file_size_bytes')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('file_size_bytes')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'file_size_bytes')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('file_size_bytes');
            });
        }
    }
};
