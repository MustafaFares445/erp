<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('installed_by_employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->string('commissioning_status', 20)->default('pending');
            $table->timestamp('commissioned_at')->nullable();
            $table->foreignId('commissioned_by_employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->string('customer_acceptance_status', 20)->default('pending');
            $table->string('customer_signatory_name')->nullable();
            $table->timestamp('customer_accepted_at')->nullable();
            $table->string('installation_location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('maintenance_record_id');
            $table->index('shipment_id');
            $table->index(['serialized_inventory_unit_id', 'commissioning_status'], 'equipment_installations_unit_commissioning_index');
            $table->index('commissioning_status');
        });

        Schema::create('equipment_installation_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_installation_id')->constrained('equipment_installations')->cascadeOnDelete();
            $table->string('check_key', 100);
            $table->string('label');
            $table->string('result', 20)->default('pending');
            $table->decimal('measured_value', 14, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['equipment_installation_id', 'check_key'], 'equipment_installation_checks_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_installation_checks');
        Schema::dropIfExists('equipment_installations');
    }
};
