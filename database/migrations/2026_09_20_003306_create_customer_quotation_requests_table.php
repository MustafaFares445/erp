<?php

declare(strict_types=1);

use App\Enums\CustomerQuotationRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_quotation_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('customer_delivery_address_id')->nullable()->constrained('customer_delivery_addresses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default(CustomerQuotationRequestStatus::Submitted->value);
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('resulting_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->string('source_channel', 30)->default('dashboard');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_quotation_requests');
    }
};
