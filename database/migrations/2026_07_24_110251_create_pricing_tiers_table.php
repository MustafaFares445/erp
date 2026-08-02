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
            $table->string('name', 150)->unique();
            $table->string('tier_type', 30)->default('general');
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('visibility', 20)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_user_id', 'is_active']);
            $table->index(['tier_type', 'is_active', 'deleted_at']);
            $table->index(['is_active', 'valid_from', 'valid_until', 'deleted_at']);
            $table->index(['visibility', 'is_active', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
    }
};
