<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_payment_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('external_payment_reference')->nullable();
            $table->string('payment_url', 1000)->nullable();
            $table->string('payment_method_reference')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_payment_links');
    }
};
