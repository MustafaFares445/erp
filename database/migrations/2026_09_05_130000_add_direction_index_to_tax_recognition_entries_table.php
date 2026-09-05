<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the tax register derivation (WP-2.7): every period
 * figure filters `tax_recognition_entries` by `tax_date` between a range and,
 * for three of the four figures, by `direction` as well. Entries are
 * immutable facts — the register is a pure derivation, so no other schema
 * change is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->index(['tax_date', 'direction'], 'tax_recognition_entries_tax_date_direction_index');
        });
    }

    public function down(): void
    {
        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->dropIndex('tax_recognition_entries_tax_date_direction_index');
        });
    }
};
