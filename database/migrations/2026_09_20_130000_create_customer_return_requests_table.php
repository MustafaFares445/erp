<?php

declare(strict_types=1);

use App\Enums\CustomerReturnRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('original_inventory_operation_id')->constrained('inventory_operations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default(CustomerReturnRequestStatus::Submitted->value);
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('resulting_inventory_return_id')->nullable()->constrained('inventory_returns')->nullOnDelete();
            $table->string('source_channel', 30)->default('dashboard');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_return_requests');
    }
};
