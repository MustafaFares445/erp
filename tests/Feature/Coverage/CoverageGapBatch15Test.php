<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use App\Filament\Resources\MaintenanceSchedules\Tables\MaintenanceSchedulesTable;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\ServiceRecordService;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers service-record relation completion create failure and actor guard', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $transitionService = new class
    {
        public int $calls = 0;

        public function transition(
            MaintenanceTask $record,
            MaintenanceStatus $to,
            User $actor,
            ?string $note = null,
            ?string $workPerformed = null,
        ): MaintenanceTask {
            $this->calls++;
            $record->forceFill([
                'status' => $to,
                'completion_notes' => $note,
                'work_performed' => $workPerformed,
            ]);

            return $record;
        }
    };
    app()->instance(ServiceRecordService::class, $transitionService);

    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $complete = new ReflectionMethod(ServiceRecordsRelationManager::class, 'completeAction');
    $completeAction = $complete->invoke(null);
    $completeAction->getActionFunction()($task, [
        'work_performed' => 'Coverage completed work',
        'completion_notes' => 'Coverage completion note',
    ]);

    expect($transitionService->calls)->toBe(1)
        ->and($task->status)->toBe(MaintenanceStatus::Closed)
        ->and($task->work_performed)->toBe('Coverage completed work');

    $failingService = new class
    {
        public function create(MaintenanceRecord $record, array $data, User $actor): never
        {
            throw new DomainException('Coverage forced service-record create failure.');
        }
    };
    app()->instance(ServiceRecordService::class, $failingService);

    $manager = new ServiceRecordsRelationManager;
    $manager->ownerRecord = MaintenanceRecord::factory()->create();

    $table = $manager->table(Table::make($manager));
    $add = $table->getHeaderActions()[0]->getActionFunction();
    expect($add)->toBeInstanceOf(Closure::class);

    $add(['title' => 'Coverage failure']);

    auth()->logout();
    $currentActor = new ReflectionMethod(ServiceRecordsRelationManager::class, 'currentActor');
    expect(fn (): mixed => $currentActor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('covers maintenance schedule due states and raise-now success callback', function (): void {
    $dueState = new ReflectionMethod(MaintenanceSchedulesTable::class, 'dueState');

    $inactive = MaintenanceSchedule::factory()->create(['is_active' => false]);
    expect($dueState->invoke(null, $inactive))->toBe('Inactive');

    $overdue = MaintenanceSchedule::factory()->create([
        'is_active' => true,
        'next_due_on' => today()->subDay(),
    ]);
    expect($dueState->invoke(null, $overdue))->toBe('Overdue');

    $dueSoon = MaintenanceSchedule::factory()->create([
        'is_active' => true,
        'lead_time_days' => 5,
        'next_due_on' => today()->addDays(3),
    ]);
    expect($dueState->invoke(null, $dueSoon))->toBe('Due Soon');

    $upcoming = MaintenanceSchedule::factory()->create([
        'is_active' => true,
        'lead_time_days' => 2,
        'next_due_on' => today()->addDays(10),
    ]);
    expect($dueState->invoke(null, $upcoming))->toBe('Upcoming');

    $generator = new class
    {
        public int $calls = 0;

        public function raiseDue(): int
        {
            $this->calls++;

            return 0;
        }
    };
    app()->instance(MaintenanceScheduleGenerator::class, $generator);

    $action = MaintenanceScheduleActions::raiseNow()->getActionFunction();
    expect($action)->toBeInstanceOf(Closure::class);
    $action();

    expect($generator->calls)->toBe(1);
});
