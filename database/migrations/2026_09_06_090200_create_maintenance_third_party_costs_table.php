<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-09) — an outside cost incurred on a job (a subcontracted
 * repair, a courier fee) that is not a spare part and not labour. `bill_id`
 * links the cost to the payable that actually paid it when one exists,
 * without requiring one — a cost can be recorded before, or without ever
 * having, a matching bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_third_party_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->date('incurred_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_third_party_costs');
    }
};
