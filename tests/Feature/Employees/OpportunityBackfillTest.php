<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerProfile;
use App\Models\VoiceNoteTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runOpportunityEvidenceMigrationUp(): void
{
    $migration = require database_path(
        'migrations/2026_09_04_101000_preserve_opportunity_evidence.php',
    );

    if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
        throw new \LogicException('Opportunity-evidence migration must expose up().');
    }

    call_user_func([$migration, 'up']);
}

function runOpportunityEvidenceMigrationDown(): void
{
    $migration = require database_path(
        'migrations/2026_09_04_101000_preserve_opportunity_evidence.php',
    );

    if (! is_object($migration) || ! is_callable([$migration, 'down'])) {
        throw new LogicException('Opportunity-evidence migration must expose down().');
    }

    call_user_func([$migration, 'down']);
}

function insertLegacyOpportunity(?int $transcriptionId, string $summary): int
{
    return (int) DB::table('sales_opportunities')->insertGetId([
        'voice_note_transcription_id' => $transcriptionId,
        'ai_keyword_rule_id' => null,
        'summary' => $summary,
        'status' => SalesOpportunityStatus::Draft->value,
        'reviewed_by' => null,
        'reviewed_at' => null,
        'review_notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('backfills origin evidence where the source transcript still exists and leaves unknown legacy origin null', function (): void {
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'Customer asked for a chairside milling upgrade.',
    ]);

    runOpportunityEvidenceMigrationDown();

    try {
        $withTranscript = insertLegacyOpportunity(
            (int) $transcription->getKey(),
            'AI detected a milling opportunity.',
        );
        $withoutTranscript = insertLegacyOpportunity(
            null,
            'Legacy opportunity whose origin was already lost.',
        );

        runOpportunityEvidenceMigrationUp();

        expect(DB::table('sales_opportunities')->where('id', $withTranscript)->value('origin_summary'))
            ->toBe('Customer asked for a chairside milling upgrade.')
            ->and(DB::table('sales_opportunities')->where('id', $withoutTranscript)->value('origin_summary'))
            ->toBeNull()
            ->and(Schema::hasColumn('sales_opportunities', 'origin_summary'))->toBeTrue();
    } finally {
        if (! Schema::hasColumn('sales_opportunities', 'origin_summary')) {
            runOpportunityEvidenceMigrationUp();
        }
    }
});

it('reports quotations whose opportunity evidence was already lost before the migration', function (): void {
    $customer = CustomerProfile::factory()->create();

    runOpportunityEvidenceMigrationDown();

    try {
        Schema::disableForeignKeyConstraints();

        DB::table('quotations')->insert([
            'quotation_number' => 'QT-DANGLING-EVIDENCE-0001',
            'customer_id' => $customer->getKey(),
            'sales_opportunity_id' => 999999999,
            'issue_date' => '2026-09-03',
            'expires_at' => null,
            'notes' => 'Historical dangling opportunity evidence.',
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'grand_total' => '0.00',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        $quotationId = (int) DB::table('quotations')
            ->where('quotation_number', 'QT-DANGLING-EVIDENCE-0001')
            ->value('id');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($quotationId): bool {
                return str_contains($message, 'source opportunity was already deleted')
                    && ($context['count'] ?? null) === 1
                    && ($context['quotation_ids'] ?? null) === [$quotationId];
            });

        runOpportunityEvidenceMigrationUp();
    } finally {
        Schema::disableForeignKeyConstraints();
        DB::table('quotations')
            ->where('quotation_number', 'QT-DANGLING-EVIDENCE-0001')
            ->delete();
        Schema::enableForeignKeyConstraints();

        if (! Schema::hasColumn('sales_opportunities', 'origin_summary')) {
            runOpportunityEvidenceMigrationUp();
        }
    }
});
