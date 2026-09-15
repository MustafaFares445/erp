<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\SalesPermission;
use App\Enums\UserType;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentTerm;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Sales\SalesOrderService;
use DomainException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CreateOrder extends CreateRecord
{
    use HasWizard;

    protected static string $resource = OrderResource::class;

    /** @return array<Step> */
    protected function getSteps(): array
    {
        return [
            Step::make('Customer & destination')
                ->description('Capture the customer commitment. Warehouse allocation happens later in Logistics.')
                ->icon(Heroicon::OutlinedUser)
                ->schema([
                    Section::make('Customer commitment')
                        ->schema([
                            Select::make('customer_id')
                                ->label('Customer')
                                ->options(fn (): array => CustomerProfile::query()
                                    ->where('is_active', true)
                                    ->orderBy('company_name')
                                    ->pluck('company_name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('customer_delivery_address_id', null);
                                }),
                            Select::make('customer_delivery_address_id')
                                ->label('Delivery address')
                                ->options(fn (Get $get): array => $this->deliveryAddressOptions($get('customer_id')))
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $this->deliveryAddressOptions($get('customer_id')) !== []),
                            DatePicker::make('scheduled_at')
                                ->label('Requested delivery date')
                                ->native(false),
                            Select::make('responsible_id')
                                ->label('Responsible salesperson')
                                ->options(fn (): array => User::query()
                                    ->where(function (Builder $query): void {
                                        $query
                                            ->where('user_type', UserType::Admin->value)
                                            ->orWhereHas('employeeProfile', fn (Builder $employee): Builder => $employee->where('is_active', true));
                                    })
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload(),
                        ])
                        ->columns(2),
                ]),
            Step::make('Products & commercial quantities')
                ->description('Select products, transaction UOMs and customer quantities. Availability is advisory only.')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->schema([
                    Section::make('Order lines')
                        ->schema([
                            Repeater::make('lines')
                                ->label('Products')
                                ->minItems(1)
                                ->defaultItems(1)
                                ->required()
                                ->addActionLabel('Add product')
                                ->schema([
                                    Select::make('product_variant_id')
                                        ->label('Product')
                                        ->options(fn (): array => $this->productOptions())
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                                            $set('unit_id', $this->defaultSaleUnitId($state));
                                        }),
                                    Select::make('unit_id')
                                        ->label('UOM')
                                        ->options(fn (Get $get): array => $this->saleUnitOptions($get('product_variant_id')))
                                        ->required()
                                        ->searchable()
                                        ->live(),
                                    TextInput::make('quantity')
                                        ->label('Quantity')
                                        ->numeric()
                                        ->minValue(0.000001)
                                        ->required()
                                        ->live(onBlur: true),
                                    Placeholder::make('price_preview')
                                        ->label('Resolved unit price')
                                        ->content(fn (Get $get): string => $this->pricePreview(
                                            $get('../../customer_id'),
                                            $get('product_variant_id'),
                                            $get('unit_id'),
                                        )),
                                    Placeholder::make('availability')
                                        ->label('Available now')
                                        ->content(fn (Get $get): string => $this->availabilityPreview($get('product_variant_id'))),
                                    Placeholder::make('availability_note')
                                        ->label('Inventory effect')
                                        ->content('Advisory only — saving or confirming this order does not reserve or move stock.'),
                                ])
                                ->columns(3)
                                ->columnSpanFull(),
                        ]),
                ]),
            Step::make('Commercial terms & review')
                ->description('Review the commercial document before saving it as a Draft.')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->schema([
                    Section::make('Commercial terms')
                        ->schema([
                            Select::make('payment_term_id')
                                ->label('Payment term')
                                ->options(fn (): array => PaymentTerm::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload(),
                            Textarea::make('notes')
                                ->label('Order notes')
                                ->rows(4)
                                ->maxLength(5000)
                                ->columnSpanFull(),
                            Toggle::make('confirm_now')
                                ->label('Save and confirm commercial order')
                                ->helperText('Confirmation freezes quantity, UOM, price and tax evidence. It still does not reserve stock.')
                                ->visible(fn (): bool => auth()->user()?->can(SalesPermission::OrderConfirm->value) ?? false),
                            Placeholder::make('review_note')
                                ->label('Next module')
                                ->content('After confirmation, Sales must explicitly Release to Logistics. Logistics then chooses warehouses, lots/serials, reservations and shipment execution.')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),
        ];
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Order
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AccessDeniedHttpException;
        }

        $lineState = $data['lines'] ?? null;
        $lines = is_array($lineState)
            ? array_values(array_map(self::normalizeLineState(...), $lineState))
            : [];
        $address = $this->deliveryAddress($data['customer_delivery_address_id'] ?? null, $data['customer_id'] ?? null);

        $order = app(SalesOrderService::class)->createDraft($actor, [
            'customer_id' => $data['customer_id'] ?? null,
            'customer_delivery_address_id' => $address?->getKey(),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'responsible_id' => $data['responsible_id'] ?? null,
            'destination_address_snapshot' => $address instanceof CustomerDeliveryAddress ? [
                'address' => $address->address,
                'country' => $address->country,
                'city' => $address->city,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                'contact_name' => $address->contact_name,
                'contact_phone' => $address->contact_phone,
            ] : null,
            'payment_term_id' => $data['payment_term_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $lines);

        if (($data['confirm_now'] ?? false) === true && $actor->can(SalesPermission::OrderConfirm->value)) {
            return app(SalesOrderService::class)->confirm($actor, $order);
        }

        return $order;
    }

    /** @return array<int, string> */
    private function productOptions(): array
    {
        return ProductVariant::query()
            ->with('product:id,name')
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('sku')
            ->get(['id', 'product_id', 'name', 'sku'])
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => sprintf('%s — %s (%s)', $variant->product?->name, $variant->name, $variant->sku),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function deliveryAddressOptions(mixed $customerId): array
    {
        if (! is_numeric($customerId)) {
            return [];
        }

        return CustomerDeliveryAddress::query()
            ->where('customer_profile_id', (int) $customerId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get(['id', 'label', 'address', 'city'])
            ->mapWithKeys(fn (CustomerDeliveryAddress $address): array => [
                $address->id => mb_trim(($address->label ?: 'Address').' — '.$address->address.($address->city ? ', '.$address->city : '')),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function saleUnitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        $options = ProductVariantUnit::query()
            ->with('unit:id,name,symbol')
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_sale', true)
            ->orderByDesc('is_base')
            ->get()
            ->mapWithKeys(fn (ProductVariantUnit $variantUnit): array => [
                $variantUnit->unit_id => mb_trim(($variantUnit->unit->name ?? 'Unit').' '.($variantUnit->unit->symbol ?? '')),
            ])
            ->all();

        if ($options !== []) {
            return $options;
        }

        $variant = ProductVariant::query()->find((int) $variantId);
        $unit = $variant instanceof ProductVariant ? Unit::query()->find($variant->unit_id) : null;

        return $unit instanceof Unit ? [$unit->id => mb_trim($unit->name.' '.$unit->symbol)] : [];
    }

    /** @return array<string, mixed> */
    private static function normalizeLineState(mixed $line): array
    {
        if (! is_array($line)) {
            throw new LogicException('Each sales order line must be an array.');
        }

        $normalizedLine = [];

        foreach ($line as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Sales order line keys must be strings.');
            }

            $normalizedLine[$key] = $value;
        }

        return $normalizedLine;
    }

    private function defaultSaleUnitId(mixed $variantId): ?int
    {
        if (! is_numeric($variantId)) {
            return null;
        }

        $unitId = ProductVariantUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_sale', true)
            ->orderByDesc('is_base')
            ->value('unit_id');

        if (is_numeric($unitId)) {
            return (int) $unitId;
        }

        $base = ProductVariant::query()->find((int) $variantId);

        return $base instanceof ProductVariant ? (int) $base->unit_id : null;
    }

    private function availabilityPreview(mixed $variantId): string
    {
        if (! is_numeric($variantId)) {
            return 'Select a product';
        }

        $available = (float) InventoryStock::query()
            ->where('product_variant_id', (int) $variantId)
            ->sum('available_quantity');

        return number_format($available, 6, '.', '').' base units';
    }

    private function pricePreview(mixed $customerId, mixed $variantId, mixed $unitId): string
    {
        if (! is_numeric($variantId)) {
            return 'Select a product';
        }

        $variant = ProductVariant::query()->find((int) $variantId);
        if (! $variant instanceof ProductVariant) {
            return 'Unavailable';
        }

        $customer = is_numeric($customerId)
            ? CustomerProfile::query()->with('user')->find((int) $customerId)
            : null;

        try {
            $resolved = app(PriceResolver::class)->resolve($variant, $customer?->user);
        } catch (DomainException) {
            return 'Price requires review';
        }

        $factor = 1.0;
        if (is_numeric($unitId)) {
            $variantUnit = ProductVariantUnit::query()
                ->where('product_variant_id', $variant->id)
                ->where('unit_id', (int) $unitId)
                ->where('is_active', true)
                ->first();
            if ($variantUnit instanceof ProductVariantUnit) {
                $factor = (float) $variantUnit->factor_to_base;
            }
        }

        return number_format($resolved->amount * $factor, 2, '.', '').' ('.$resolved->source->value.')';
    }

    private function deliveryAddress(mixed $addressId, mixed $customerId): ?CustomerDeliveryAddress
    {
        if (! is_numeric($addressId)) {
            return null;
        }

        $address = CustomerDeliveryAddress::query()
            ->whereKey((int) $addressId)
            ->where('customer_profile_id', is_numeric($customerId) ? (int) $customerId : 0)
            ->where('is_active', true)
            ->first();

        if (! $address instanceof CustomerDeliveryAddress) {
            throw ValidationException::withMessages(['customer_delivery_address_id' => 'Select an active delivery address owned by this customer.']);
        }

        return $address;
    }
}
