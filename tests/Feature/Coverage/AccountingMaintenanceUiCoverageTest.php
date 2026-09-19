<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Filament\Resources\MaintenanceSchedules\Pages\EditMaintenanceSchedule;
use App\Filament\Widgets\PeriodCloseReadiness;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

it('renders period-close readiness with and without an open period', function (): void {
    $widget = app(PeriodCloseReadiness::class);
    $method = new ReflectionMethod(PeriodCloseReadiness::class, 'getStats');

    expect($method->invoke($widget))->toHaveCount(1);

    FiscalPeriod::factory()->create(['name' => 'Coverage Period', 'is_closed' => false]);
    expect($method->invoke($widget))->toHaveCount(3);

    expect(PeriodCloseReadiness::canView())->toBeFalse();
    $this->actingAs(User::factory()->admin()->create());
    expect(PeriodCloseReadiness::canView())->toBeTrue();
});

it('executes maintenance-schedule edit guards and update path', function (): void {
    $page = new ReflectionClass(EditMaintenanceSchedule::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(EditMaintenanceSchedule::class, 'handleRecordUpdate');
    $schedule = MaintenanceSchedule::factory()->create();
    $data = [
        'name' => 'Updated coverage schedule',
        'interval_type' => MaintenanceIntervalType::Months->value,
        'interval_value' => 2,
        'lead_time_days' => 5,
        'billing_type' => MaintenanceBillingType::Unbilled->value,
    ];

    expect(fn (): mixed => $method->invoke($page, $schedule, $data))->toThrow(HttpException::class);

    $this->actingAs(User::factory()->admin()->create());
    expect(fn (): mixed => $method->invoke($page, new CustomerProfile, $data))
        ->toThrow(NotFoundHttpException::class);

    $updated = $method->invoke($page, $schedule, $data);
    expect($updated)->toBeInstanceOf(MaintenanceSchedule::class)
        ->and($updated->refresh()->name)->toBe('Updated coverage schedule')
        ->and($page->getHeaderActions())->toHaveCount(1);
});
