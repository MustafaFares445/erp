<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('schedule_number', 30)->unique();
            $table->foreignId('serialized_inventory_unit_id')->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->string('name', 255);
            $table->string('interval_type', 20);
            $table->unsignedInteger('interval_value');
            $table->unsignedInteger('lead_time_days')->default(7);
            $table->date('first_due_on');
            $table->date('next_due_on');
            $table->date('last_completed_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('billing_type', 30);
            $table->json('checklist')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'next_due_on']);
            $table->index('serialized_inventory_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedules');
    }
};
