<?php

declare(strict_types=1);

use App\Enums\PaymentTransactionStatus;
use App\Services\Payments\ProviderPaymentSettlementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-side payment lifecycle evidence. Independent of ERP `payments` —
 * {@see ProviderPaymentSettlementService} is the only
 * writer that ever turns a Succeeded row here into a posted ERP Payment, and
 * it does so at most once per row (enforced by the `payment_id` link plus
 * the unique `idempotency_key`/`checkout_session_id`/`payment_intent_id`
 * columns, which also protect against duplicate provider references).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('purpose_type');
            $table->unsignedBigInteger('purpose_id');
            $table->string('provider_customer_reference')->nullable();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable()->unique();
            $table->string('provider_charge_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 20)->default(PaymentTransactionStatus::Pending->value);
            $table->string('idempotency_key')->unique();
            $table->string('last_provider_event_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['purpose_type', 'purpose_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
