<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 20);
            $table->string('address', 255);
            $table->string('reason', 40);
            $table->timestamp('suppressed_at');
            $table->unsignedBigInteger('source_campaign_id')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'address'], 'communication_suppressions_channel_address_unique');
            $table->index('source_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_suppressions');
    }
};
