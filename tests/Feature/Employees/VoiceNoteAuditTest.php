<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('audits voice-note deletion', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $voiceNote = EmployeeVoiceNote::factory()->create();

    $voiceNote->delete();

    expect(
        AuditLog::query()->where('description', 'voice_note.deleted')->where('subject_id', $voiceNote->id)->exists()
    )->toBeTrue();
});

it('audits an opportunity draft approval', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $draft = SalesOpportunity::factory()->create();

    app(OpportunityReviewService::class)->approve($draft);

    expect(
        AuditLog::query()->where('description', 'opportunity.approved')->where('subject_id', $draft->id)->exists()
    )->toBeTrue();
});

it('audits an opportunity draft rejection', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $draft = SalesOpportunity::factory()->create();

    app(OpportunityReviewService::class)->reject($draft);

    expect(
        AuditLog::query()->where('description', 'opportunity.rejected')->where('subject_id', $draft->id)->exists()
    )->toBeTrue();
});
