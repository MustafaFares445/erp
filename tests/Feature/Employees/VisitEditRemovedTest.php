<?php

declare(strict_types=1);

use App\Enums\EmployeePermission;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\AuditLog;
use App\Models\CustomerVisit;
use App\Models\User;
use App\Services\Employees\VisitReviewService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('has no visit edit route for any role, including System Admin', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $visit = CustomerVisit::factory()->create();

    expect(fn (): string => VisitResource::getUrl('edit', ['record' => $visit]))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the review-note action available to a reviewer', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');
    $this->actingAs($reviewer);
    $visit = CustomerVisit::factory()->create();

    app(VisitReviewService::class)->updateReviewNote($visit, 'Looks good, verified in person.');

    expect($visit->fresh()->review_note)->toBe('Looks good, verified in person.')
        ->and($visit->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and(
            AuditLog::query()->where('description', 'visit.reviewed')->where('subject_id', $visit->id)->exists()
        )->toBeTrue();
});

it('updates a visit review note through the table action', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->givePermissionTo(EmployeePermission::VisitReview->value);

    $visit = CustomerVisit::factory()->create();

    Livewire::actingAs($reviewer)
        ->test(ListVisits::class)
        ->callTableAction('review', $visit, data: ['review_note' => 'Reviewed in the field.'])
        ->assertHasNoTableActionErrors();

    expect($visit->fresh()->review_note)->toBe('Reviewed in the field.');
});
