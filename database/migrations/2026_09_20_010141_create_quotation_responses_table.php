<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only customer-decision evidence for a Quotation — never updated or
 * deleted. The mutable projection columns already on `quotations`
 * (`decided_at`, `decision_note`, `decided_by`) remain for backward-compatible
 * display of the *current* decision; this table is the full history plus the
 * distinction between who responded and who recorded the response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('response_type', 20);
            $table->text('note')->nullable();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at');
            $table->string('source_channel', 30)->default('dashboard');
            $table->timestamps();

            $table->index(['quotation_id', 'responded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_responses');
    }
};
