<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->string('rfq_number')->unique();
            $table->string('status', 32)->default('draft')->index();
            $table->string('currency_code', 3)->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('needed_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closes_at')->nullable()->index();
            $table->foreignId('awarded_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('awarded_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_rfq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_id')->constrained('purchase_rfqs')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 15, 6);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_id', 'product_variant_id', 'unit_id'], 'purchase_rfq_line_unique');
        });

        Schema::create('purchase_rfq_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_id')->constrained('purchase_rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_id', 'supplier_id'], 'purchase_rfq_supplier_unique');
        });

        Schema::create('purchase_rfq_response_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_supplier_id')->constrained('purchase_rfq_suppliers')->cascadeOnDelete();
            $table->foreignId('purchase_rfq_line_id')->constrained('purchase_rfq_lines')->cascadeOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('offered_quantity', 15, 6);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('minimum_order_quantity', 15, 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_supplier_id', 'purchase_rfq_line_id'], 'purchase_rfq_response_unique');
        });

        Schema::create('purchase_agreements', function (Blueprint $table): void {
            $table->id();
            $table->string('agreement_number')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->string('currency_code', 3)->index();
            $table->date('starts_on')->index();
            $table->date('ends_on')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_agreement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_agreement_id')->constrained('purchase_agreements')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('minimum_order_quantity', 15, 6)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->timestamps();
            $table->unique(['purchase_agreement_id', 'product_variant_id', 'unit_id'], 'purchase_agreement_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_agreement_lines');
        Schema::dropIfExists('purchase_agreements');
        Schema::dropIfExists('purchase_rfq_response_lines');
        Schema::dropIfExists('purchase_rfq_suppliers');
        Schema::dropIfExists('purchase_rfq_lines');
        Schema::dropIfExists('purchase_rfqs');
    }
};
