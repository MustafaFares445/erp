<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Actions;

use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Filament\Support\CurrencySelect;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\TicketTriageService;
use App\Services\Support\WarrantyResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TriageTicketAction
{
    public static function make(): Action
    {
        return Action::make('triage')
            ->label(__('Triage Ticket'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->authorize('update')
            ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Pending)
            ->slideOver()
            ->steps([
                Step::make(__('Identify equipment'))
                    ->description(__('Link the exact customer asset before any warranty decision is shown.'))
                    ->icon(Heroicon::OutlinedQrCode)
                    ->schema([
                        Section::make(__('Equipment source'))
                            ->schema([
                                Select::make('equipment_source')
                                    ->options([
                                        TicketEquipmentSource::SoldByUs->value => TicketEquipmentSource::SoldByUs->label(),
                                        TicketEquipmentSource::External->value => TicketEquipmentSource::External->label(),
                                    ])
                                    ->required()
                                    ->live(),
                                Select::make('serialized_inventory_unit_id')
                                    ->label(__('Customer equipment'))
                                    ->options(static fn (Ticket $record): array => self::equipmentOptions($record))
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('Search by product or serial number. Only equipment currently in this customer custody is shown.'))
                                    ->required(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::SoldByUs->value)
                                    ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::SoldByUs->value)
                                    ->live(),
                                TextInput::make('external_equipment_name')
                                    ->label(__('Equipment name'))
                                    ->required(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value)
                                    ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                                TextInput::make('external_equipment_model')
                                    ->label(__('Model'))
                                    ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                                TextInput::make('external_serial_number')
                                    ->label(__('Serial number'))
                                    ->visible(static fn (Get $get): bool => $get('equipment_source') === TicketEquipmentSource::External->value),
                            ])
                            ->columns(2),
                    ]),
                Step::make(__('Warranty eligibility'))
                    ->description(__('Eligibility is read-only here. Repair coverage is decided only after diagnosis.'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->schema([
                        Section::make(__('Customer warranty'))
                            ->description(__('Being inside the warranty period makes the equipment eligible for warranty review; it does not automatically make every repair free.'))
                            ->schema([
                                Placeholder::make('warranty_preview')
                                    ->label(__('Eligibility'))
                                    ->content(static fn (Ticket $record, Get $get): string => self::warrantyPreview($record, $get)),
                                Placeholder::make('warranty_policy')
                                    ->label(__('Policy / entitlement'))
                                    ->content(static fn (Ticket $record, Get $get): string => self::policyPreview($record, $get)),
                            ]),
                    ]),
                Step::make(__('Service routing'))
                    ->description(__('Choose the operational path. Any payment requested here is a diagnostic fee only.'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->schema([
                        Section::make(__('Next step'))
                            ->schema([
                                Select::make('service_path')
                                    ->label(__('Service path'))
                                    ->options([
                                        TicketServicePath::RemoteSupport->value => TicketServicePath::RemoteSupport->label(),
                                        TicketServicePath::Maintenance->value => TicketServicePath::Maintenance->label(),
                                        TicketServicePath::OnSiteVisit->value => TicketServicePath::OnSiteVisit->label(),
                                    ])
                                    ->required(),
                                Toggle::make('diagnostic_fee_required')
                                    ->label(__('Diagnostic fee required before technical work'))
                                    ->helperText(__('Do not use this for the final repair price. Repair billing is decided after diagnosis.'))
                                    ->live()
                                    ->default(false),
                                TextInput::make('diagnostic_fee_amount')
                                    ->label(__('Diagnostic fee'))
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required(static fn (Get $get): bool => (bool) $get('diagnostic_fee_required'))
                                    ->visible(static fn (Get $get): bool => (bool) $get('diagnostic_fee_required')),
                                CurrencySelect::makeBase('diagnostic_fee_currency')
                                    ->label(__('Currency'))
                                    ->required(static fn (Get $get): bool => (bool) $get('diagnostic_fee_required'))
                                    ->visible(static fn (Get $get): bool => (bool) $get('diagnostic_fee_required')),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->action(static function (Ticket $record, array $data): void {
                try {
                    app(TicketTriageService::class)->triage(
                        $record,
                        self::stringKeyedData($data),
                        self::currentActor(),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Ticket triaged'))
                        ->body(__('Equipment eligibility and service route were recorded. Final repair coverage remains pending diagnosis.'))
                        ->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('Unable to triage ticket'))
                        ->body($exception->getMessage())
                        ->send();
                }
            });
    }

    /** @return array<int, string> */
    private static function equipmentOptions(Ticket $ticket): array
    {
        $customer = $ticket->customer;

        if (! $customer instanceof CustomerProfile) {
            return [];
        }

        return SerializedInventoryUnit::query()
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_id', $ticket->customer_id)
            ->with(['productVariant', 'warrantyEntitlements'])
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(static function (SerializedInventoryUnit $unit) use ($customer): array {
                $variant = $unit->productVariant;
                $coverage = app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer);
                $expiry = $coverage->expiresOn?->toDateString();

                $label = sprintf(
                    '%s — %s — %s%s',
                    $variant instanceof ProductVariant ? $variant->name : 'Product',
                    $unit->serial_number,
                    $coverage->status->label(),
                    $expiry !== null ? ' until '.$expiry : '',
                );

                return [self::integerKey($unit) => $label];
            })
            ->all();
    }

    private static function warrantyPreview(Ticket $ticket, Get $get): string
    {
        if ($get('equipment_source') === TicketEquipmentSource::External->value) {
            return 'IERP sale warranty is not applicable. Manufacturer, supplier, service-contract or goodwill coverage can still be recorded after diagnosis.';
        }

        $id = $get('serialized_inventory_unit_id');

        if (! is_numeric($id)) {
            return 'Select customer equipment to resolve warranty eligibility.';
        }

        $unit = SerializedInventoryUnit::query()
            ->with(['productVariant.warrantyPolicy', 'warrantyEntitlements'])
            ->find((int) $id);
        $customer = $ticket->customer;

        if (! $unit instanceof SerializedInventoryUnit || ! $customer instanceof CustomerProfile) {
            return 'Warranty eligibility is unavailable.';
        }

        $coverage = app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer);
        $expiry = $coverage->expiresOn?->toDateString();

        return match ($coverage->status->value) {
            'covered' => 'Warranty active'.($expiry !== null ? ' until '.$expiry : '').'. Final repair coverage will be confirmed after technical diagnosis.',
            'expired' => 'Warranty expired'.($expiry !== null ? ' on '.$expiry : '').'. Diagnosis may still qualify for goodwill, service-contract, manufacturer or supplier coverage.',
            'not_covered' => 'No IERP customer warranty is configured for this equipment.',
            'unknown' => 'Warranty needs verification before a seller-warranty claim can be approved.',
            default => 'IERP sale warranty is not applicable.',
        };
    }

    private static function policyPreview(Ticket $ticket, Get $get): string
    {
        $id = $get('serialized_inventory_unit_id');

        if (! is_numeric($id)) {
            return '—';
        }

        $entitlement = WarrantyEntitlement::query()
            ->where('serialized_inventory_unit_id', (int) $id)
            ->where('customer_id', $ticket->customer_id)
            ->latest('id')
            ->first();

        if (! $entitlement instanceof WarrantyEntitlement) {
            return 'No activated entitlement snapshot yet.';
        }

        return sprintf(
            '%s · %s · starts from %s',
            $entitlement->policy_name,
            $entitlement->state->label(),
            $entitlement->start_trigger->label(),
        );
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }

    /** @param array<array-key, mixed> $data
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
