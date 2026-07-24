<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
    }
};
