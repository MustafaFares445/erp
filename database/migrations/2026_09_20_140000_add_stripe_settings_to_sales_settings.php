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
            $table->boolean('stripe_enabled')->default(false);
            $table->foreignId('stripe_payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->boolean('auto_apply_customer_deposits')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sales_settings', function (Blueprint $table): void {
            $table->dropColumn(['stripe_enabled', 'stripe_payment_method_id', 'auto_apply_customer_deposits']);
        });
    }
};
