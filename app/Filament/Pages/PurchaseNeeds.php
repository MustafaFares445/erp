<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\CurrencySelect;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
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

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected string $view = 'filament.pages.purchase-needs';

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
                ->label('Create from Sales demand')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('create', PurchaseOrder::class) ?? false)
                ->schema([
                    Select::make('order_id')
                        ->label('Sales Order')
                        ->options(fn (): array => Order::query()
                            ->whereHas('procurementRequirements', static fn (Builder $query): Builder => $query
                                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                                ->whereNull('purchase_order_id'))
                            ->orderByDesc('id')
                            ->pluck('order_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    CurrencySelect::make('currency_code')
                        ->label('Purchase Order currency')
                        ->default(fn (): string => (string) (Currency::query()
                            ->where('is_default', true)
                            ->value('code') ?? 'AED'))
                        ->live()
                        ->required(),
                    Select::make('supplier_id')
                        ->label('Supplier')
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
                        ->helperText('Only active suppliers with capability and an active commercial reference in the selected currency are shown.'),
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
                        ->title('Purchase Order drafts created')
                        ->body(sprintf(
                            '%d draft(s) created from Sales Order %s: %s',
                            $drafts->count(),
                            $order->order_number,
                            $drafts->pluck('purchase_order_number')->implode(', '),
                        ))
                        ->send();
                }),
            Action::make('createPurchaseOrder')
                ->label('Create Purchase Order')
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
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->with([
                'order:id,order_number',
                'productVariant.product:id,name',
                'destinationWarehouse:id,name',
                'purchaseOrder:id,purchase_order_number',
            ])
            ->orderBy('id')
            ->get();

        $replenishment = ReplenishmentRequirement::query()
            ->active()
            ->with([
                'productVariant.product:id,name',
                'warehouse:id,name',
            ])
            ->orderBy('id')
            ->get();

        $variantIds = $sales->pluck('product_variant_id')
            ->merge($replenishment->pluck('product_variant_id'))
            ->unique()
            ->values();

        $supplierCounts = SupplierProductReference::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('is_active', true)
            ->whereHas('supplier', static fn (Builder $query): Builder => $query->where('is_active', true))
            ->selectRaw('product_variant_id, COUNT(*) AS supplier_count')
            ->groupBy('product_variant_id')
            ->pluck('supplier_count', 'product_variant_id');

        $rows = [];

        foreach ($sales as $requirement) {
            $rows[] = [
                'source' => 'Sales Order',
                'source_reference' => $requirement->order->order_number ?? '—',
                'product' => $requirement->productVariant->product->name ?? $requirement->productVariant->name ?? '—',
                'sku' => $requirement->productVariant->sku ?? '—',
                'warehouse' => $requirement->destinationWarehouse->name ?? 'Not assigned',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->fulfilled_base_quantity,
                'remaining' => $requirement->outstandingBaseQuantity(),
                'linked_po' => $requirement->purchaseOrder?->purchase_order_number,
                'status' => (string) $requirement->status,
                'supplier_count' => self::supplierCount($supplierCounts->get($requirement->product_variant_id)),
            ];
        }

        $transferSuggestions = app(ReplenishmentTransferSuggestionService::class);

        foreach ($replenishment as $requirement) {
            $transferQuantity = 0.0;

            foreach ($transferSuggestions->suggest($requirement) as $suggestion) {
                $transferQuantity += $suggestion->suggestedBaseQuantity;
            }

            $purchaseRemaining = max(
                0.0,
                round($requirement->remainingUncoveredQuantity() - $transferQuantity, 6),
            );

            if ($purchaseRemaining <= 0.0) {
                continue;
            }

            $rows[] = [
                'source' => 'Inventory Replenishment',
                'source_reference' => 'REQ-'.$requirement->id,
                'product' => $requirement->productVariant->product->name ?? $requirement->productVariant->name ?? '—',
                'sku' => $requirement->productVariant->sku ?? '—',
                'warehouse' => $requirement->warehouse->name ?? '—',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->covered_base_quantity,
                'remaining' => number_format($purchaseRemaining, 6, '.', ''),
                'linked_po' => null,
                'status' => $requirement->status->value,
                'supplier_count' => self::supplierCount($supplierCounts->get($requirement->product_variant_id)),
            ];
        }

        return $rows;
    }

    private static function supplierCount(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
