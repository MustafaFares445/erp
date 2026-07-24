<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_import_run_id', 'row_number'], 'inventory_import_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_import_items');
    }
};
