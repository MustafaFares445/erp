<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No `deleted_at`, matching the ERD and required by FR-025: a posted entry
     * is immutable and undeletable by any path, and a draft is hard-deleted
     * because it was never in the ledger.
     *
     * `fiscal_period_id` is nullable because a draft has no period yet — it is
     * resolved from `entry_date` at the moment of posting (research.md R-004).
     *
     * The `source` morph carries the document that produced the entry, and for
     * a reversal it points at the entry being reversed, which is why no
     * dedicated reversal column exists (research.md R-003).
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->restrictOnDelete();
            $table->string('entry_number', 100)->unique();
            $table->date('entry_date')->index();
            $table->text('description')->nullable();
            $table->nullableMorphs('source');
            $table->string('status', 50)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
