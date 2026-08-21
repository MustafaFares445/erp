<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\MovementType;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\InventoryMovement;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.inventory.movement.date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.inventory.stock.variant_name'))
                    ->searchable(),
                TextColumn::make('warehouse.code')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('package.name')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('movement_type')
                    ->label(__('admin.inventory.movement.type'))
                    ->badge()
                    ->formatStateUsing(fn (MovementType $state): string => Str::headline($state->value))
                    ->color(fn (MovementType $state): string => self::movementTypeColor($state)),
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.movement.quantity'))
                    ->formatStateUsing(fn (string $state): string => self::formatSignedQuantity($state))
                    ->color(fn (string $state): string => Str::startsWith($state, '-') ? 'danger' : 'success'),
                TextColumn::make('source_reference')
                    ->label(__('admin.inventory.movement.source'))
                    ->state(fn (InventoryMovement $record): string => self::sourceReference($record))
                    ->url(fn (InventoryMovement $record): ?string => self::sourceUrl($record)),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.inventory.movement.creator'))
                    ->default(__('admin.inventory.movement.system')),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label(__('admin.inventory.movement.type'))
                    ->options(self::movementTypeOptions()),
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_variant_id')
                    ->label(__('admin.inventory.stock.variant'))
                    ->relationship('productVariant', 'sku')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from)) {
                            $query->whereDate('created_at', '>=', $from);
                        }

                        if (is_string($until)) {
                            $query->whereDate('created_at', '<=', $until);
                        }

                        return $query;
                    }),
                SelectFilter::make('source_type')
                    ->label(__('admin.inventory.movement.source_type'))
                    ->options(fn (): array => InventoryMovement::query()
                        ->whereNotNull('source_type')
                        ->distinct()
                        ->orderBy('source_type')
                        ->pluck('source_type', 'source_type')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function movementTypeColor(MovementType $movementType): string
    {
        return match ($movementType) {
            MovementType::Sale, MovementType::Reservation, MovementType::Damage, MovementType::Disposal, MovementType::ServiceConsumption => 'danger',
            MovementType::Return, MovementType::DamageRecovery => 'success',
            MovementType::Adjustment, MovementType::Transfer => 'info',
            MovementType::Receipt => 'primary',
        };
    }

    private static function formatSignedQuantity(string $quantity): string
    {
        return Str::startsWith($quantity, '-') ? $quantity : '+'.$quantity;
    }

    public static function sourceReference(InventoryMovement $movement): string
    {
        if ($movement->source_type === null || $movement->source_id === null) {
            return __('admin.inventory.movement.no_source');
        }

        return sprintf('%s #%s', $movement->source_type, $movement->source_id);
    }

    public static function sourceUrl(InventoryMovement $movement): ?string
    {
        if ($movement->source_id === null) {
            return null;
        }

        $sourceResource = self::sourceResource($movement->source_type);

        if ($sourceResource === null) {
            return null;
        }

        return AdminModuleRegistry::resolveResourceRecordLink($sourceResource, $movement->source_id);
    }

    /**
     * @return non-empty-string|null
     */
    private static function sourceResource(?string $sourceType): ?string
    {
        return match ($sourceType) {
            'delivery_note' => 'App\\Filament\\Resources\\DeliveryNotes\\DeliveryNoteResource',
            'invoice' => 'App\\Filament\\Resources\\Invoices\\InvoiceResource',
            'credit_note' => 'App\\Filament\\Resources\\CreditNotes\\CreditNoteResource',
            'adjustment' => AdjustmentResource::class,
            'transfer' => TransferResource::class,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function movementTypeOptions(): array
    {
        return collect(MovementType::cases())
            ->mapWithKeys(fn (MovementType $type): array => [$type->value => Str::headline($type->value)])
            ->all();
    }
}
