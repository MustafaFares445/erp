<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CustomerVisit;
use App\Models\User;
use App\Services\Employees\VisitReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('audits a review note creation, with no prior text', function (): void {
    $reviewer = User::factory()->admin()->create();
    $this->actingAs($reviewer);
    $visit = CustomerVisit::factory()->create(['review_note' => null]);

    app(VisitReviewService::class)->updateReviewNote($visit, 'First note');

    $entry = AuditLog::query()->where('action', 'visit.reviewed')->where('entity_id', $visit->id)->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values['review_note'])->toBe('First note')
        ->and($entry->old_values['review_note'] ?? null)->toBeNull();
});

it('audits a review note update with both the previous and the new text', function (): void {
    $reviewer = User::factory()->admin()->create();
    $this->actingAs($reviewer);
    $visit = CustomerVisit::factory()->create(['review_note' => 'First note']);

    app(VisitReviewService::class)->updateReviewNote($visit, 'Revised note');

    $entry = AuditLog::query()->where('action', 'visit.reviewed')->where('entity_id', $visit->id)->latest('id')->first();

    expect($entry->old_values['review_note'])->toBe('First note')
        ->and($entry->new_values['review_note'])->toBe('Revised note');
});
