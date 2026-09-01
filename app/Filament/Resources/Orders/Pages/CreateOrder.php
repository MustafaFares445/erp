<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Data\Orders\OrderFulfillmentData;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotService;
use App\Services\Orders\OrderFulfillmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CreateOrder extends CreateRecord
{
    use HasWizard;

    protected static string $resource = OrderResource::class;

    private OrderFulfillmentService $orderFulfillmentService;

    private InventoryLotService $inventoryLotService;

    public function boot(OrderFulfillmentService $orderFulfillmentService, InventoryLotService $inventoryLotService): void
    {
        $this->orderFulfillmentService = $orderFulfillmentService;
        $this->inventoryLotService = $inventoryLotService;
    }

    /** @return array<Step> */
    protected function getSteps(): array
    {
        return [
            Step::make('Select customer')
                ->description('Choose the destination for this order.')
                ->icon(Heroicon::OutlinedUser)
                ->completedIcon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Section::make('Delivery destination')
                        ->description('The customer location is used to rank warehouses and estimate delivery distance.')
                        ->icon(Heroicon::OutlinedMapPin)
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
                                    $set('shipments', []);
                                }),
                            Placeholder::make('customer_location')
                                ->label('Delivery location')
                                ->content(fn (Get $get): Htmlable => $this->customerLocation($get('customer_id'))),
                        ])
                        ->columns(2),
                ]),
            Step::make('Select products')
                ->description('Add the items and quantities the customer needs.')
                ->icon(Heroicon::OutlinedCube)
                ->completedIcon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Section::make('Order items')
                        ->description('Availability is updated from active warehouse stock as you build the order.')
                        ->icon(Heroicon::OutlinedShoppingCart)
                        ->schema([
                            Repeater::make('products')
                                ->label('Products')
                                ->minItems(1)
                                ->defaultItems(1)
                                ->required()
                                ->addActionLabel('Add product')
                                ->columns(3)
                                ->schema([
                                    Select::make('product_variant_id')
                                        ->label('Product')
                                        ->options(fn (): array => $this->productOptions())
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live(),
                                    TextInput::make('quantity')
                                        ->label('Required quantity')
                                        ->numeric()
                                        ->minValue(0.001)
                                        ->required()
                                        ->live(onBlur: true),
                                    Placeholder::make('availability')
                                        ->label('Availability')
                                        ->content(fn (Get $get): Htmlable => $this->availabilitySummary($get('product_variant_id'))),
                                ])
                                ->columnSpanFull(),
                        ]),
                    Placeholder::make('fulfillment_warning')
                        ->hiddenLabel()
                        ->content(fn (Get $get): Htmlable => $this->fulfillmentWarning($get('products'))),
                ])
                ->afterValidation(function (Get $get, Set $set): void {
                    $customer = $this->selectedCustomer($get('customer_id'));
                    $products = $this->stateArray($get('products'));
                    $set('shipments', $this->suggestShipments($customer, $products));
                }),
            Step::make('Select warehouses')
                ->description('Review the suggested shipments, then adjust them when needed.')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->completedIcon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Actions::make([
                        Action::make('rerunWarehouseSelection')
                            ->label('Refresh recommended warehouses')
                            ->icon(Heroicon::ArrowPath)
                            ->color('primary')
                            ->action(function (Get $get, Set $set): void {
                                $customer = $this->selectedCustomer($get('customer_id'));
                                $products = $this->stateArray($get('products'));
                                $set('shipments', $this->suggestShipments($customer, $products));
                            }),
                    ]),
                    Section::make('Warehouse shipments')
                        ->description('Each card is one delivery. Keep products together where possible to reduce split shipments.')
                        ->icon(Heroicon::OutlinedTruck)
                        ->schema([
                            Repeater::make('shipments')
                                ->label('')
                                ->minItems(1)
                                ->required()
                                ->addActionLabel('Add another warehouse')
                                ->schema([
                                    Select::make('warehouse_id')
                                        ->label('Warehouse')
                                        ->options(fn (): array => $this->warehouseOptions())
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live(),
                                    TextInput::make('tracking_number')
                                        ->label('Tracking number')
                                        ->maxLength(100)
                                        ->placeholder('Leave empty to generate a tracking number automatically.'),
                                    FileUpload::make('attachments')
                                        ->label('Shipment attachments')
                                        ->multiple()
                                        ->maxFiles(20)
                                        ->panelLayout('grid')
                                        ->disk('local')
                                        ->directory('shipment-attachments')
                                        ->visibility('private')
                                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize(5120),
                                    Placeholder::make('warehouse_route')
                                        ->label('Location and distance')
                                        ->content(fn (Get $get): string => $this->warehouseRouteSummary(
                                            $get('warehouse_id'),
                                            $get('../../customer_id'),
                                        )),
                                    Repeater::make('assignments')
                                        ->label('Products in this shipment')
                                        ->minItems(1)
                                        ->required()
                                        ->addActionLabel('Assign product')
                                        ->columns(3)
                                        ->schema([
                                            Select::make('product_variant_id')
                                                ->label('Product')
                                                ->options(fn (Get $get): array => $this->selectedProductOptions($get('/data.products')))
                                                ->searchable()
                                                ->preload()
                                                ->required()
                                                ->live(),
                                            TextInput::make('quantity')
                                                ->label('Assigned quantity')
                                                ->numeric()
                                                ->minValue(0.001)
                                                ->required()
                                                ->live(onBlur: true),
                                            Select::make('inventory_lot_id')
                                                ->label('Batch / lot')
                                                ->options(fn (Get $get): array => $this->lotOptions(
                                                    $get('product_variant_id'),
                                                    $get('../../warehouse_id'),
                                                ))
                                                ->default(fn (Get $get): ?int => array_key_first($this->lotOptions(
                                                    $get('product_variant_id'),
                                                    $get('../../warehouse_id'),
                                                )))
                                                ->searchable()
                                                ->visible(fn (Get $get): bool => $this->requiresLot($get('product_variant_id')))
                                                ->required(fn (Get $get): bool => $this->requiresLot($get('product_variant_id')))
                                                ->live(),
                                            Placeholder::make('warehouse_stock')
                                                ->label('Warehouse stock')
                                                ->content(fn (Get $get): Htmlable => $this->warehouseStockSummary(
                                                    $get('product_variant_id'),
                                                    $get('../../warehouse_id'),
                                                )),
                                        ]),
                                ])
                                ->columnSpanFull(),
                        ]),
                ])
                ->afterValidation(function (Get $get): void {
                    $this->orderFulfillmentService->validateFulfillment(
                        $this->stateArray($get('products')),
                        $this->stateArray($get('shipments')),
                    );
                }),
            Step::make('Delivery routes preview')
                ->description('Confirm every shipment before creating the order.')
                ->icon(Heroicon::OutlinedMap)
                ->completedIcon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Placeholder::make('route_preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): Htmlable => $this->routePreview(
                            $get('customer_id'),
                            $this->stateArray($get('shipments')),
                        ))
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Order notes')
                        ->maxLength(5000)
                        ->columnSpanFull(),
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

        $customerId = $this->integer($data['customer_id'] ?? null);

        if ($customerId === null) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer before creating the order.']);
        }

        return $this->orderFulfillmentService->create(new OrderFulfillmentData(
            customer: $this->selectedCustomer($customerId),
            products: $this->stateArray($data['products'] ?? null),
            shipments: $this->stateArray($data['shipments'] ?? null),
            actor: $actor,
            notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
        ));
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
            ->mapWithKeys(function (ProductVariant $variant): array {
                $variantId = $this->integer($variant->getKey());

                // @codeCoverageIgnoreStart
                // ProductVariant uses an auto-incrementing integer primary key.
                if ($variantId === null) {
                    return [];
                }

                // @codeCoverageIgnoreEnd

                return [$variantId => sprintf('%s — %s (%s)', $variant->product?->name, $variant->name, $variant->sku)];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function warehouseOptions(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(function (Warehouse $warehouse): array {
                $warehouseId = $this->integer($warehouse->getKey());

                return $warehouseId === null ? [] : [$warehouseId => $warehouse->name];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function selectedProductOptions(mixed $products): array
    {
        $productIds = collect($this->stateArray($products))
            ->map(fn (mixed $product): ?int => is_array($product) ? $this->integer($product['product_variant_id'] ?? null) : null)
            ->filter()
            ->all();

        return ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('id', $productIds)
            ->orderBy('sku')
            ->get(['id', 'product_id', 'name', 'sku'])
            ->mapWithKeys(function (ProductVariant $variant): array {
                $variantId = $this->integer($variant->getKey());

                // @codeCoverageIgnoreStart
                // ProductVariant uses an auto-incrementing integer primary key.
                if ($variantId === null) {
                    return [];
                }

                // @codeCoverageIgnoreEnd

                return [$variantId => sprintf('%s — %s (%s)', $variant->product?->name, $variant->name, $variant->sku)];
            })
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $products
     * @return list<array<string, mixed>>
     */
    private function suggestShipments(CustomerProfile $customer, array $products): array
    {
        $shipments = $this->orderFulfillmentService->suggest($customer, $products);

        // @codeCoverageIgnoreStart
        // OrderFulfillmentService::suggest() returns validated shipment rows.
        foreach ($shipments as $shipmentKey => $shipment) {
            if (! is_array($shipment['assignments'] ?? null)) {
                continue;
            }

            foreach ($shipment['assignments'] as $assignmentKey => $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                if ($this->integer($assignment['product_variant_id'] ?? null) === null) {
                    continue;
                }

                if ($this->requiresLot($assignment['product_variant_id'])) {
                    $assignment['inventory_lot_id'] = array_key_first($this->lotOptions(
                        $assignment['product_variant_id'],
                        $shipment['warehouse_id'] ?? null,
                    ));
                }

                $shipments[$shipmentKey]['assignments'][$assignmentKey] = $assignment;
            }
        }

        // @codeCoverageIgnoreEnd

        return $shipments;
    }

    /** @return array<int, string> */
    private function lotOptions(mixed $productVariantId, mixed $warehouseId): array
    {
        $productVariantId = $this->integer($productVariantId);
        $warehouseId = $this->integer($warehouseId);

        if ($productVariantId === null || $warehouseId === null) {
            return [];
        }

        $options = [];

        foreach ($this->inventoryLotService->availableLots($productVariantId, $warehouseId) as $lot) {
            $lotId = $this->integer($lot->getKey());

            // @codeCoverageIgnoreStart
            // InventoryLot uses an auto-incrementing integer primary key.
            if ($lotId === null) {
                continue;
            }

            // @codeCoverageIgnoreEnd

            $lotLabel = $lot->lot_number ?? '#'.$lotId;
            $options[$lotId] = $lot->expires_at === null
                ? __('admin.inventory.lot.option_no_expiry', [
                    'lot' => $lotLabel,
                    'available' => $lot->availableQuantity($warehouseId),
                ])
                : __('admin.inventory.lot.option', [
                    'lot' => $lotLabel,
                    'date' => $lot->expires_at->toDateString(),
                    'available' => $lot->availableQuantity($warehouseId),
                ]);
        }

        return $options;
    }

    private function requiresLot(mixed $productVariantId): bool
    {
        $productVariantId = $this->integer($productVariantId);

        return $productVariantId !== null
            && ProductVariant::query()->whereKey($productVariantId)->value('track_batches') === true;
    }

    private function customerLocation(mixed $customerId): Htmlable
    {
        $customerId = $this->integer($customerId);

        if ($customerId === null) {
            return new HtmlString('<div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-white/15 dark:bg-white/5 dark:text-gray-400">Select a customer to load the delivery address.</div>');
        }

        $customer = CustomerProfile::query()->find($customerId);

        if (! $customer instanceof CustomerProfile) {
            return new HtmlString('<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">The selected customer is no longer available.</div>');
        }

        $location = collect([$customer->address, $customer->city, $customer->country])->filter()->implode(', ');

        if ($location === '') {
            return new HtmlString('<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">No delivery address is recorded for this customer.</div>');
        }

        $coordinates = is_numeric($customer->latitude) && is_numeric($customer->longitude)
            ? 'Coordinates are ready for warehouse ranking.'
            : 'Add coordinates to enable distance-based warehouse ranking.';

        return new HtmlString('<div class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-500/10"><p class="font-medium text-primary-950 dark:text-primary-100">'.e($location).'</p><p class="mt-1 text-xs text-primary-700 dark:text-primary-200">'.e($coordinates).'</p></div>');
    }

    private function availabilitySummary(mixed $productVariantId): Htmlable
    {
        $productVariantId = $this->integer($productVariantId);

        if ($productVariantId === null) {
            return new HtmlString('Select a product to view availability.');
        }

        $availability = $this->orderFulfillmentService->availability($productVariantId);
        $warehouses = collect($availability['warehouses'])
            ->map(fn (array $warehouse): string => '<li>'.e($warehouse['name']).'<span>'.number_format($warehouse['available_quantity'], 3).'</span></li>')
            ->implode('');
        $status = $availability['available_quantity'] > 0 ? 'Available' : 'Unavailable';
        $statusClasses = $availability['available_quantity'] > 0
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
            : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200';

        return new HtmlString('<div class="rounded-xl border px-3 py-2.5 '.$statusClasses.'"><div class="flex items-center justify-between gap-2"><strong>'.e($status).'</strong><span class="text-xs font-medium">'.number_format($availability['available_quantity'], 3).' total</span></div>'.($warehouses !== '' ? '<ul class="mt-2 space-y-1 border-t border-current/15 pt-2 text-xs [&_li]:flex [&_li]:justify-between [&_li_span]:font-semibold">'.$warehouses.'</ul>' : '<p class="mt-1 text-xs">No warehouse stock available.</p>').'</div>');
    }

    private function fulfillmentWarning(mixed $products): Htmlable
    {
        $unfulfilled = [];

        foreach ($this->stateArray($products) as $product) {
            if (! is_array($product)) {
                continue;
            }

            $variantId = $this->integer($product['product_variant_id'] ?? null);
            $quantity = $product['quantity'] ?? null;
            if ($variantId === null) {
                continue;
            }

            if (! is_numeric($quantity)) {
                continue;
            }

            if ($this->orderFulfillmentService->availability($variantId)['available_quantity'] + 0.0001 < (float) $quantity) {
                $unfulfilled[] = 'One or more selected products cannot be fully fulfilled with current stock.';
            }
        }

        if ($unfulfilled === []) {
            return new HtmlString('');
        }

        return new HtmlString('<div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-800 shadow-sm dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"><p class="font-semibold">Fulfillment needs attention</p><p class="mt-1 text-sm">'.e($unfulfilled[0]).'</p></div>');
    }

    private function warehouseRouteSummary(mixed $warehouseId, mixed $customerId): string
    {
        $warehouseId = $this->integer($warehouseId);
        $customerId = $this->integer($customerId);

        if ($warehouseId === null || $customerId === null) {
            return 'Select a warehouse to calculate its route.';
        }

        $routes = $this->orderFulfillmentService->routePreviews($this->selectedCustomer($customerId), [['warehouse_id' => $warehouseId, 'assignments' => []]]);
        $route = $routes[0] ?? null;

        if ($route === null) {
            return 'Route information is unavailable.';
        }

        if ($route['distance_km'] === null) {
            return ($route['warehouse_address'] ?? 'No warehouse address').' · Distance needs map coordinates.';
        }

        return ($route['warehouse_address'] ?? 'No warehouse address').' · '.number_format($route['distance_km'], 1).' km · about '.$route['estimated_minutes'].' min';
    }

    private function warehouseStockSummary(mixed $productVariantId, mixed $warehouseId): Htmlable
    {
        $productVariantId = $this->integer($productVariantId);
        $warehouseId = $this->integer($warehouseId);

        if ($productVariantId === null || $warehouseId === null) {
            return new HtmlString('Select a product and warehouse.');
        }

        $stock = collect($this->orderFulfillmentService->availability($productVariantId)['warehouses'])
            ->first(fn (array $warehouse): bool => $warehouse['id'] === $warehouseId);

        if (! is_array($stock)) {
            return new HtmlString('<div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><strong>Unavailable</strong><br><span class="text-xs">No available stock in this warehouse.</span></div>');
        }

        return new HtmlString('<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"><strong>Available</strong><br><span class="text-xs">'.number_format($stock['available_quantity'], 3).' in this warehouse</span></div>');
    }

    /** @param array<array-key, mixed> $shipments */
    private function routePreview(mixed $customerId, array $shipments): Htmlable
    {
        $customerId = $this->integer($customerId);

        if ($customerId === null || $shipments === []) {
            return new HtmlString('Complete the warehouse assignments to preview delivery routes.');
        }

        $customer = $this->selectedCustomer($customerId);

        return view('filament.orders.route-preview', [
            'customer' => $customer,
            'routes' => $this->orderFulfillmentService->routePreviews($customer, $shipments),
        ]);
    }

    private function selectedCustomer(mixed $customerId): CustomerProfile
    {
        $customerId = $this->integer($customerId);

        if ($customerId === null) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer before continuing.']);
        }

        return CustomerProfile::query()->where('is_active', true)->findOrFail($customerId);
    }

    /** @return array<array-key, mixed> */
    private function stateArray(mixed $state): array
    {
        return is_array($state) ? $state : [];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
