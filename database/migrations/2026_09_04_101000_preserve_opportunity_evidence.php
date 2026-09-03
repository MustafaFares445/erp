<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign('sales_opportunities_voice_note_transcription_id_foreign');
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

        $danglingQuotationIds = DB::table('quotations')
            ->leftJoin(
                'sales_opportunities',
                'quotations.sales_opportunity_id',
                '=',
                'sales_opportunities.id',
            )
            ->whereNotNull('quotations.sales_opportunity_id')
            ->whereNull('sales_opportunities.id')
            ->orderBy('quotations.id')
            ->pluck('quotations.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($danglingQuotationIds !== []) {
            Log::warning(
                'Opportunity evidence migration found quotations whose source opportunity was already deleted.',
                [
                    'count' => count($danglingQuotationIds),
                    'quotation_ids' => $danglingQuotationIds,
                ],
            );
        }
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
