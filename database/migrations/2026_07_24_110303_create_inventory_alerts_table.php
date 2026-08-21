<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('subject_type', 150);
            $table->unsignedBigInteger('subject_id');
            $table->string('message');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['type', 'subject_type', 'subject_id'], 'inventory_alert_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
