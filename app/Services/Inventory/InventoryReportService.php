<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Models\CustomerPricingTier;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierProductReference;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class InventoryReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function query(InventoryReportType $type, array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($type, $filters);

        return match ($type) {
            InventoryReportType::Catalog => $this->catalogQuery($filters),
            InventoryReportType::StockLevels => $this->stockQuery($filters),
            InventoryReportType::Movements => $this->movementQuery($filters),
            InventoryReportType::Devices => $this->deviceQuery($filters),
            InventoryReportType::ExpiryLots => $this->expiryQuery($filters),
            InventoryReportType::SupplierComparison => $this->supplierQuery($filters),
            InventoryReportType::PriceHistory => $this->priceHistoryQuery($filters),
            InventoryReportType::PricingTiers => $this->pricingTierQuery($filters),
            InventoryReportType::CustomerAssignments => $this->customerAssignmentQuery($filters),
            InventoryReportType::FloorOverrides => $this->floorOverrideQuery($filters),
            InventoryReportType::ImportRuns => $this->importRunQuery($filters),
            InventoryReportType::ImportResults => $this->importResultQuery($filters),
        };
    }

    /** @return list<InventoryReportType> */
    public function availableReports(User $actor): array
    {
        return array_values(array_filter(
            InventoryReportType::cases(),
            fn (InventoryReportType $type): bool => $this->canView($actor, $type),
        ));
    }

    public function canView(User $actor, InventoryReportType $type): bool
    {
        if ($type->requiresPricing() && $actor->can(CrmPermission::ReportView->value)) {
            return $actor->can(CrmPermission::ReportView->value);
        }

        $sourcePermission = $type->sourcePermission();

        return $actor->can(InventoryPermission::ReportView->value)
            && $actor->can($sourcePermission->value)
            && (! $type->requiresPricing() || $actor->can(InventoryPermission::PricingView->value));
    }

    /** @throws DomainException */
    public function authorizeView(User $actor, InventoryReportType $type): void
    {
        if (! $this->canView($actor, $type)) {
            throw new DomainException(__('admin.inventory.reports.errors.unauthorized'));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, bool|int|string>
     */
    public function normalizeFilters(InventoryReportType $type, array $filters): array
    {
        $normalized = [];
        $supported = $this->supportedFilters($type);

        foreach ($filters as $key => $value) {
            if (! in_array($key, $supported, true)) {
                continue;
            }

            $value = $this->normalizeFilterValue($key, $value);

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        $this->validateDateRange($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<ProductVariant>
     */
    private function catalogQuery(array $filters): Builder
    {
        $query = ProductVariant::query()
            ->with(['product.brand', 'product.category', 'unit'])
            ->withCount('supplierReferences');

        $this->whereInteger($query, $filters, 'product_id');
        $this->whereString($query, $filters, 'status');
        $this->whereBoolean($query, $filters, 'is_active');

        if (isset($filters['category_id'])) {
            $query->whereHas('product', fn (Builder $product): Builder => $product->where('category_id', $filters['category_id']));
        }

        if (isset($filters['brand_id'])) {
            $query->whereHas('product', fn (Builder $product): Builder => $product->where('brand_id', $filters['brand_id']));
        }

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<InventoryStock>
     */
    private function stockQuery(array $filters): Builder
    {
        $query = InventoryStock::query()->with(['productVariant.product', 'warehouse']);
        $this->whereInteger($query, $filters, 'warehouse_id');
        $this->whereInteger($query, $filters, 'product_variant_id');

        return match ($filters['availability_state'] ?? null) {
            'out_of_stock' => $query->where('available_quantity', 0),
            'low_stock' => $query
                ->where('available_quantity', '>', 0)
                ->whereNotNull('reorder_level')
                ->whereColumn('available_quantity', '<=', 'reorder_level'),
            'available' => $query->where('available_quantity', '>', 0),
            default => $query,
        };
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<InventoryMovement>
     */
    private function movementQuery(array $filters): Builder
    {
        $query = InventoryMovement::query()->with(['productVariant', 'warehouse', 'serializedUnit']);
        $this->whereInteger($query, $filters, 'warehouse_id');
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->whereString($query, $filters, 'movement_type');
        $this->applyDateRange($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<SerializedInventoryUnit>
     */
    private function deviceQuery(array $filters): Builder
    {
        $query = SerializedInventoryUnit::query()
            ->with(['productVariant.product', 'warehouse', 'receiptItem.receipt'])
            ->withCount('movements');
        $this->whereInteger($query, $filters, 'warehouse_id');
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->whereString($query, $filters, 'status');

        if (isset($filters['identity'])) {
            $identity = $filters['identity'];
            $query->where(fn (Builder $identityQuery): Builder => $identityQuery
                ->where('serial_number', 'like', sprintf('%%%s%%', $identity))
                ->orWhere('iot_number', 'like', sprintf('%%%s%%', $identity)));
        }

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<InventoryLot>
     */
    private function expiryQuery(array $filters): Builder
    {
        $query = InventoryLot::query()->with(['productVariant.product', 'warehouse']);
        $this->whereInteger($query, $filters, 'warehouse_id');
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->applyDateRange($query, $filters, 'expires_at');

        $threshold = today()->addDays(InventorySetting::expiryAlertDays())->toDateString();

        return match ($filters['expiry_state'] ?? null) {
            'expired' => $query->whereDate('expires_at', '<', today()),
            'expiring' => $query->whereBetween('expires_at', [today()->toDateString(), $threshold]),
            'healthy' => $query->whereDate('expires_at', '>', $threshold),
            'no_expiry' => $query->whereNull('expires_at'),
            default => $query,
        };
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<SupplierProductReference>
     */
    private function supplierQuery(array $filters): Builder
    {
        $query = SupplierProductReference::query()->with(['supplier', 'productVariant.product']);
        $this->whereInteger($query, $filters, 'supplier_id');
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->whereString($query, $filters, 'country_code');
        $this->whereString($query, $filters, 'currency_code');
        $this->whereBoolean($query, $filters, 'is_active');

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<PriceHistory>
     */
    private function priceHistoryQuery(array $filters): Builder
    {
        $query = PriceHistory::query()->with(['productVariant.product', 'changedBy']);
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->whereInteger($query, $filters, 'changed_by');
        $this->applyDateRange($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<PricingTier>
     */
    private function pricingTierQuery(array $filters): Builder
    {
        $query = PricingTier::query()
            ->with(['customer'])
            ->withCount(['products', 'assignments as active_assignments_count' => fn (Builder $assignments): Builder => $assignments->where('is_active', true)]);
        $this->whereBoolean($query, $filters, 'is_active');
        $this->whereString($query, $filters, 'tier_type');
        $this->whereString($query, $filters, 'visibility');

        if (isset($filters['customer_user_id'])) {
            $query->where(fn (Builder $customerQuery): Builder => $customerQuery
                ->where('customer_user_id', $filters['customer_user_id'])
                ->orWhereHas('assignments', fn (Builder $assignments): Builder => $assignments
                    ->where('customer_user_id', $filters['customer_user_id'])
                    ->where('is_active', true)));
        }

        if (isset($filters['product_id'])) {
            $query->whereHas('products', fn (Builder $products): Builder => $products->whereKey($filters['product_id']));
        }

        $query = match ($filters['eligibility_state'] ?? null) {
            'current' => $query->current(),
            'scheduled' => $query->scheduled(),
            'expired' => $query->expired(),
            default => $query,
        };
        $this->applyDateRange($query, $filters, 'valid_until');

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<CustomerPricingTier>
     */
    private function customerAssignmentQuery(array $filters): Builder
    {
        $query = CustomerPricingTier::query()->with(['customer', 'pricingTier.products']);
        $this->whereInteger($query, $filters, 'customer_user_id');
        $this->whereBoolean($query, $filters, 'is_active');

        if (isset($filters['tier_type'])) {
            $query->whereHas('pricingTier', fn (Builder $tier): Builder => $tier->where('tier_type', $filters['tier_type']));
        }

        if (isset($filters['product_id'])) {
            $query->whereHas('pricingTier.products', fn (Builder $products): Builder => $products->whereKey($filters['product_id']));
        }

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<PriceFloorOverride>
     */
    private function floorOverrideQuery(array $filters): Builder
    {
        $query = PriceFloorOverride::query()->with(['productVariant.product', 'customer', 'pricingTier', 'approvedBy']);
        $this->whereInteger($query, $filters, 'product_variant_id');
        $this->whereInteger($query, $filters, 'customer_user_id');
        $this->whereInteger($query, $filters, 'pricing_tier_id');
        $this->applyDateRange($query, $filters, 'approved_at');

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<InventoryImportRun>
     */
    private function importRunQuery(array $filters): Builder
    {
        $query = InventoryImportRun::query()->with(['createdBy', 'confirmedBy']);
        $this->whereString($query, $filters, 'status');
        $this->whereInteger($query, $filters, 'created_by');
        $this->applyDateRange($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<InventoryImportItem>
     */
    private function importResultQuery(array $filters): Builder
    {
        $query = InventoryImportItem::query()->with(['run.createdBy']);
        $this->whereInteger($query, $filters, 'inventory_import_run_id');
        $this->whereString($query, $filters, 'status');

        if (isset($filters['created_by'])) {
            $query->whereHas('run', fn (Builder $run): Builder => $run->where('created_by', $filters['created_by']));
        }

        $this->applyDateRange($query, $filters);

        return $query;
    }

    /** @return list<string> */
    private function supportedFilters(InventoryReportType $type): array
    {
        return match ($type) {
            InventoryReportType::Catalog => ['product_id', 'category_id', 'brand_id', 'status', 'is_active'],
            InventoryReportType::StockLevels => ['warehouse_id', 'product_variant_id', 'availability_state'],
            InventoryReportType::Movements => ['warehouse_id', 'product_variant_id', 'movement_type', 'from', 'until'],
            InventoryReportType::Devices => ['warehouse_id', 'product_variant_id', 'status', 'identity'],
            InventoryReportType::ExpiryLots => ['warehouse_id', 'product_variant_id', 'expiry_state', 'from', 'until'],
            InventoryReportType::SupplierComparison => ['supplier_id', 'product_variant_id', 'country_code', 'currency_code', 'is_active'],
            InventoryReportType::PriceHistory => ['product_variant_id', 'changed_by', 'from', 'until'],
            InventoryReportType::PricingTiers => ['customer_user_id', 'product_id', 'tier_type', 'visibility', 'is_active', 'eligibility_state', 'from', 'until'],
            InventoryReportType::CustomerAssignments => ['customer_user_id', 'product_id', 'tier_type', 'is_active'],
            InventoryReportType::FloorOverrides => ['product_variant_id', 'customer_user_id', 'pricing_tier_id', 'from', 'until'],
            InventoryReportType::ImportRuns => ['status', 'created_by', 'from', 'until'],
            InventoryReportType::ImportResults => ['inventory_import_run_id', 'status', 'created_by', 'from', 'until'],
        };
    }

    private function normalizeFilterValue(string $key, mixed $value): bool|int|string|null
    {
        if (in_array($key, [
            'product_id',
            'category_id',
            'brand_id',
            'warehouse_id',
            'product_variant_id',
            'supplier_id',
            'customer_user_id',
            'changed_by',
            'created_by',
            'inventory_import_run_id',
            'pricing_tier_id',
        ], true)) {
            return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        if ($key === 'is_active') {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        if ($value === '') {
            return null;
        }

        if ($key === 'country_code' || $key === 'currency_code') {
            return mb_strtoupper($value);
        }

        if (($key === 'from' || $key === 'until') && ! $this->isDate($value)) {
            throw new DomainException(__('admin.inventory.reports.errors.invalid_date'));
        }

        return $value;
    }

    /** @param array<string, bool|int|string> $filters */
    private function validateDateRange(array $filters): void
    {
        if (isset($filters['from'], $filters['until']) && $filters['from'] > $filters['until']) {
            throw new DomainException(__('admin.inventory.reports.errors.invalid_date_range'));
        }
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, bool|int|string>  $filters
     */
    private function whereInteger(Builder $query, array $filters, string $key): void
    {
        if (isset($filters[$key]) && is_int($filters[$key])) {
            $query->where($key, $filters[$key]);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, bool|int|string>  $filters
     */
    private function whereString(Builder $query, array $filters, string $key): void
    {
        if (isset($filters[$key]) && is_string($filters[$key])) {
            $query->where($key, $filters[$key]);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, bool|int|string>  $filters
     */
    private function whereBoolean(Builder $query, array $filters, string $key): void
    {
        if (isset($filters[$key]) && is_bool($filters[$key])) {
            $query->where($key, $filters[$key]);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, bool|int|string>  $filters
     */
    private function applyDateRange(Builder $query, array $filters, string $column = 'created_at'): void
    {
        if (isset($filters['from']) && is_string($filters['from'])) {
            $query->whereDate($column, '>=', $filters['from']);
        }

        if (isset($filters['until']) && is_string($filters['until'])) {
            $query->whereDate($column, '<=', $filters['until']);
        }
    }
}
