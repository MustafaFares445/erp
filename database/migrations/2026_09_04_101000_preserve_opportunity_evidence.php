<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['voice_note_transcription_id']);
        });

        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->foreignId('voice_note_transcription_id')->nullable()->change();
            $table->text('origin_summary')->nullable();

            $table->foreign('voice_note_transcription_id')
                ->references('id')
                ->on('voice_note_transcriptions')
                ->nullOnDelete();
        });

        DB::table('sales_opportunities')
            ->select(['id', 'voice_note_transcription_id'])
            ->whereNull('origin_summary')
            ->whereNotNull('voice_note_transcription_id')
            ->orderBy('id')
            ->chunkById(100, function ($opportunities): void {
                foreach ($opportunities as $opportunity) {
                    $transcript = DB::table('voice_note_transcriptions')
                        ->where('id', $opportunity->voice_note_transcription_id)
                        ->value('transcript');

                    if (! is_string($transcript) || mb_trim($transcript) === '') {
                        continue;
                    }

                    DB::table('sales_opportunities')
                        ->where('id', $opportunity->id)
                        ->update(['origin_summary' => $transcript]);
                }
            });
    }

    public function down(): void
    {
        // Deliberately keep the FK nullable with nullOnDelete semantics.
        // Re-introducing cascadeOnDelete would restore the data-loss defect
        // that this migration exists to remove.
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropColumn('origin_summary');
        });
    }
};
