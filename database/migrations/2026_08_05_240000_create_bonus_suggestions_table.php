<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->foreignId('sales_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_opportunity_draft_id')->nullable()->constrained('sales_opportunity_drafts')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->string('status', 20)->default('Pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->index(['sales_plan_id', 'employee_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_suggestions');
    }
};
