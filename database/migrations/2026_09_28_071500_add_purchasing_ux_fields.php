<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('address');
        });

        Schema::table('supplier_product_references', function (Blueprint $table): void {
            $table->string('availability_status', 32)->default('active')->after('notes')->index();
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('availability_status');
            $table->decimal('minimum_order_quantity', 15, 3)->nullable()->after('lead_time_days');
            $table->boolean('is_preferred')->default(false)->after('minimum_order_quantity')->index();
            $table->string('supplier_item_number', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_product_references', function (Blueprint $table): void {
            $table->dropIndex(['availability_status']);
            $table->dropIndex(['is_preferred']);
            $table->dropColumn([
                'availability_status',
                'lead_time_days',
                'minimum_order_quantity',
                'is_preferred',
            ]);
            $table->string('supplier_item_number', 100)->nullable(false)->change();
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
