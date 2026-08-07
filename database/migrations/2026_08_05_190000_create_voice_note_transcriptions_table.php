<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_note_transcriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_voice_note_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('transcript')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('confidence_source', 30);
            $table->string('detected_language', 20)->nullable();
            $table->string('provider', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->string('status', 20)->default('Pending');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_note_transcriptions');
    }
};
