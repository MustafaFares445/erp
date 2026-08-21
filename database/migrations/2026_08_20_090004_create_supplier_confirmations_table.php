<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier confirmations (data-model.md §4, ERD extensions E-3, E-4, E-5).
 *
 * Polymorphic so one record type serves both a purchase order and a customer
 * order, restricted to those two at the service layer. No soft delete: the
 * supplier's answer is evidence, and evidence is append-only (R-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->morphs('confirmable');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // The ERD's generic `status` column is dropped (E-4): carrying both
            // it and `confirmation_status` would let one record's state be
            // written in two places that can disagree.
            $table->string('confirmation_status', 30)->default('pending')->index();
            $table->date('promised_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_confirmations');
    }
};
