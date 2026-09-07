<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Data\Support\LabourEntryData;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceCostService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class LabourEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'labourEntries';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('performed_on', 'desc')
            ->columns([
                TextColumn::make('employee.name')->label('Employee'),
                TextColumn::make('performed_on')->date(),
                TextColumn::make('minutes')->numeric(),
                TextColumn::make('hourly_rate_minor')->label('Rate')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                TextColumn::make('total_cost_minor')->label('Cost')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                TextColumn::make('notes')->limit(40)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('recordLabour')
                    ->label('Record Labour')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        DatePicker::make('performed_on')->required()->default(now()),
                        TextInput::make('minutes')->numeric()->minValue(1)->required(),
                        TextInput::make('hourly_rate_minor')
                            ->label('Hourly rate (minor units)')
                            ->numeric()
                            ->helperText("Leave blank to use the employee's default rate."),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('recordCost', $this->maintenanceRecord()))
                    ->action(function (array $data): void {
                        try {
                            app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
                                maintenanceRecordId: $this->maintenanceRecord()->id,
                                serviceRecordId: null,
                                employeeId: self::requiredInt($data, 'employee_id'),
                                performedOn: self::requiredString($data, 'performed_on'),
                                minutes: self::requiredInt($data, 'minutes'),
                                hourlyRateMinor: self::optionalInt($data, 'hourly_rate_minor'),
                                notes: self::optionalString($data, 'notes'),
                            ), self::currentActor());
                        } catch (DomainException $domainException) {
                            Notification::make()->danger()->title('Unable to record labour')->body($domainException->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    private function maintenanceRecord(): MaintenanceRecord
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof MaintenanceRecord) {
            throw new LogicException('Expected the owner record of LabourEntriesRelationManager to be a MaintenanceRecord.');
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

    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
