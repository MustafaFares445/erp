<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->foreignId('inventory_return_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('inventory_returns')
                ->nullOnDelete();
            $table->string('stock_consequence', 30)
                ->default('not_applicable')
                ->after('reason_category');
            $table->index('inventory_return_id', 'credit_notes_inventory_return_idx');
        });

        Schema::table('credit_note_lines', function (Blueprint $table): void {
            $table->foreignId('inventory_return_line_id')
                ->nullable()
                ->after('invoice_line_id')
                ->constrained('inventory_return_lines')
                ->nullOnDelete();
            $table->unique(
                ['credit_note_id', 'inventory_return_line_id'],
                'credit_note_return_line_unique',
            );
            $table->index('inventory_return_line_id', 'credit_note_lines_return_line_idx');
        });

        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->boolean('credit_note_required')
                ->default(false)
                ->after('financial_reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_note_lines', function (Blueprint $table): void {
            $table->dropUnique('credit_note_return_line_unique');
            $table->dropIndex('credit_note_lines_return_line_idx');
            $table->dropConstrainedForeignId('inventory_return_line_id');
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropIndex('credit_notes_inventory_return_idx');
            $table->dropConstrainedForeignId('inventory_return_id');
            $table->dropColumn('stock_consequence');
        });

        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropColumn('credit_note_required');
        });
    }
};
