<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\RelationManagers;

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\PlanTaskService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required()->maxLength(200),
                Textarea::make('description'),
                DatePicker::make('starts_at')->required(),
                DatePicker::make('due_at')->required(),
                Select::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'company_name')
                    ->searchable(),
            ]);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('starts_at')->date(),
                TextColumn::make('due_at')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using($this->createTask(...)),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(self::updateTask(...)),
                self::transitionAction('startProgress', 'Start progress', PlanTaskStatus::InProgress)
                    ->visible(static fn (PlanTask $record): bool => $record->status === PlanTaskStatus::Pending),
                self::transitionAction('complete', 'Complete', PlanTaskStatus::Completed)
                    ->visible(static fn (PlanTask $record): bool => in_array($record->status, [PlanTaskStatus::Pending, PlanTaskStatus::InProgress], true)),
                self::transitionAction('cancel', 'Cancel', PlanTaskStatus::Cancelled)
                    ->visible(static fn (PlanTask $record): bool => in_array($record->status, [PlanTaskStatus::Pending, PlanTaskStatus::InProgress], true)),
                self::transitionAction('reopen', 'Reopen', PlanTaskStatus::InProgress)
                    ->modalHeading('Reopen this task?')
                    ->modalDescription('Reopening clears the completion date and marks the plan\'s performance score stale.')
                    ->visible(static fn (PlanTask $record): bool => $record->status === PlanTaskStatus::Completed),
                DeleteAction::make(),
            ]);
    }

    private static function transitionAction(string $name, string $label, PlanTaskStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->schema([
                Textarea::make('note')->label('Note')->required(),
            ])
            ->action(static function (PlanTask $record, array $data) use ($to): void {
                $note = $data['note'] ?? null;

                self::applyTransition($record, $to, is_string($note) ? $note : null);
            });
    }

    private static function applyTransition(PlanTask $record, PlanTaskStatus $to, ?string $note): void
    {
        try {
            app(PlanTaskService::class)->transition($record, $to, $note);
        } catch (DomainException $exception) {
            Notification::make()->danger()->title('Unable to change the task status')->body($exception->getMessage())->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTask(array $data): PlanTask
    {
        return app(PlanTaskService::class)->create($this->plan(), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function updateTask(PlanTask $record, array $data): PlanTask
    {
        return app(PlanTaskService::class)->update($record, $data);
    }

    private function plan(): SalesPlan
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof SalesPlan) {
            throw new LogicException('Expected the owner record of TasksRelationManager to be a SalesPlan.');
        }

        return $record;
    }
}
