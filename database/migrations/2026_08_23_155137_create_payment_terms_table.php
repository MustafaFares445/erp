<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per ERD, unchanged except for the blameable columns every document table in
 * this feature carries (data-model.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('due_days')->default(0);
            $table->integer('grace_days')->default(0);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
