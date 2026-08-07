<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\DeliveryDocumentSynchronizer;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Orders\DeliveryTypeResolver;
use App\Services\Orders\OrderFulfillmentService;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Locale;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateInventoryOperation extends CreateRecord
{
    use HasWizard {
        form as private wizardForm;
    }

    protected static string $resource = InventoryOperationResource::class;

    public bool $isContextualDelivery = false;

    private OrderFulfillmentService $orderFulfillmentService;

    private DeliveryTypeResolver $deliveryTypeResolver;

    private InventoryOperationService $inventoryOperationService;

    public function boot(
        OrderFulfillmentService $orderFulfillmentService,
        DeliveryTypeResolver $deliveryTypeResolver,
        InventoryOperationService $inventoryOperationService,
    ): void {
        $this->orderFulfillmentService = $orderFulfillmentService;
        $this->deliveryTypeResolver = $deliveryTypeResolver;
        $this->inventoryOperationService = $inventoryOperationService;
    }

    #[\Override]
    public function mount(): void
    {
        $operationType = $this->forcedOperationType();
        $this->isContextualDelivery = $operationType === OperationType::Delivery;

        parent::mount();
    }

    #[\Override]
    public function form(Schema $schema): Schema
    {
        if ($this->isDeliveryCreation()) {
            return $this->wizardForm($schema);
        }

        return parent::form($schema);
    }

    #[\Override]
    public function hasFormWrapper(): bool
    {
        if ($this->isDeliveryCreation()) {
            return false;
        }

        return parent::hasFormWrapper();
    }

    /** @return array<Step> */
    protected function getSteps(): array
    {
        return [
            Step::make('Delivery Information')
                ->description('Choose an active customer with delivery coordinates.')
                ->icon(Heroicon::OutlinedMapPin)
                ->schema([
                    Section::make()
                        ->columns(2)
                        ->schema([
                            Hidden::make('operation_type')->default(OperationType::Delivery->value),
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
                            DateTimePicker::make('scheduled_at'),
                            Select::make('responsible_id')
                                ->relationship('responsible', 'name')
                                ->searchable()
                                ->preload(),
                            Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
                            Section::make('Delivery documents')
                                ->description('Upload the documents required for this delivery.')
                                ->columns(2)
                                ->schema(array_map(
                                    self::deliveryDocumentUpload(...),
                                    DeliveryDocument::cases(),
                                ))
                                ->columnSpanFull(),
                        ]),
                ]),
            Step::make('Warehouse Allocation')
                ->description('Add warehouse shipments, products, variants, and quantities, then create the delivery.')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->schema([
                    View::make('filament.inventory-operations.delivery-customer-map')
                        ->viewData(fn (Get $get): array => $this->deliveryCustomerMapData(
                            $get('customer_id'),
                            $get('shipments'),
                        ))
                        ->columnSpanFull(),
                    Repeater::make('shipments')
                        ->minItems(1)
                        ->required()
                        ->collapsed()
                        ->reorderable(false)
                        ->schema([
                            Hidden::make('delivery_type')->default(DeliveryType::Inner->value)->dehydrated(),
                            Select::make('warehouse_id')
                                ->label('Warehouse')
                                ->options(fn (Get $get): array => $this->warehouseOptions($get('../assignments')))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set('delivery_type', $this->autoDetectedDeliveryType($get)->value)),
                            Placeholder::make('warehouse_address')
                                ->label('Warehouse address')
                                ->content(fn (Get $get): string => $this->warehouseAddress($get('warehouse_id')) ?? 'No address on file.')
                                ->visible(fn (Get $get): bool => $this->integer($get('warehouse_id')) !== null),
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
                            Placeholder::make('stock_warning')
                                ->hiddenLabel()
                                ->content(fn (Get $get): HtmlString => $this->stockWarning($get))
                                ->visible(fn (Get $get): bool => $this->hasStockWarning($get)),
                            Repeater::make('assignments')
                                ->minItems(1)
                                ->required()
                                ->reorderable(false)
                                ->schema([
                                    Select::make('product_id')
                                        ->label('Product')
                                        ->options(fn (Get $get): array => $this->productOptions($get('../../warehouse_id')))
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, Get $get, mixed $state): mixed => $set('product_variant_id', $this->singleVariantId($state, $get('../../warehouse_id')))),
                                    Select::make('product_variant_id')
                                        ->label('Product variant')
                                        ->options(fn (Get $get): array => $this->variantsForProduct($get('product_id'), $get('../../warehouse_id')))
                                        ->searchable()
                                        ->preload()
                                        ->visible(fn (Get $get): bool => $this->hasMultipleVariants($get('product_id'), $get('../../warehouse_id')))
                                        ->required(fn (Get $get): bool => $this->hasMultipleVariants($get('product_id'), $get('../../warehouse_id')))
                                        ->dehydrated(true)
                                        ->live(),
                                    TextInput::make('quantity')
                                        ->numeric()
                                        ->placeholder(fn (Get $get): string => $this->quantityPlaceholder($get))
                                        ->minValue(0.001)
                                        ->maxValue(fn (Get $get): ?float => $this->availableQuantity(
                                            $get('product_variant_id') ?? $this->singleVariantId($get('product_id'), $get('../../warehouse_id')),
                                            $get('../../warehouse_id'),
                                        ))
                                        ->validationMessages(['max' => 'Quantity cannot exceed the available stock.'])
                                        ->required()
                                        ->live(),
                                ])
                                ->columns(3),
                        ])
                        ->columnSpanFull(),
                ]),
        ];
    }

    #[\Override]
    public function getTitle(): string
    {
        $operationType = $this->forcedOperationType();

        if ($this->isContextualDelivery) {
            return 'Create Delivery';
        }

        return $operationType instanceof OperationType
            ? 'Create '.$operationType->label()
            : 'Create Inventory Operation';
    }

    #[\Override]
    protected function authorizeAccess(): void
    {
        $operationType = $this->forcedOperationType();

        if (! $operationType instanceof OperationType) {
            parent::authorizeAccess();

            return;
        }

        abort_unless(
            auth()->user()?->can('createType', [InventoryOperation::class, $operationType]) ?? false,
            403,
        );
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $operationType = $this->forcedOperationType();

        if ($this->isContextualDelivery) {
            $data['operation_type'] = OperationType::Delivery->value;
            $data['shipments'] = $this->normalizedShipments($this->stateArray($data['shipments'] ?? null));
            $data['products'] = $this->productsFromShipments($data['shipments']);
        } elseif ($operationType instanceof OperationType) {
            $data['operation_type'] = $operationType->value;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        if ($this->isDeliveryCreation()) {
            return $this->createDeliveryGroup($data);
        }

        $documents = $this->extractDeliveryDocuments($data);
        $record = InventoryOperation::query()->create($data);
        $synchronizer = app(DeliveryDocumentSynchronizer::class);

        foreach ($documents as $collection => $path) {
            $synchronizer->sync($record, $collection, $path);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function extractDeliveryDocuments(array &$data): array
    {
        $documents = [];

        foreach (DeliveryDocument::cases() as $document) {
            $value = $data[$document->value] ?? null;
            unset($data[$document->value]);

            if (is_array($value)) {
                $value = array_values(array_filter($value, is_string(...)))[0] ?? null;
            }

            if (is_string($value)) {
                $documents[$document->value] = $value;
            }
        }

        return $documents;
    }

    private function forcedOperationType(): ?OperationType
    {
        $value = request()->query('operation_type');

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ($operationType = OperationType::tryFrom($value)) === null) {
            throw new NotFoundHttpException;
        }

        return $operationType;
    }

    /** @param array<string, mixed> $data */
    private function createDeliveryGroup(array $data): InventoryOperation
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new NotFoundHttpException;
        }

        $customerId = $this->integer($data['customer_id'] ?? null);

        if ($customerId === null) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
        }

        $customer = CustomerProfile::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->find($customerId);

        if (! $customer instanceof CustomerProfile) {
            throw ValidationException::withMessages(['customer_id' => 'The selected customer needs delivery coordinates.']);
        }

        $responsibleId = $this->integer($data['responsible_id'] ?? null);
        $responsible = $responsibleId === null ? null : User::query()->find($responsibleId);

        if ($responsibleId !== null && ! $responsible instanceof User) {
            throw ValidationException::withMessages(['responsible_id' => 'Select the responsible user.']);
        }

        $scheduledAtValue = $data['scheduled_at'] ?? null;

        if ($scheduledAtValue !== null && ! is_string($scheduledAtValue)) {
            throw ValidationException::withMessages(['scheduled_at' => 'Select a valid delivery schedule.']);
        }

        $scheduledAt = is_string($scheduledAtValue) && filled($scheduledAtValue)
            ? Carbon::parse($scheduledAtValue)
            : null;

        $documents = $this->extractDeliveryDocuments($data);

        $shipments = $this->normalizedShipments($this->stateArray($data['shipments'] ?? null));

        $order = $this->orderFulfillmentService->create(new OrderFulfillmentData(
            customer: $customer,
            products: $this->productsFromShipments($shipments),
            shipments: $shipments,
            actor: $actor,
            notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
            documents: $documents,
            scheduledAt: $scheduledAt,
            responsible: $responsible,
        ));

        $delivery = $order->deliveries()->orderBy('id')->first();

        if (! $delivery instanceof InventoryOperation) {
            throw new LogicException('The delivery group did not create a child delivery.');
        }

        return $delivery;
    }

    /**
     * @return array{
     *     customerName: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     location: string|null,
     *     warehouses: list<array{id: int, name: string, latitude: float, longitude: float}>,
     *     warehouseOptions: list<array{id: int, name: string, latitude: float, longitude: float}>,
     *     routingServiceUrl: string,
     * }
     */
    private function deliveryCustomerMapData(mixed $customerId, mixed $shipments): array
    {
        $customerId = $this->integer($customerId);
        $customer = $customerId === null
            ? null
            : CustomerProfile::query()->where('is_active', true)->find($customerId);
        $warehouseIds = $this->selectedWarehouseIds($shipments);
        $warehouseOptions = $this->deliveryMapWarehouseOptions();
        $warehouses = $this->selectedDeliveryMapWarehouses($warehouseIds, $warehouseOptions);

        if (! $customer instanceof CustomerProfile) {
            return [
                'customerName' => null,
                'latitude' => null,
                'longitude' => null,
                'location' => null,
                'warehouses' => $warehouses,
                'warehouseOptions' => $warehouseOptions,
                'routingServiceUrl' => $this->routingServiceUrl(),
            ];
        }

        $latitude = is_numeric($customer->latitude) ? (float) $customer->latitude : null;
        $longitude = is_numeric($customer->longitude) ? (float) $customer->longitude : null;

        return [
            'customerName' => $customer->company_name ?? 'Customer',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location' => $this->customerLocationSummary($customer),
            'warehouses' => $warehouses,
            'warehouseOptions' => $warehouseOptions,
            'routingServiceUrl' => $this->routingServiceUrl(),
        ];
    }

    /**
     * Joins the customer's address, city, and country into one line, skipping any part already
     * mentioned by an earlier part — customer addresses are often typed as a full sentence that
     * already names the city and country, and a country code like "AE" is expanded to its name
     * first so it can be recognised as a duplicate too.
     */
    private function customerLocationSummary(CustomerProfile $customer): ?string
    {
        $parts = [];

        foreach ([$customer->address, $customer->city, $this->displayCountryName($customer->country)] as $part) {
            if (! is_string($part)) {
                continue;
            }

            if ($part === '') {
                continue;
            }

            $alreadyMentioned = collect($parts)->contains(
                static fn (string $existing): bool => str_contains(mb_strtolower($existing), mb_strtolower($part)),
            );

            if (! $alreadyMentioned) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function displayCountryName(?string $country): ?string
    {
        if (! is_string($country) || $country === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            return $country;
        }

        $normalizedCountry = mb_strtoupper($country);
        $name = Locale::getDisplayRegion('-'.$normalizedCountry, 'en');

        if (! is_string($name) || $name === '' || $name === $normalizedCountry) {
            return $country;
        }

        return $name;
    }

    /** @return list<int> */
    private function selectedWarehouseIds(mixed $shipments): array
    {
        $warehouseIds = [];

        foreach ($this->stateArray($shipments) as $shipment) {
            $warehouseId = is_array($shipment)
                ? $this->integer($shipment['warehouse_id'] ?? null)
                : null;

            if ($warehouseId !== null && ! in_array($warehouseId, $warehouseIds, true)) {
                $warehouseIds[] = $warehouseId;
            }
        }

        return $warehouseIds;
    }

    /**
     * @return list<array{id: int, name: string, latitude: float, longitude: float}>
     */
    private function deliveryMapWarehouseOptions(): array
    {
        $warehouseOptions = [];
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'latitude', 'longitude']);

        foreach ($warehouses as $warehouse) {
            if (! is_numeric($warehouse->latitude)) {
                continue;
            }

            if (! is_numeric($warehouse->longitude)) {
                continue;
            }

            $warehouseOptions[] = [
                'id' => self::modelKey($warehouse),
                'name' => $warehouse->name,
                'latitude' => (float) $warehouse->latitude,
                'longitude' => (float) $warehouse->longitude,
            ];
        }

        return $warehouseOptions;
    }

    /**
     * @param  list<int>  $warehouseIds
     * @param  list<array{id: int, name: string, latitude: float, longitude: float}>  $warehouseOptions
     * @return list<array{id: int, name: string, latitude: float, longitude: float}>
     */
    private function selectedDeliveryMapWarehouses(array $warehouseIds, array $warehouseOptions): array
    {
        $warehouseOptionsById = [];

        foreach ($warehouseOptions as $warehouseOption) {
            $warehouseOptionsById[$warehouseOption['id']] = $warehouseOption;
        }

        $warehouses = [];

        foreach ($warehouseIds as $warehouseId) {
            if (isset($warehouseOptionsById[$warehouseId])) {
                $warehouses[] = $warehouseOptionsById[$warehouseId];
            }
        }

        return $warehouses;
    }

    private function routingServiceUrl(): string
    {
        $routingServiceUrl = config('services.osrm.url');

        if (! is_string($routingServiceUrl) || $routingServiceUrl === '') {
            throw new LogicException('The OSRM routing service URL must be configured.');
        }

        return $routingServiceUrl;
    }

    /** @return array<int, string> */
    private function productOptions(mixed $warehouseId): array
    {
        $warehouseId = $this->integer($warehouseId);

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true));

        if ($warehouseId !== null) {
            $variants->whereHas('stocks', fn (Builder $query): Builder => $query
                ->where('warehouse_id', $warehouseId)
                ->where('available_quantity', '>', 0));
        }

        $variants = $variants
            ->with('product:id,name')
            ->get(['id', 'product_id']);
        $options = [];

        foreach ($variants as $variant) {
            $productName = $variant->product?->name;
            if (! is_string($productName)) {
                continue;
            }

            if (array_key_exists($variant->product_id, $options)) {
                continue;
            }

            $options[$variant->product_id] = $productName;
        }

        return $options;
    }

    /** @return array<int, string> */
    private function warehouseOptions(mixed $assignments): array
    {
        $productIds = collect($this->stateArray($assignments))
            ->filter(static fn (mixed $assignment): bool => is_array($assignment))
            ->map(fn (array $assignment): ?int => $this->integer($assignment['product_id'] ?? null))
            ->filter()
            ->unique()
            ->values();
        $warehouses = Warehouse::query()->where('is_active', true);

        if ($productIds->isNotEmpty()) {
            $warehouses->whereHas('stocks', fn (Builder $stocks): Builder => $stocks
                ->where('available_quantity', '>', 0)
                ->whereHas('productVariant', fn (Builder $variants): Builder => $variants->whereIn('product_id', $productIds)));
        }

        $options = [];

        foreach ($warehouses->orderBy('name')->get(['id', 'name']) as $warehouse) {
            $options[self::modelKey($warehouse)] = $warehouse->name;
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @return list<array{product_variant_id: int, quantity: float}>
     */
    private function productsFromShipments(array $shipments): array
    {
        $products = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            if (! is_array($shipment['assignments'] ?? null)) {
                continue;
            }

            foreach ($shipment['assignments'] as $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $variantId = $this->integer($assignment['product_variant_id'] ?? null)
                    ?? $this->singleVariantId($assignment['product_id'] ?? null);
                $quantity = $assignment['quantity'] ?? null;
                if ($variantId === null) {
                    continue;
                }

                if (! is_numeric($quantity)) {
                    continue;
                }

                $products[$variantId] = [
                    'product_variant_id' => $variantId,
                    'quantity' => (float) ($products[$variantId]['quantity'] ?? 0) + (float) $quantity,
                ];
            }
        }

        return array_values($products);
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @return array<array-key, mixed>
     */
    private function normalizedShipments(array $shipments): array
    {
        foreach ($shipments as $shipmentKey => $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            $shipment['delivery_type'] = $this->resolvedDeliveryType($shipment['delivery_type'] ?? null)->value;

            $assignments = $shipment['assignments'] ?? null;

            if (is_array($assignments)) {
                foreach ($assignments as $assignmentKey => $assignment) {
                    if (! is_array($assignment)) {
                        continue;
                    }

                    if ($this->integer($assignment['product_variant_id'] ?? null) !== null) {
                        continue;
                    }

                    $variantId = $this->singleVariantId($assignment['product_id'] ?? null);

                    if ($variantId !== null) {
                        $assignment['product_variant_id'] = $variantId;
                        $assignments[$assignmentKey] = $assignment;
                    }
                }

                $shipment['assignments'] = $assignments;
            }

            $shipments[$shipmentKey] = $shipment;
        }

        return $shipments;
    }

    private function resolvedDeliveryType(mixed $value): DeliveryType
    {
        return is_string($value) ? (DeliveryType::tryFrom($value) ?? DeliveryType::Inner) : DeliveryType::Inner;
    }

    /**
     * Classifies a shipment as {@see DeliveryType::Outer} whenever the driving route between the
     * chosen warehouse and the customer leaves the UAE, so the wizard never asks the operator to
     * make that call themselves.
     */
    private function autoDetectedDeliveryType(Get $get): DeliveryType
    {
        $warehouseId = $this->integer($get('warehouse_id'));
        $customerId = $this->integer($get('../../customer_id'));

        $warehouse = $warehouseId === null ? null : Warehouse::query()->find($warehouseId, ['latitude', 'longitude']);
        $customer = $customerId === null ? null : CustomerProfile::query()->find($customerId, ['latitude', 'longitude']);

        if (! $warehouse instanceof Warehouse || ! $customer instanceof CustomerProfile
            || ! is_numeric($warehouse->latitude) || ! is_numeric($warehouse->longitude)
            || ! is_numeric($customer->latitude) || ! is_numeric($customer->longitude)) {
            return DeliveryType::Inner;
        }

        return $this->deliveryTypeResolver->resolve(
            (float) $warehouse->latitude,
            (float) $warehouse->longitude,
            (float) $customer->latitude,
            (float) $customer->longitude,
        );
    }

    private function warehouseAddress(mixed $warehouseId): ?string
    {
        $warehouseId = $this->integer($warehouseId);

        if ($warehouseId === null) {
            return null;
        }

        $address = Warehouse::query()->whereKey($warehouseId)->value('address');

        return is_string($address) && $address !== '' ? $address : null;
    }

    private function hasStockWarning(Get $get): bool
    {
        return $this->stockWarnings($get) !== [];
    }

    private function stockWarning(Get $get): HtmlString
    {
        $items = array_map(
            static fn (array $warning): string => sprintf(
                '<li>%s — requested %s, available %s</li>',
                e($warning['name']),
                e(number_format($warning['requested'], 3)),
                e(number_format($warning['available'], 3)),
            ),
            $this->stockWarnings($get),
        );

        return new HtmlString(
            '<div class="flex items-start gap-2 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-400">'
            .'<svg class="mt-0.5 h-5 w-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>'
            .'<div><p class="font-medium">Not enough stock</p><ul class="mt-1 list-disc pl-4">'.implode('', $items).'</ul></div>'
            .'</div>',
        );
    }

    /**
     * Only counts assignment rows that already name a product variant with a positive quantity,
     * so the warning appears once there is something concrete to warn about — not while the row
     * is still being filled in.
     *
     * @return list<array{name: string, requested: float, available: float}>
     */
    private function stockWarnings(Get $get): array
    {
        $warehouseId = $this->integer($get('warehouse_id'));

        if ($warehouseId === null) {
            return [];
        }

        $candidates = collect($this->stateArray($get('assignments')))
            ->filter(static fn (mixed $assignment): bool => is_array($assignment))
            ->map(
                /** @return array{variant_id: int, requested: float}|null */
                function (array $assignment): ?array {
                    $variantId = $this->integer($assignment['product_variant_id'] ?? null);
                    $requested = is_numeric($assignment['quantity'] ?? null) ? (float) $assignment['quantity'] : 0.0;

                    return $variantId === null || $requested <= 0.0
                        ? null
                        : ['variant_id' => $variantId, 'requested' => $requested];
                },
            )
            ->filter()
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $variantIds = array_values($candidates
            ->map(static fn (array $candidate): int => $candidate['variant_id'])
            ->all());

        $stocks = $this->inventoryOperationService->availableQuantitiesFor($variantIds, $warehouseId);
        $warnings = [];

        foreach ($candidates as $candidate) {
            $stock = $stocks[$candidate['variant_id']] ?? null;

            if ($stock === null) {
                $warnings[] = [
                    'name' => 'Selected product variant',
                    'requested' => $candidate['requested'],
                    'available' => 0.0,
                ];

                continue;
            }

            if ($candidate['requested'] <= $stock['available_quantity']) {
                continue;
            }

            $warnings[] = [
                'name' => $stock['variant_name'] ?? 'Selected product variant',
                'requested' => $candidate['requested'],
                'available' => $stock['available_quantity'],
            ];
        }

        return $warnings;
    }

    /** @return array<int, string> */
    private function variantsForProduct(mixed $productId, mixed $warehouseId = null): array
    {
        $productId = $this->integer($productId);
        $warehouseId = $this->integer($warehouseId);

        if ($productId === null) {
            return [];
        }

        $query = ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_active', true);

        if ($warehouseId !== null) {
            $query->whereHas('stocks', fn (Builder $stockQuery): Builder => $stockQuery
                ->where('warehouse_id', $warehouseId)
                ->where('available_quantity', '>', 0));
        }

        return $query->orderBy('sku')->get(['id', 'name', 'sku'])
            ->mapWithKeys(static fn (ProductVariant $variant): array => [
                self::modelKey($variant) => sprintf('%s (%s)', $variant->name, $variant->sku),
            ])
            ->all();
    }

    private function hasMultipleVariants(mixed $productId, mixed $warehouseId = null): bool
    {
        $productId = $this->integer($productId);
        $warehouseId = $this->integer($warehouseId);

        if ($productId === null) {
            return false;
        }

        $query = ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_active', true);

        if ($warehouseId !== null) {
            $query->whereHas('stocks', fn (Builder $stockQuery): Builder => $stockQuery
                ->where('warehouse_id', $warehouseId)
                ->where('available_quantity', '>', 0));
        }

        return $query->count() > 1;
    }

    private function singleVariantId(mixed $productId, mixed $warehouseId = null): ?int
    {
        $variantIds = array_keys($this->variantsForProduct($productId, $warehouseId));

        return count($variantIds) === 1 ? $variantIds[0] : null;
    }

    private function quantityPlaceholder(Get $get): string
    {
        $warehouseId = $get('../../warehouse_id');
        $variantId = $get('product_variant_id') ?? $this->singleVariantId($get('product_id'), $warehouseId);
        $variantId = $this->integer($variantId);

        $warehouseId = $this->integer($warehouseId);

        if ($variantId === null || $warehouseId === null) {
            return 'Select a product to see available quantity.';
        }

        $availableQuantity = $this->availableQuantity($variantId, $warehouseId);

        if ($availableQuantity === null) {
            return 'No available stock.';
        }

        $formattedQuantity = mb_rtrim(mb_rtrim(number_format($availableQuantity, 3, '.', ''), '0'), '.');

        return 'Available: '.$formattedQuantity;
    }

    private function availableQuantity(mixed $variantId, mixed $warehouseId): ?float
    {
        $variantId = $this->integer($variantId);
        $warehouseId = $this->integer($warehouseId);

        if ($variantId === null || $warehouseId === null) {
            return null;
        }

        return $this->inventoryOperationService->availableQuantity($variantId, $warehouseId);
    }

    /** @return array<array-key, mixed> */
    private function stateArray(mixed $state): array
    {
        return is_array($state) ? $state : [];
    }

    private function isDeliveryCreation(): bool
    {
        return $this->isContextualDelivery;
    }

    private static function deliveryDocumentUpload(DeliveryDocument $document): FileUpload
    {
        return FileUpload::make($document->value)
            ->label($document->label())
            ->multiple()
            ->maxFiles(1)
            ->disk('local')
            ->directory('delivery-documents/'.$document->value)
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120);
    }

    protected function hasSkippableSteps(): bool
    {
        return $this->isDeliveryCreation();
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private static function modelKey(Model $model): int
    {
        $key = $model->getKey();

        if (is_int($key)) {
            return $key;
        }

        if (is_string($key) && ctype_digit($key)) {
            return (int) $key;
        }

        throw new LogicException('The delivery wizard requires an integer model key.');
    }
}
