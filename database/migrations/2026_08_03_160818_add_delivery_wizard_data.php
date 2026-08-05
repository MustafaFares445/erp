<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('customer_delivery_address_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->string('delivery_type', 20)->nullable()->after('scheduled_at');
            $table->foreignId('responsible_id')->nullable()->after('delivery_type')->constrained('users')->nullOnDelete();
            $table->string('tracking_reference', 100)->nullable()->after('responsible_id');
            $table->json('destination_address_snapshot')->nullable()->after('tracking_reference');
        });

        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->foreignId('customer_delivery_address_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->nullOnDelete();
            $table->json('source_address_snapshot')->nullable()->after('delivery_type');
            $table->json('destination_address_snapshot')->nullable()->after('source_address_snapshot');
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->string('allocation_source', 20)->default('automatic')->after('quantity')->index();
        });
    }
};
