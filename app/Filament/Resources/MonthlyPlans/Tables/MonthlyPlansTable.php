<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Tables;

use App\Enums\SalesPlanStatus;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanDuplicationService;
use App\Services\Employees\SalesPlanService;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class MonthlyPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.job_title')->label('Employee')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('month')->date('F Y')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('tasks_count')->label('Tasks')->counts('tasks'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    static fn (): array => array_combine(
                        array_map(static fn (SalesPlanStatus $status): string => $status->value, SalesPlanStatus::cases()),
                        array_map(static fn (SalesPlanStatus $status): string => $status->value, SalesPlanStatus::cases()),
                    ),
                ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::transitionAction('activate', 'Activate', SalesPlanStatus::Active, Heroicon::OutlinedPlay),
                self::transitionAction('pause', 'Pause', SalesPlanStatus::Paused, Heroicon::OutlinedPause),
                self::transitionAction('complete', 'Complete', SalesPlanStatus::Completed, Heroicon::OutlinedCheckCircle),
                self::transitionAction('archive', 'Archive', SalesPlanStatus::Archived, Heroicon::OutlinedArchiveBox),
                self::copyToMonthAction(),
                self::assignToEmployeeAction(),
                DeleteAction::make()
                    ->action(static function (SalesPlan $record): void {
                        try {
                            app(SalesPlanService::class)->delete($record);
                        } catch (DomainException $domainException) {
                            Notification::make()->danger()->title('Unable to delete the plan')->body($domainException->getMessage())->send();
                        }
                    }),
                RestoreAction::make()
                    ->action(static fn (SalesPlan $record) => app(SalesPlanService::class)->restore($record)),
            ]);
    }

    private static function transitionAction(string $name, string $label, SalesPlanStatus $to, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(static fn (SalesPlan $record): bool => $record->status->canTransitionTo($to))
            ->action(static function (SalesPlan $record) use ($to): void {
                try {
                    app(SalesPlanService::class)->transition($record, $to);
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to change the plan status')->body($domainException->getMessage())->send();
                }
            });
    }

    private static function copyToMonthAction(): Action
    {
        return Action::make('copyToMonth')
            ->label('Copy to month')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->authorize('create')
            ->schema([
                DatePicker::make('target_month')->label('Target month')->displayFormat('F Y')->required(),
            ])
            ->action(self::copyToMonth(...));
    }

    /**
     * @param  array{target_month: string}  $data
     */
    private static function copyToMonth(SalesPlan $record, array $data): void
    {
        try {
            app(SalesPlanDuplicationService::class)->duplicate(
                $record,
                $record->employee_id,
                CarbonImmutable::parse($data['target_month']),
            );
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to copy the plan')->body($domainException->getMessage())->send();
        }
    }

    private static function assignToEmployeeAction(): Action
    {
        return Action::make('assignToEmployee')
            ->label('Assign to another employee')
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize('create')
            ->schema([
                Select::make('target_employee_id')
                    ->label('Target employee')
                    ->options(static fn (): array => EmployeeProfile::query()->pluck('job_title', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(self::assignToEmployee(...));
    }

    /**
     * @param  array{target_employee_id: int|string}  $data
     */
    private static function assignToEmployee(SalesPlan $record, array $data): void
    {
        try {
            app(SalesPlanDuplicationService::class)->duplicate(
                $record,
                (int) $data['target_employee_id'],
                CarbonImmutable::parse($record->month),
            );
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to assign the plan')->body($domainException->getMessage())->send();
        }
    }
}
