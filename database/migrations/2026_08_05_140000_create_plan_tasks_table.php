<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('starts_at');
            $table->date('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('Pending');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->index(['sales_plan_id', 'status']);
            $table->index('due_at');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_tasks');
    }
};
