<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;
use App\Enums\TranscriptionConfidenceSource;
use App\Enums\UserType;
use App\Filament\Resources\EmployeeReports\Pages\ManageEmployeeReports;
use App\Filament\Resources\MonthlyPlans\RelationManagers\TasksRelationManager;
use App\Filament\Resources\MonthlyPlans\RelationManagers\VisitsRelationManager;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\FakeVoiceNoteTranscriber;
use App\Services\Employees\OpportunityReviewService;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses deterministic, locally isolated voice transcription in both locales', function (): void {
    foreach (['en', 'ar'] as $locale) {
        $result = (new FakeVoiceNoteTranscriber)->transcribe(
            new TranscriptionRequest('local', 'agent5/demo.ogg', $locale),
        );
        expect($result->provider)->toBe('fake')
            ->and($result->detectedLanguage)->toBe($locale)
            ->and($result->confidence)->toBe(87.5)
            ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::ProviderReported);
    }
});

it('does not allow an Employee account to enter the Filament admin panel', function (): void {
    $user = User::factory()->create(['user_type' => UserType::Employee]);
    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
});

it('requires a human review with a reason before counting opportunity outcomes', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $draft = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);
    $review = app(OpportunityReviewService::class);

    expect(fn () => $review->reject($draft, ''))->toThrow(DomainException::class);
    expect($draft->fresh()->status)->toBe(SalesOpportunityStatus::Draft);

    $approved = $review->approve($draft, 'Reviewed by authorized QA administrator');
    expect($approved->status)->toBe(SalesOpportunityStatus::Approved)
        ->and((int) $approved->reviewed_by)->toBe((int) $actor->getKey())
        ->and($approved->reviewed_at)->not->toBeNull();
});

it('localizes Sales Plan relation tabs and records that the employee mobile API is not deployed', function (): void {
    app()->setLocale('ar');
    expect(TasksRelationManager::getTitle(new SalesPlan, ''))->toBe(__('Tasks'))->not->toBe('Tasks');
    expect(VisitsRelationManager::getTitle(new SalesPlan, ''))->toBe(__('Visits'))->not->toBe('Visits');
    expect(__('employees.visits_calendar'))->toBe('تقويم الزيارات');
    $employeeReportPage = new ManageEmployeeReports;
    expect($employeeReportPage->getTitle())->toBe(__('admin.resources.employee_reports'))
        ->not->toBe(__('admin.resources.monthly_plans'));
    app()->setLocale('en');
    expect($employeeReportPage->getTitle())->toBe(__('admin.resources.employee_reports'));
    foreach (app('router')->getRoutes() as $route) {
        expect(str_starts_with($route->uri(), 'api/employee'))->toBeFalse();
    }
});
