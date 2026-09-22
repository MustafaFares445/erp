<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved, named breach of a constraint whose enforcement mode is
 * `require_approval`.
 *
 * Shaped after `price_floor_overrides`, which already proves the pattern in
 * this codebase: the row is immutable, carries the attempted value alongside
 * the limit it crossed, and names both the approver and the reason, so the
 * exception stays explainable long after the document has moved on.
 *
 * The subject morph is nullable because a breach can be approved before the
 * document that will carry it exists, exactly as a price-floor approval
 * precedes the quotation line it unlocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constraint_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('constraint_key', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->decimal('attempted_value', 15, 4);
            $table->decimal('limit_value', 15, 4);
            $table->text('reason');
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['constraint_key', 'approved_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('constraint_overrides');
    }
};
