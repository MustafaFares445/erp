<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Actions;

use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketTriageService;
use App\Services\Support\WarrantyResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TriageTicketAction
{
    public static function make(): Action
    {
        return Action::make('triage')
            ->label('Triage Ticket')
            ->authorize('update')
            ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Pending)
            ->slideOver()
            ->schema([
                Section::make('Equipment')
                    ->schema([
                        Select::make('equipment_source')
                            ->options([
                                TicketEquipmentSource::SoldByUs->value => 'Sold by us',
                                TicketEquipmentSource::External->value => 'External equipment',
                            ])
                            ->required()
                            ->live(),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Customer equipment')
                            ->options(static fn (Ticket $record): array => SerializedInventoryUnit::query()
                                ->where('custody_type', SerializedCustodyType::Customer->value)
                                ->where('custody_reference_id', $record->customer_id)
                                ->with('productVariant')
                                ->orderBy('serial_number')
                                ->get()
                                ->mapWithKeys(static function (SerializedInventoryUnit $unit): array {
                                    $variant = $unit->productVariant;

                                    return [
                                        self::integerKey($unit) => sprintf(
                                            '%s — %s',
                                            $variant instanceof ProductVariant ? $variant->name : 'Product',
                                            $unit->serial_number,
                                        ),
                                    ];
                                })->all())
                            ->searchable()
                            ->required(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::SoldByUs->value)
                            ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::SoldByUs->value)
                            ->live(),
                        Placeholder::make('warranty_preview')
                            ->label('Warranty')
                            ->content(static function (Ticket $record, Get $get): string {
                                if ($get('equipment_source') === TicketEquipmentSource::External->value) {
                                    return 'External equipment — IERP warranty not applicable';
                                }

                                $id = $get('serialized_inventory_unit_id');
                                if (! is_numeric($id)) {
                                    return 'Select customer equipment to resolve warranty.';
                                }

                                $unit = SerializedInventoryUnit::query()->find((int) $id);
                                if (! $unit instanceof SerializedInventoryUnit) {
                                    return 'Warranty unavailable';
                                }

                                $customer = $record->customer;

                                if (! $customer instanceof CustomerProfile) {
                                    return 'Warranty unavailable';
                                }

                                $coverage = app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer);
                                $expiry = $coverage->expiresOn?->toDateString();

                                return str($coverage->status->value)->headline()->toString().($expiry !== null ? ' — until '.$expiry : '');
                            })
                            ->columnSpanFull(),
                        TextInput::make('external_equipment_name')
                            ->label('Equipment name')
                            ->required(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value)
                            ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                        TextInput::make('external_equipment_model')
                            ->label('Model')
                            ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                        TextInput::make('external_serial_number')
                            ->label('Serial number')
                            ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                    ])
                    ->columns(2),
                Section::make('Service decision')
                    ->schema([
                        Select::make('service_path')
                            ->label('Service path')
                            ->options([
                                TicketServicePath::RemoteSupport->value => 'Remote support',
                                TicketServicePath::Maintenance->value => 'Maintenance required',
                            ])
                            ->required(),
                        Select::make('billing_decision')
                            ->label('Commercial decision')
                            ->options([
                                'no_charge' => 'No charge',
                                'payment_required' => 'Payment required',
                                'waive' => 'Waive charge',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(static fn (Get $get): bool => $get('billing_decision') === 'payment_required')
                            ->visible(static fn (Get $get): bool => $get('billing_decision') === 'payment_required'),
                        Select::make('currency')
                            ->options([
                                'USD' => 'US Dollar (USD)',
                                'AED' => 'UAE Dirham (AED)',
                            ])
                            ->native(false)
                            ->required(static fn (Get $get): bool => $get('billing_decision') === 'payment_required')
                            ->visible(static fn (Get $get): bool => $get('billing_decision') === 'payment_required'),
                        Textarea::make('charge_waived_reason')
                            ->label('Waiver reason')
                            ->required(static fn (Get $get): bool => $get('billing_decision') === 'waive')
                            ->visible(static fn (Get $get): bool => $get('billing_decision') === 'waive')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->action(static function (Ticket $record, array $data): void {
                try {
                    app(TicketTriageService::class)->triage($record, self::stringKeyedData($data), self::currentActor());
                    Notification::make()->success()->title('Ticket triaged')->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title('Unable to triage ticket')->body($exception->getMessage())->send();
                }
            });
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyedData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Ticket triage fields must use string keys.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function integerKey(SerializedInventoryUnit $unit): int
    {
        $key = $unit->getKey();

        if (! is_numeric($key)) {
            throw new LogicException('Serialized equipment must have a numeric identifier.');
        }

        return (int) $key;
    }
}
