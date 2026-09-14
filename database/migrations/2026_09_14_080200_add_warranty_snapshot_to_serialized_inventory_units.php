<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->date('warranty_started_on')->nullable()->after('stock_condition');
            $table->date('warranty_expires_on')->nullable()->after('warranty_started_on');
            $table->foreignId('warranty_source_shipment_id')->nullable()->after('warranty_expires_on')->constrained('shipments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warranty_source_shipment_id');
            $table->dropColumn(['warranty_started_on', 'warranty_expires_on']);
        });
    }
};
