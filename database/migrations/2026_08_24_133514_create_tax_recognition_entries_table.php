<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_recognition_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('tax_date')->index();
            $table->string('direction', 30)->index();
            $table->string('tax_type', 50);
            $table->decimal('tax_amount', 15, 2);
            $table->morphs('source');
            $table->timestamps();
            $table->index(['direction', 'tax_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_recognition_entries');
    }
};
