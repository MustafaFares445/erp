<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('serial_number', 255)->nullable();
            // restrictOnDelete (not nullOnDelete): the equipment link must survive the unit's
            // own stock lifecycle (FR-068) — a hard delete of a referenced unit must fail
            // outright rather than silently orphaning this maintenance request's link.
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained('serialized_inventory_units')->restrictOnDelete();
            // True only when a serial number was entered but matched no known serialized unit
            // (FR-063) — distinct from simply having no serial number at all.
            $table->boolean('is_equipment_unlinked')->default(false);
            $table->string('warranty_status', 20)->default('unknown');
            $table->date('warranty_expiry_date')->nullable();
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
