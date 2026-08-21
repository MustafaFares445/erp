<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_gps_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_visit_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');

            $table->index(['customer_visit_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_gps_logs');
    }
};
