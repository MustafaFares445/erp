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
            $table->unsignedInteger('customer_order_auto_close_days')->default(14);
        });
    }

    public function down(): void
    {
        Schema::table('sales_settings', function (Blueprint $table): void {
            $table->dropColumn('customer_order_auto_close_days');
        });
    }
};
