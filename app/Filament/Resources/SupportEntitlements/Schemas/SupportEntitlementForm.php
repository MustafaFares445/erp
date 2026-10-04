<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Schemas;

use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportServiceLevel;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class SupportEntitlementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Entitlement'))
                ->description(__('Grants a customer a service level for a period. Leave the equipment empty to cover the whole customer, or pick a unit to cover only that equipment.'))
                ->schema([
                    Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->limit(200)->pluck('company_name', 'id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => CustomerProfile::query()
                            ->where('company_name', 'like', "%{$search}%")
                            ->orderBy('company_name')->limit(50)->pluck('company_name', 'id')->all())
                        ->getOptionLabelUsing(static function (mixed $value): ?string {
                            $name = CustomerProfile::query()->whereKey($value)->value('company_name');

                            return is_string($name) ? $name : null;
                        })
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(static fn (callable $set): mixed => $set('serialized_inventory_unit_id', null)),
                    Select::make('support_service_level_id')
                        ->label(__('Service level'))
                        ->options(fn (): array => SupportServiceLevel::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('serialized_inventory_unit_id')
                        ->label(__('Equipment (serial number)'))
                        ->helperText(__("Only equipment currently in the selected customer's custody is offered."))
                        ->options(static fn (Get $get): array => self::equipmentOptions(self::customerId($get)))
                        ->searchable()
                        ->nullable()
                        ->rules([static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if ($value === null || $value === '') {
                                return;
                            }
                            if (! is_numeric($value) || ! array_key_exists((int) $value, self::equipmentOptions(self::customerId($get)))) {
                                $fail(__("The selected equipment is not in this customer's custody."));
                            }
                        }]),
                    Select::make('status')
                        ->label(__('Status'))
                        ->options(collect(SupportEntitlementStatus::cases())->mapWithKeys(
                            static fn (SupportEntitlementStatus $status): array => [$status->value => $status->label()],
                        )->all())
                        ->required()
                        ->default(SupportEntitlementStatus::Active->value),
                    DatePicker::make('starts_on')->label(__('Starts on'))->required(),
                    DatePicker::make('ends_on')->label(__('Ends on'))->after('starts_on')
                        ->helperText(__('Leave empty for an open-ended entitlement.')),
                    TextInput::make('external_reference')->label(__('External reference'))->maxLength(255)
                        ->helperText(__('Contract or purchase-order number from the customer agreement.')),
                    Textarea::make('notes')->label(__('Notes'))->rows(3)->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    private static function customerId(Get $get): int
    {
        $customerId = $get('customer_id');

        return is_numeric($customerId) ? (int) $customerId : 0;
    }

    /** @return array<int, string> */
    private static function equipmentOptions(int $customerId): array
    {
        if ($customerId === 0) {
            return [];
        }

        return SerializedInventoryUnit::query()
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_id', $customerId)
            ->with('productVariant:id,name')
            ->orderBy('serial_number')
            ->limit(200)
            ->get()
            ->mapWithKeys(static fn (SerializedInventoryUnit $unit): array => [
                $unit->id => $unit->serial_number.' — '.($unit->productVariant->name ?? ''),
            ])->all();
    }
}
