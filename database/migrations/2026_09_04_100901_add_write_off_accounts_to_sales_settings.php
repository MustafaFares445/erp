<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_settings', function (Blueprint $table): void {
            $table->foreignId('bad_debt_expense_account_id')
                ->nullable()
                ->after('customer_deposits_account_id')
                ->constrained('chart_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bad_debt_expense_account_id');
        });
    }
};
