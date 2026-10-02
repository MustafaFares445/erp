<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('employee.user.name')->label(__('Assigned to')),
                TextColumn::make('assignedBy.name')->label(__('Assigned by')),
                TextColumn::make('assigned_at')->dateTime()->sortable(),
            ])
            ->defaultSort('assigned_at', 'desc')
            ->headerActions([
                Action::make('assign')
                    ->label(__('Assign'))
                    ->schema([
                        Select::make('employee_id')
                            ->label(__('Employee'))
                            ->options(fn (): array => EmployeeProfile::query()->with('user')->get()
                                ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->authorize(fn (): bool => $this->currentActor()->can('assign', $this->ticket()))
                    ->action(function (array $data): void {
                        $employeeId = $data['employee_id'] ?? null;

                        if (is_int($employeeId) || is_string($employeeId)) {
                            $this->assign($employeeId);
                        }
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function assign(int|string $employeeId): void
    {
        $employee = EmployeeProfile::query()->findOrFail($employeeId);

        try {
            app(TicketLifecycleService::class)->assign($this->ticket(), $employee, $this->currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to assign this ticket'))->body($domainException->getMessage())->send();
        }
    }

    private function ticket(): Ticket
    {
        /** @var Ticket $record */
        $record = $this->getOwnerRecord();

        return $record;
    }

    private function currentActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
