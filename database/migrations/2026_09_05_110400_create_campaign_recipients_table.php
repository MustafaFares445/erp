<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('send_status', 20)->default('pending');
            $table->string('send_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('notification_delivery_id')->nullable()->constrained('notification_deliveries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'recipient_type', 'recipient_id'], 'campaign_recipients_campaign_recipient_unique');
            $table->index(['campaign_id', 'send_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
