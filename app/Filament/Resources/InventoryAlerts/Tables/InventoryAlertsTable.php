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
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class InventoryAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->formatStateUsing(fn (InventoryAlertType $state): string => Str::headline($state->name))
                    ->badge()
                    ->sortable(),
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('message')->wrap()->searchable(),
                TextColumn::make('subject_reference')
                    ->label('Origin')
                    ->state(fn (InventoryAlert $record): string => self::subjectReference($record)),
                TextColumn::make('state')
                    ->state(fn (InventoryAlert $record): string => $record->isActive() ? 'active' : 'resolved')
                    ->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->dateTime()->sortable()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->trueLabel('Active')
                    ->falseLabel('Resolved')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                    ),
                SelectFilter::make('type')
                    ->options(collect(InventoryAlertType::cases())
                        ->mapWithKeys(fn (InventoryAlertType $type): array => [$type->value => $type->name])
                        ->all()),
                SelectFilter::make('severity')
                    ->options(collect(InventoryAlertSeverity::cases())
                        ->mapWithKeys(fn (InventoryAlertSeverity $severity): array => [$severity->value => $severity->name])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('open_origin')
                    ->label('Open Origin')
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
        return class_basename($alert->subject_type).' #'.$alert->subject_id;
    }
}
