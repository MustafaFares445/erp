<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('statement_number')->unique();
            $table->string('import_hash', 64)->unique();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('status', 24)->default('open')->index();
            $table->string('currency_code', 3)->index();
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2);
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('line_hash', 64)->index();
            $table->date('transaction_date')->index();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable()->index();
            $table->string('counterparty')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('unmatched')->index();
            $table->timestamps();
            $table->unique(['bank_statement_id', 'sequence'], 'bank_statement_line_sequence_unique');
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->cascadeOnDelete();
            $table->morphs('matchable');
            $table->decimal('amount', 15, 2);
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['matchable_type', 'matchable_id'], 'bank_recon_matchable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
