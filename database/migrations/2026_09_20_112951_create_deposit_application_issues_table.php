<?php

declare(strict_types=1);

use App\Services\Payments\CustomerDepositApplicationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when {@see CustomerDepositApplicationService}
 * fails after an invoice was successfully issued. The invoice issuance
 * itself is never rolled back for a deposit-application failure — this
 * table is what makes that failure visible on the dashboard and retryable,
 * rather than silently swallowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_application_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->text('error_message');
            $table->timestamp('occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_application_issues');
    }
};
