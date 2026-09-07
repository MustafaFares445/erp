<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_condition_changes', function (Blueprint $table): void {
            $table->foreignId('reverses_condition_change_id')
                ->nullable()
                ->after('supplier_return_id')
                ->constrained('inventory_condition_changes')
                ->nullOnDelete();
            $table->foreignId('authorised_by')
                ->nullable()
                ->after('reverses_condition_change_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('authorised_at')->nullable()->after('authorised_by');

            $table->index(['reverses_condition_change_id'], 'inventory_condition_changes_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_condition_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_condition_change_id');
            $table->dropConstrainedForeignId('authorised_by');
            $table->dropColumn('authorised_at');
        });
    }
};
