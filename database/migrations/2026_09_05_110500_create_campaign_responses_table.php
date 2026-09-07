<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->foreignId('created_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_recipient_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_responses');
    }
};
