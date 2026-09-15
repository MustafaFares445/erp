<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Enums\MaintenanceStatus;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\ServiceRecordService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class ServiceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceRecords';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('employee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('started_at')->label('Started')->dateTime()->placeholder('—'),
                TextColumn::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                Action::make('addServiceRecord')
                    ->label('Add Service Record')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        Select::make('employee_id')
                            ->label('Assignee')
                            ->options(fn (): array => EmployeeProfile::query()->with('user')->get()
                                ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                                ->all())
                            ->searchable(),
                        DateTimePicker::make('due_at'),
                        Textarea::make('description')->columnSpanFull(),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', MaintenanceTask::class))
                    ->visible(fn (): bool => in_array($this->maintenanceRecord()->status, [MaintenanceStatus::Open, MaintenanceStatus::InProgress], true))
                    ->action(function (array $data): void {
                        try {
                            app(ServiceRecordService::class)->create($this->maintenanceRecord(), [
                                'title' => $data['title'] ?? null,
                                'employee_id' => $data['employee_id'] ?? null,
                                'due_at' => $data['due_at'] ?? null,
                                'description' => $data['description'] ?? null,
                            ], self::currentActor());
                        } catch (DomainException $domainException) {
                            Notification::make()->danger()->title('Unable to add this service record')->body($domainException->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('viewEdit')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(static fn (MaintenanceTask $record): string => ServiceRecordResource::getUrl('view', ['record' => $record])),
                self::transitionAction('startProgress', 'Start work', MaintenanceStatus::InProgress)
                    ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::Open),
                self::completeAction()
                    ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::InProgress),
                self::transitionAction('cancel', 'Cancel', MaintenanceStatus::Cancelled)
                    ->color('danger')
                    ->visible(static fn (MaintenanceTask $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
            ])
            ->toolbarActions([]);
    }

    private static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize('execute')
            ->schema([
                Textarea::make('work_performed')
                    ->label('Work performed')
                    ->required()
                    ->rows(4),
                Textarea::make('completion_notes')
                    ->label('Completion notes')
                    ->rows(3),
            ])
            ->action(static function (MaintenanceTask $record, array $data): void {
                $workPerformed = $data['work_performed'] ?? null;
                $completionNotes = $data['completion_notes'] ?? null;

                if (! is_string($workPerformed) || mb_trim($workPerformed) === '') {
                    return;
                }

                self::applyTransition(
                    $record,
                    MaintenanceStatus::Closed,
                    is_string($completionNotes) ? $completionNotes : null,
                    $workPerformed,
                );
            });
    }

    private static function transitionAction(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize('execute')
            ->action(static fn (MaintenanceTask $record) => self::applyTransition($record, $to));
    }

    private static function applyTransition(
        MaintenanceTask $record,
        MaintenanceStatus $to,
        ?string $note = null,
        ?string $workPerformed = null,
    ): void {
        try {
            app(ServiceRecordService::class)->transition($record, $to, self::currentActor(), $note, $workPerformed);
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to change the service record status')->body($domainException->getMessage())->send();
        }
    }

    private function maintenanceRecord(): MaintenanceRecord
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof MaintenanceRecord) {
            throw new LogicException('Expected the owner record of ServiceRecordsRelationManager to be a MaintenanceRecord.');
        }

        return $record;
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
