<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Services\Employees\PlanTaskService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

final class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('salesPlan.name')->label('Plan')->searchable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('due_at')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(self::overdueQuery(...)),
                Filter::make('due_soon')
                    ->label('Due soon')
                    ->query(self::dueSoonQuery(...)),
                Filter::make('completed')
                    ->label('Completed')
                    ->query(static fn (Builder $query): Builder => $query->where('status', PlanTaskStatus::Completed->value)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::transitionAction('startProgress', 'Start progress', PlanTaskStatus::InProgress)
                    ->visible(static fn (PlanTask $record): bool => $record->status === PlanTaskStatus::Pending),
                self::transitionAction('complete', 'Complete', PlanTaskStatus::Completed)
                    ->visible(static fn (PlanTask $record): bool => in_array($record->status, [PlanTaskStatus::Pending, PlanTaskStatus::InProgress], true)),
                self::transitionAction('cancel', 'Cancel', PlanTaskStatus::Cancelled)
                    ->visible(static fn (PlanTask $record): bool => in_array($record->status, [PlanTaskStatus::Pending, PlanTaskStatus::InProgress], true)),
                self::transitionAction('reopen', 'Reopen', PlanTaskStatus::InProgress)
                    ->modalHeading('Reopen this task?')
                    ->modalDescription(new HtmlString("Reopening clears the completion date and marks the plan's performance score stale."))
                    ->visible(static fn (PlanTask $record): bool => $record->status === PlanTaskStatus::Completed),
            ]);
    }

    private static function transitionAction(string $name, string $label, PlanTaskStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize('update')
            ->schema([
                Textarea::make('note')->label('Note')->required(),
            ])
            ->action(static function (PlanTask $record, array $data) use ($to): void {
                $note = $data['note'] ?? null;

                self::transition($record, $to, is_string($note) ? $note : null);
            });
    }

    private static function transition(PlanTask $record, PlanTaskStatus $to, ?string $note): void
    {
        try {
            app(PlanTaskService::class)->transition($record, $to, $note);
        } catch (DomainException $exception) {
            Notification::make()->danger()->title('Unable to change the task status')->body($exception->getMessage())->send();
        }
    }

    /**
     * @param  Builder<PlanTask>  $query
     * @return Builder<PlanTask>
     */
    private static function overdueQuery(Builder $query): Builder
    {
        return $query->overdue();
    }

    /**
     * @param  Builder<PlanTask>  $query
     * @return Builder<PlanTask>
     */
    private static function dueSoonQuery(Builder $query): Builder
    {
        return $query->dueSoon();
    }
}
