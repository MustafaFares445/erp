<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts\Tables;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryAlert;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->formatStateUsing(fn (InventoryAlertType $state): string => __('admin.inventory.alert.types.'.$state->value))
                    ->badge()
                    ->sortable(),
                TextColumn::make('severity')
                    ->formatStateUsing(fn (InventoryAlertSeverity $state): string => __('admin.inventory.alert.severities.'.$state->value))
                    ->badge()
                    ->sortable(),
                TextColumn::make('message')->wrap()->searchable(),
                TextColumn::make('subject_reference')
                    ->label(__('admin.inventory.alert.origin'))
                    ->state(fn (InventoryAlert $record): string => self::subjectReference($record)),
                TextColumn::make('state')
                    ->label(__('admin.inventory.alert.state'))
                    ->state(fn (InventoryAlert $record): string => $record->isActive()
                        ? __('admin.inventory.alert.active')
                        : __('admin.inventory.alert.resolved'))
                    ->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->dateTime()->sortable()->placeholder(__('—')),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->trueLabel(__('admin.inventory.alert.filters.active'))
                    ->falseLabel(__('admin.inventory.alert.filters.resolved'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                    ),
                SelectFilter::make('type')
                    ->options(collect(InventoryAlertType::cases())
                        ->mapWithKeys(fn (InventoryAlertType $type): array => [
                            $type->value => __('admin.inventory.alert.types.'.$type->value),
                        ])
                        ->all()),
                SelectFilter::make('severity')
                    ->options(collect(InventoryAlertSeverity::cases())
                        ->mapWithKeys(fn (InventoryAlertSeverity $severity): array => [
                            $severity->value => __('admin.inventory.alert.severities.'.$severity->value),
                        ])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('open_origin')
                    ->label(__('admin.inventory.alert.open_origin'))
                    ->url(fn (InventoryAlert $record): ?string => self::subjectUrl($record))
                    ->visible(fn (InventoryAlert $record): bool => self::subjectUrl($record) !== null),
            ]);
    }

    public static function subjectUrl(InventoryAlert $alert): ?string
    {
        $resource = match ($alert->subject_type) {
            InventoryStock::class => StockLevelResource::class,
            InventoryLot::class => InventoryLotResource::class,
            InventoryOperation::class => InventoryOperationResource::class,
            InventoryImportRun::class => InventoryImportRunResource::class,
            SerializedInventoryUnit::class => SerializedInventoryUnitResource::class,
            ProductVariant::class => ProductVariantResource::class,
            default => null,
        };

        return is_string($resource)
            ? AdminModuleRegistry::resolveResourceRecordLink($resource, $alert->subject_id)
            : null;
    }

    public static function subjectReference(InventoryAlert $alert): string
    {
        return match ($alert->subject_type) {
            InventoryStock::class => self::stockReference($alert->subject_id),
            InventoryLot::class => self::lotReference($alert->subject_id),
            InventoryOperation::class => self::operationReference($alert->subject_id),
            SerializedInventoryUnit::class => self::serializedUnitReference($alert->subject_id),
            ProductVariant::class => self::variantReference($alert->subject_id),
            InventoryImportRun::class => __('admin.resources.inventory_import_runs').' #'.$alert->subject_id,
            default => class_basename($alert->subject_type).' #'.$alert->subject_id,
        };
    }

    private static function lotReference(int $lotId): string
    {
        $lot = InventoryLot::query()->whereKey($lotId)->first();

        return $lot instanceof InventoryLot && is_string($lot->lot_number) && $lot->lot_number !== ''
            ? $lot->lot_number
            : 'Lot #'.$lotId;
    }

    private static function operationReference(int $operationId): string
    {
        $operation = InventoryOperation::query()->whereKey($operationId)->first();

        return $operation instanceof InventoryOperation
            && is_string($operation->operation_number)
            && $operation->operation_number !== ''
                ? $operation->operation_number
                : __('admin.resources.inventory_operations').' #'.$operationId;
    }

    private static function serializedUnitReference(int $unitId): string
    {
        $unit = SerializedInventoryUnit::query()->whereKey($unitId)->first();

        return $unit instanceof SerializedInventoryUnit && $unit->serial_number !== ''
            ? $unit->serial_number
            : __('admin.resources.serialized_inventory_units').' #'.$unitId;
    }

    private static function variantReference(int $variantId): string
    {
        $variant = ProductVariant::query()->whereKey($variantId)->first();

        return $variant instanceof ProductVariant && $variant->sku !== ''
            ? $variant->sku
            : __('admin.resources.product_variants').' #'.$variantId;
    }

    private static function stockReference(int $stockId): string
    {
        $stock = InventoryStock::query()
            ->with(['productVariant:id,sku', 'warehouse:id,name'])
            ->find($stockId);

        if (! $stock instanceof InventoryStock) {
            return __('admin.resources.stock_levels').' #'.$stockId;
        }

        $variant = $stock->productVariant;
        $warehouse = $stock->warehouse;

        return ($variant instanceof ProductVariant ? $variant->sku : '#'.$stock->product_variant_id)
            .' · '
            .($warehouse instanceof Warehouse ? $warehouse->name : '#'.$stock->warehouse_id);
    }
}
