<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Enums\EquipmentLoanStatus;
use App\Enums\InventoryPermission;
use App\Enums\MaintenanceKind;
use App\Enums\StockCondition;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Support\EquipmentLoanService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Temporary replacement equipment for a customer's unit under repair. Support
 * records the loan here; issuing and returning move custody through Inventory,
 * so those actions also require the Inventory loan permission.
 */
final class LoanRelationManager extends RelationManager
{
    protected static string $relationship = 'equipmentLoans';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof MaintenanceRecord
            && $ownerRecord->maintenance_kind === MaintenanceKind::Corrective
            && (bool) config('support.loaner_equipment_enabled', false);
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Temporary Replacement Equipment');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['loanerUnit.productVariant', 'loanerUnit.warehouse'])->latest('id'))
            ->columns([
                TextColumn::make('loanerUnit.serial_number')->label(__('Loaner serial')),
                TextColumn::make('loanerUnit.productVariant.name')->label(__('Loaner product')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('issued_at')->label(__('Issued'))->dateTime()->placeholder(__('—')),
                TextColumn::make('expected_return_at')
                    ->label(__('Expected return'))
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->color(static fn (EquipmentLoan $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->description(static fn (EquipmentLoan $record): ?string => $record->isOverdue() ? self::t('Overdue') : null),
                TextColumn::make('returned_at')->label(__('Returned'))->dateTime()->placeholder(__('—')),
                TextColumn::make('condition_out')->label(__('Condition out'))->badge()->placeholder(__('—')),
                TextColumn::make('condition_in')->label(__('Condition in'))->badge()->placeholder(__('—')),
                TextColumn::make('notes')->label(__('Notes'))->placeholder(__('—'))->limit(60),
            ])
            ->headerActions([
                Action::make('reserveLoaner')
                    ->label(__('Reserve Loaner'))
                    ->schema([
                        Select::make('loaner_id')
                            ->label(__('Loaner'))
                            ->options(fn (): array => $this->loanerOptions())
                            ->helperText(fn (): ?string => $this->loanerOptions() === [] ? self::t('No available loaner of the same product is held in a warehouse.') : null)
                            ->required(),
                        DateTimePicker::make('expected_return_at')->label(__('Expected return')),
                        Textarea::make('notes')->label(__('Notes')),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', EquipmentLoan::class))
                    ->visible(fn (): bool => ! $this->maintenanceRecord()->isFinalised())
                    ->action(fn (array $data) => $this->run(function () use ($data): void {
                        $loaner = SerializedInventoryUnit::query()->findOrFail(is_numeric($data['loaner_id'] ?? null) ? (int) $data['loaner_id'] : 0);

                        app(EquipmentLoanService::class)->reserve(
                            $this->maintenanceRecord(),
                            $loaner,
                            self::currentActor(),
                            is_string($data['expected_return_at'] ?? null) && $data['expected_return_at'] !== '' ? Carbon::parse($data['expected_return_at']) : null,
                            is_string($data['notes'] ?? null) ? $data['notes'] : null,
                        );
                    })),
            ])
            ->recordActions([
                Action::make('issueLoaner')
                    ->label(__('Issue to Customer'))
                    ->schema(fn (EquipmentLoan $record): array => [
                        DateTimePicker::make('expected_return_at')
                            ->label(__('Expected return'))
                            ->default($record->expected_return_at)
                            ->required(),
                    ])
                    ->authorize(fn (EquipmentLoan $record): bool => self::currentActor()->can('update', $record) && self::canMoveStock())
                    ->visible(static fn (EquipmentLoan $record): bool => $record->status === EquipmentLoanStatus::Reserved)
                    ->action(fn (EquipmentLoan $record, array $data) => $this->run(
                        fn () => app(EquipmentLoanService::class)->issue(
                            $record,
                            self::currentActor(),
                            is_string($data['expected_return_at'] ?? null) ? Carbon::parse($data['expected_return_at']) : null,
                        ),
                    )),
                Action::make('recordReturn')
                    ->label(__('Record Return'))
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('Return to warehouse'))
                            ->options(fn (): array => Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->required(),
                        Select::make('condition_in')
                            ->label(__('Condition in'))
                            ->options([
                                StockCondition::Saleable->value => StockCondition::Saleable->label(),
                                StockCondition::Damaged->value => StockCondition::Damaged->label(),
                                StockCondition::Quarantine->value => StockCondition::Quarantine->label(),
                            ])
                            ->required(),
                        Textarea::make('notes')->label(__('Inspection notes')),
                    ])
                    ->authorize(fn (EquipmentLoan $record): bool => self::currentActor()->can('update', $record) && self::canMoveStock())
                    ->visible(static fn (EquipmentLoan $record): bool => $record->status === EquipmentLoanStatus::Issued)
                    ->action(fn (EquipmentLoan $record, array $data) => $this->run(
                        fn () => app(EquipmentLoanService::class)->recordReturn(
                            $record,
                            is_numeric($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : 0,
                            StockCondition::tryFrom(is_string($data['condition_in'] ?? null) ? $data['condition_in'] : '') ?? StockCondition::Quarantine,
                            self::currentActor(),
                            is_string($data['notes'] ?? null) && $data['notes'] !== '' ? $data['notes'] : null,
                        ),
                    )),
                Action::make('cancelLoan')
                    ->label(__('Cancel Loan'))
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()])
                    ->authorize(fn (EquipmentLoan $record): bool => self::currentActor()->can('update', $record))
                    ->visible(static fn (EquipmentLoan $record): bool => $record->status === EquipmentLoanStatus::Reserved)
                    ->action(fn (EquipmentLoan $record, array $data) => $this->run(
                        fn () => app(EquipmentLoanService::class)->cancel($record, self::currentActor(), is_string($data['reason'] ?? null) ? $data['reason'] : ''),
                    )),
            ])
            ->toolbarActions([]);
    }

    /** @return array<int, string> */
    private function loanerOptions(): array
    {
        return app(EquipmentLoanService::class)->eligibleLoaners($this->maintenanceRecord())
            ->mapWithKeys(static fn (SerializedInventoryUnit $unit): array => [
                $unit->id => collect([$unit->serial_number, $unit->productVariant?->name, $unit->warehouse?->name])->filter()->implode(' · '),
            ])
            ->all();
    }

    /** Custody moves through Inventory, so Support permission alone is never enough. */
    private static function canMoveStock(): bool
    {
        return self::currentActor()->can(InventoryPermission::LoanManage->value);
    }

    /** @param array<string, scalar> $replace */
    private static function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    /** Runs a service call, turning a domain failure into a notification. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $validationException) {
            Notification::make()->danger()->title(__('Unable to update the loan'))
                ->body(collect($validationException->errors())->flatten()->implode(' '))
                ->send();
        } catch (\DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to update the loan'))->body($domainException->getMessage())->send();
        }
    }

    private function maintenanceRecord(): MaintenanceRecord
    {
        $record = $this->getOwnerRecord();

        return $record instanceof MaintenanceRecord ? $record : throw new LogicException('Expected the owner record to be a MaintenanceRecord.');
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : throw new LogicException('An authenticated User is required.');
    }
}
