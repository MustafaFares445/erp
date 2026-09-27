<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierProductReference;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseNeeds extends Page
{
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
            Action::make('createPurchaseOrder')
                ->label('Create Purchase Order')
                ->icon(Heroicon::Plus)
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
                'source_reference' => $requirement->order?->order_number ?? '—',
                'product' => $requirement->productVariant?->product?->name ?? $requirement->productVariant?->name ?? '—',
                'sku' => $requirement->productVariant?->sku ?? '—',
                'warehouse' => $requirement->destinationWarehouse?->name ?? 'Not assigned',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->fulfilled_base_quantity,
                'remaining' => $requirement->outstandingBaseQuantity(),
                'linked_po' => $requirement->purchaseOrder?->purchase_order_number,
                'status' => (string) $requirement->status,
                'supplier_count' => (int) ($supplierCounts[$requirement->product_variant_id] ?? 0),
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
                'product' => $requirement->productVariant?->product?->name ?? $requirement->productVariant?->name ?? '—',
                'sku' => $requirement->productVariant?->sku ?? '—',
                'warehouse' => $requirement->warehouse?->name ?? '—',
                'required' => (string) $requirement->required_base_quantity,
                'covered' => (string) $requirement->covered_base_quantity,
                'remaining' => number_format($purchaseRemaining, 6, '.', ''),
                'linked_po' => null,
                'status' => $requirement->status->value,
                'supplier_count' => (int) ($supplierCounts[$requirement->product_variant_id] ?? 0),
            ];
        }

        return $rows;
    }
}
