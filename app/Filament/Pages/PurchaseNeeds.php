<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Support\CurrencySelect;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Inventory\ReplenishmentRecommendationService;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Services\Purchasing\ReplenishmentPurchaseOrderDraftService;
use App\Services\Purchasing\SalesDemandProcurementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseNeeds extends Page
{
    use InteractsWithPurchasingServices;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected string $view = 'filament.pages.purchase-needs';

    public string $search = '';

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchase_needs');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.purchase_needs');
    }

    /** @return list<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createFromSalesDemand')
                ->label(__('Create from Sales demand'))
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('create', PurchaseOrder::class) ?? false)
                ->fillForm(fn (Action $action): array => [
                    'order_id' => $action->getArguments()['order_id'] ?? null,
                    'currency_code' => self::defaultCurrencyCode(),
                ])
                ->schema([
                    Select::make('order_id')
                        ->label(__('Sales Order'))
                        ->options(fn (): array => Order::query()
                            ->whereHas('procurementRequirements', static fn (Builder $query): Builder => $query
                                ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
                                ->whereNull('purchase_order_id'))
                            ->orderByDesc('id')
                            ->pluck('order_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    CurrencySelect::make('currency_code')
                        ->label(__('Purchase Order currency'))
                        ->default(fn (): string => self::defaultCurrencyCode())
                        ->live()
                        ->required(),
                    Select::make('supplier_id')
                        ->label(__('Supplier'))
                        ->options(function (Get $get): array {
                            $orderId = $get('order_id');
                            $currency = $get('currency_code');

                            if (! is_numeric($orderId)) {
                                return [];
                            }

                            $order = Order::query()->find((int) $orderId);

                            if (! $order instanceof Order) {
                                return [];
                            }

                            $supplierIds = app(SalesDemandProcurementService::class)->eligibleSupplierIds(
                                $order,
                                is_string($currency) ? $currency : null,
                            );

                            return Supplier::query()
                                ->whereIn('id', $supplierIds)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText(__('Only active suppliers with an active Supplier Product in the selected currency are shown.')),
                ])
                ->action(function (array $data): void {
                    $actor = self::purchasingActor();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $order = Order::query()->findOrFail(self::integerFrom($data['order_id'] ?? null));
                    $drafts = self::runPurchasingOperation(fn () => app(SalesDemandProcurementService::class)->createDrafts(
                        $actor,
                        $order,
                        self::integerFrom($data['supplier_id'] ?? null),
                        self::stringFrom($data['currency_code'] ?? null),
                    ));

                    Notification::make()
                        ->success()
                        ->title(__('Purchase Order drafts created'))
                        ->body(sprintf(
                            '%d draft(s) created from Sales Order %s: %s',
                            $drafts->count(),
                            $order->order_number,
                            $drafts->pluck('purchase_order_number')->implode(', '),
                        ))
                        ->send();
                }),
            Action::make('createFromReplenishment')
                ->label(__('Create from Replenishment'))
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('create', PurchaseOrder::class) ?? false)
                ->fillForm(function (Action $action): array {
                    $requirementId = $action->getArguments()['requirement_id'] ?? null;

                    return [
                        'requirement_ids' => is_numeric($requirementId) ? [(int) $requirementId] : [],
                    ];
                })
                ->schema([
                    Select::make('requirement_ids')
                        ->label(__('Replenishment recommendations'))
                        ->options(fn (): array => self::replenishmentRequirementOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText(__('Selected recommendations are grouped into Purchase Order drafts by supplier and currency. Warehouse allocation remains in the Purchase Inbound workflow.')),
                ])
                ->action(function (array $data): void {
                    $actor = self::purchasingActor();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $rawIds = is_array($data['requirement_ids'] ?? null) ? $data['requirement_ids'] : [];
                    $ids = array_values(array_map(
                        static fn (mixed $id): int => self::integerFrom($id),
                        $rawIds,
                    ));

                    $drafts = self::runPurchasingOperation(
                        fn () => app(ReplenishmentPurchaseOrderDraftService::class)->createDrafts($actor, $ids),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Purchase Order drafts created'))
                        ->body(sprintf(
                            '%d draft(s) created from replenishment: %s',
                            $drafts->count(),
                            $drafts->pluck('purchase_order_number')->implode(', '),
                        ))
                        ->send();
                }),
            Action::make('createPurchaseOrder')
                ->label(__('Create Purchase Order'))
                ->icon(Heroicon::Plus)
                ->color('gray')
                ->url(PurchaseOrderResource::getUrl('create')),
        ];
    }

    /**
     * A read-only projection over the source-domain requirements. No Purchasing
     * duplicate rows are created merely to display demand.
     *
     * @return list<array<string, int|string|null>>
     */
    public function needs(): array
    {
        $sales = SalesProcurementRequirement::query()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->with([
                'order:id,order_number',
                'productVariant.media',
                'productVariant.product:id,name',
                'productVariant.product.media',
                'destinationWarehouse:id,name',
                'purchaseOrder:id,purchase_order_number',
            ])
            ->orderBy('id')
            ->get();

        $replenishment = ReplenishmentRequirement::query()
            ->active()
            ->with([
                'policy',
                'productVariant.media',
                'productVariant.product:id,name',
                'productVariant.product.media',
                'warehouse:id,name',
            ])
            ->orderBy('id')
            ->get();

        $variantIds = $sales->pluck('product_variant_id')
            ->merge($replenishment->pluck('product_variant_id'))
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $supplierCounts = self::eligibleSupplierCounts(array_values($variantIds->all()));

        $rows = [];

        foreach ($sales as $requirement) {
            $rows[] = [
                'source' => 'Sales Order',
                'source_reference' => $requirement->order->order_number ?? '—',
                'source_url' => OrderResource::getUrl('view', ['record' => $requirement->order]),
                'product' => $requirement->productVariant->product->name ?? $requirement->productVariant->name ?? '—',
                'sku' => $requirement->productVariant->sku ?? '—',
                'image' => $requirement->productVariant?->mainImageUrl(),
                'warehouse' => $requirement->destinationWarehouse->name ?? 'Not assigned',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->fulfilled_base_quantity,
                'remaining' => $requirement->outstandingBaseQuantity(),
                'linked_po' => $requirement->purchaseOrder?->purchase_order_number,
                'linked_po_url' => $requirement->purchaseOrder instanceof PurchaseOrder
                    ? PurchaseOrderResource::getUrl('view', ['record' => $requirement->purchaseOrder])
                    : null,
                'status' => (string) $requirement->status,
                'supplier_count' => $supplierCounts[$requirement->product_variant_id] ?? 0,
                'sales_order_id' => $requirement->order_id,
                'replenishment_requirement_id' => null,
                'available' => null,
                'reserved' => null,
                'incoming' => null,
                'minimum' => null,
                'maximum' => null,
                'suggested_quantity' => null,
                'suggested_supplier' => null,
                'lead_time_days' => null,
                'next_action' => $requirement->purchaseOrder instanceof PurchaseOrder
                    ? 'Review linked Purchase Order'
                    : (($supplierCounts[$requirement->product_variant_id] ?? 0) > 0
                        ? 'Create Purchase Order'
                        : 'Add Supplier Product'),
                'next_action_type' => $requirement->purchaseOrder instanceof PurchaseOrder
                    ? 'link'
                    : (($supplierCounts[$requirement->product_variant_id] ?? 0) > 0 ? 'sales_create' : 'link'),
                'next_action_url' => $requirement->purchaseOrder instanceof PurchaseOrder
                    ? PurchaseOrderResource::getUrl('view', ['record' => $requirement->purchaseOrder])
                    : (($supplierCounts[$requirement->product_variant_id] ?? 0) > 0
                        ? null
                        : SupplierProductReferenceResource::getUrl('index')),
            ];
        }

        $transferSuggestions = app(ReplenishmentTransferSuggestionService::class);
        $recommendations = app(ReplenishmentRecommendationService::class);

        foreach ($replenishment as $requirement) {
            $transferQuantity = 0.0;

            foreach ($transferSuggestions->suggest($requirement) as $suggestion) {
                $transferQuantity += $suggestion->suggestedBaseQuantity;
            }

            $purchaseRemaining = max(
                0.0,
                round($requirement->remainingUncoveredQuantity() - $transferQuantity, 6),
            );
            $recommendation = $requirement->policy !== null
                ? $recommendations->recommendation($requirement->policy)
                : null;

            if ($purchaseRemaining <= 0.0) {
                continue;
            }

            $rows[] = [
                'source' => 'Inventory Replenishment',
                'source_reference' => 'REQ-'.$requirement->id,
                'source_url' => null,
                'product' => $requirement->productVariant->product->name ?? $requirement->productVariant->name ?? '—',
                'sku' => $requirement->productVariant->sku ?? '—',
                'image' => $requirement->productVariant?->mainImageUrl(),
                'warehouse' => $requirement->warehouse->name ?? '—',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->covered_base_quantity,
                'remaining' => number_format($purchaseRemaining, 6, '.', ''),
                'linked_po' => null,
                'linked_po_url' => null,
                'status' => $requirement->status->value,
                'supplier_count' => $supplierCounts[$requirement->product_variant_id] ?? 0,
                'sales_order_id' => null,
                'replenishment_requirement_id' => $requirement->getKey(),
                'available' => $recommendation?->available,
                'reserved' => $recommendation?->reserved,
                'incoming' => $recommendation?->incoming,
                'minimum' => $recommendation?->minimum,
                'maximum' => $recommendation?->maximum,
                'suggested_quantity' => $recommendation?->suggestedBaseQuantity,
                'suggested_supplier' => $recommendation?->supplierName,
                'lead_time_days' => $recommendation?->leadTimeDays,
                'next_action' => ($supplierCounts[$requirement->product_variant_id] ?? 0) > 0
                    ? 'Create PO draft'
                    : 'Add Supplier Product',
                'next_action_type' => ($supplierCounts[$requirement->product_variant_id] ?? 0) > 0
                    ? 'replenishment_create'
                    : 'link',
                'next_action_url' => ($supplierCounts[$requirement->product_variant_id] ?? 0) > 0
                    ? null
                    : SupplierProductReferenceResource::getUrl('index'),
            ];
        }

        $search = mb_strtolower(mb_trim($this->search));

        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['source'],
                    $row['source_reference'],
                    $row['product'],
                    $row['sku'],
                    $row['warehouse'],
                    $row['linked_po'] ?? null,
                    $row['status'],
                ], is_scalar(...))));

                return str_contains($haystack, $search);
            },
        ));
    }

    /** @return array<int, string> */
    private static function replenishmentRequirementOptions(): array
    {
        $requirements = ReplenishmentRequirement::query()
            ->active()
            ->with([
                'policy',
                'warehouse:id,name',
                'productVariant:id,product_id,sku,name',
                'productVariant.product:id,name',
            ])
            ->orderBy('id')
            ->get();
        $recommendations = app(ReplenishmentRecommendationService::class);
        $transfers = app(ReplenishmentTransferSuggestionService::class);
        $options = [];

        foreach ($requirements as $requirement) {
            $policy = $requirement->policy;

            if ($policy === null) {
                continue;
            }

            $transferQuantity = 0.0;

            foreach ($transfers->suggest($requirement) as $suggestion) {
                $transferQuantity += $suggestion->suggestedBaseQuantity;
            }

            $purchaseQuantity = max(0.0, round($requirement->remainingUncoveredQuantity() - $transferQuantity, 6));

            if ($purchaseQuantity <= 0.000001) {
                continue;
            }

            $recommendation = $recommendations->recommendation($policy);

            if ($recommendation->supplierId === null || $recommendation->currencyCode === null) {
                continue;
            }

            $product = $requirement->productVariant?->product?->name
                ?? $requirement->productVariant?->name
                ?? 'Product';
            $sku = $requirement->productVariant?->sku ?? '—';
            $warehouse = $requirement->warehouse?->name ?? 'Warehouse';

            $options[(int) $requirement->getKey()] = sprintf(
                'REQ-%d · %s (%s) · %s · %.3f · %s',
                (int) $requirement->getKey(),
                $product,
                $sku,
                $warehouse,
                $purchaseQuantity,
                $recommendation->supplierName ?? 'Supplier',
            );
        }

        return $options;
    }

    private static function defaultCurrencyCode(): string
    {
        $code = Currency::query()
            ->where('is_default', true)
            ->value('code');

        return is_string($code) && $code !== ''
            ? mb_strtoupper($code)
            : 'AED';
    }

    /**
     * Supplier Products are the single sourcing truth exposed to buyers.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    private static function eligibleSupplierCounts(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $references = SupplierProductReference::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('availability_status', 'active')
            ->where('is_active', true)
            ->currentlyValid()
            ->whereHas('supplier', static fn (Builder $query): Builder => $query->where('is_active', true))
            ->get(['supplier_id', 'product_variant_id']);

        /** @var array<int, array<int, true>> $eligible */
        $eligible = [];

        foreach ($references as $reference) {
            $eligible[$reference->product_variant_id][$reference->supplier_id] = true;
        }

        $counts = [];

        foreach ($variantIds as $variantId) {
            $counts[$variantId] = count($eligible[$variantId] ?? []);
        }

        return $counts;
    }
}
