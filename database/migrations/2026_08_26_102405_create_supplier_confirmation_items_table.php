<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_confirmation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_confirmation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 15, 3);
            $table->string('confirmation_status', 30)->default('pending')->index();
            $table->date('promised_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supplier_confirmation_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_confirmation_items');
    }
};
