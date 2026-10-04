<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->foreignId('performed_by_employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->foreignId('external_provider_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('follow_up_maintenance_record_id')->nullable()->constrained('maintenance_records')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('calibrated_at')->nullable();
            $table->string('result', 30)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->date('certificate_expires_on')->nullable();
            $table->timestamp('certificate_issued_at')->nullable();
            $table->date('next_calibration_due_on')->nullable();
            $table->string('standard_reference')->nullable();
            $table->string('instrument_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('maintenance_record_id');
            $table->index(['serialized_inventory_unit_id', 'calibrated_at'], 'equipment_calibrations_unit_calibrated_index');
            $table->index(['next_calibration_due_on', 'result'], 'equipment_calibrations_due_result_index');
        });

        Schema::create('equipment_calibration_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_calibration_id')->constrained('equipment_calibrations', indexName: 'equipment_calibration_measurements_calibration_fk')->cascadeOnDelete();
            $table->string('measurement_key', 100);
            $table->string('label');
            $table->decimal('expected_value', 14, 4)->nullable();
            $table->decimal('minimum_value', 14, 4)->nullable();
            $table->decimal('maximum_value', 14, 4)->nullable();
            $table->decimal('actual_value', 14, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('result', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['equipment_calibration_id', 'measurement_key'], 'equipment_calibration_measurements_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_calibration_measurements');
        Schema::dropIfExists('equipment_calibrations');
    }
};
