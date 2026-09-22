<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('payment_transaction_id')->nullable()->after('payment_method_id')
                ->constrained('payment_transactions')->nullOnDelete();
            $table->string('provider_reference')->nullable()->after('payment_transaction_id');
            $table->string('provider_status', 20)->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_transaction_id');
            $table->dropColumn(['provider_reference', 'provider_status']);
        });
    }
};
