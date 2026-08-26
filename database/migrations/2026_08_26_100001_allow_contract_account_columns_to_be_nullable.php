<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_account_id')->nullable()->change();
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_account_id')->nullable(false)->change();
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_account_id')->nullable(false)->change();
        });
    }
};
