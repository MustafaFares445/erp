<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ERD's generic `status varchar(50) default 'draft/pending'` column is
     * deliberately absent (ERD deviation E-1, ADR 0007): `is_closed` is this
     * table's single lifecycle source of truth, and carrying both would let a
     * period's state be recorded in two places that can disagree. There is no
     * `deleted_at` either, matching the ERD.
     */
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('is_closed')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Serves the containment lookup that resolves an entry's period
            // from its date at posting time (FR-018).
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
