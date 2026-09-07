<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Data\Support\ThirdPartyCostData;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceCostService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class ThirdPartyCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'thirdPartyCosts';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('incurred_on', 'desc')
            ->columns([
                TextColumn::make('supplier.name')->label('Supplier')->placeholder('—'),
                TextColumn::make('description'),
                TextColumn::make('amount_minor')->label('Amount')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                TextColumn::make('incurred_on')->date(),
                TextColumn::make('bill.bill_number')->label('Bill')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('recordThirdPartyCost')
                    ->label('Record Cost')
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('amount_minor')->label('Amount (minor units)')->numeric()->minValue(1)->required(),
                        DatePicker::make('incurred_on')->required()->default(now()),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('recordCost', $this->maintenanceRecord()))
                    ->action(function (array $data): void {
                        try {
                            app(MaintenanceCostService::class)->recordThirdPartyCost(new ThirdPartyCostData(
                                maintenanceRecordId: $this->maintenanceRecord()->id,
                                description: self::requiredString($data, 'description'),
                                amountMinor: self::requiredInt($data, 'amount_minor'),
                                incurredOn: self::requiredString($data, 'incurred_on'),
                                supplierId: self::optionalInt($data, 'supplier_id'),
                            ), self::currentActor());
                        } catch (DomainException $domainException) {
                            Notification::make()->danger()->title('Unable to record this cost')->body($domainException->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    private function maintenanceRecord(): MaintenanceRecord
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof MaintenanceRecord) {
            throw new LogicException('Expected the owner record of ThirdPartyCostsRelationManager to be a MaintenanceRecord.');
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

    /** @param array<array-key, mixed> $data */
    private static function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_numeric($value)) {
            throw new LogicException(sprintf('The "%s" field must be a number.', $key));
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new LogicException(sprintf('The "%s" field is required.', $key));
        }

        return $value;
    }
}
