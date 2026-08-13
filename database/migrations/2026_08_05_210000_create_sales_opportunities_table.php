<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_note_transcription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_keyword_rule_id')->nullable()->constrained('ai_keyword_rules')->nullOnDelete();
            $table->text('summary');
            $table->string('status', 20)->default('Draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_opportunities');
    }
};
