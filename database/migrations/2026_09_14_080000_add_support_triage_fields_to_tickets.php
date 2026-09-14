<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('equipment_source')->nullable()->after('is_chargeable')->index();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->after('equipment_source')->constrained()->nullOnDelete();
            $table->string('external_equipment_name')->nullable()->after('serialized_inventory_unit_id');
            $table->string('external_equipment_model')->nullable()->after('external_equipment_name');
            $table->string('external_serial_number')->nullable()->after('external_equipment_model');
            $table->string('warranty_status')->nullable()->after('external_serial_number')->index();
            $table->date('warranty_expiry_date')->nullable()->after('warranty_status');
            $table->string('service_path')->nullable()->after('warranty_expiry_date')->index();
            $table->timestamp('triaged_at')->nullable()->after('service_path');
            $table->foreignId('triaged_by')->nullable()->after('triaged_at')->constrained('users')->nullOnDelete();
            $table->text('charge_waived_reason')->nullable()->after('triaged_by');
            $table->text('resolution_summary')->nullable()->after('charge_waived_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triaged_by');
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
            $table->dropColumn([
                'equipment_source',
                'external_equipment_name',
                'external_equipment_model',
                'external_serial_number',
                'warranty_status',
                'warranty_expiry_date',
                'service_path',
                'triaged_at',
                'charge_waived_reason',
                'resolution_summary',
            ]);
        });
    }
};
