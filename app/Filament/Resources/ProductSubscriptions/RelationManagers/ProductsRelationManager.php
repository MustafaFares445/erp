<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\RelationManagers;

use App\Enums\ProductStatus;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('name_ar')->searchable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->recordSelectOptionsQuery(static fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->where('status', ProductStatus::Active->value))
                    ->recordSelectSearchColumns(['name', 'name_ar'])
                    ->using(fn (Product $record, ProductSubscriptionService $service): Product => $service
                        ->assignProducts($this->subscription(), [$record->id], ProductSubscriptionResource::actor())
                        ->products()
                        ->findOrFail($record->id)),
            ])
            ->recordActions([
                DetachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->using(function (Product $record, ProductSubscriptionService $service): void {
                        $service->unassignProducts($this->subscription(), [$record->id], ProductSubscriptionResource::actor());
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->using(function (Collection $records, ProductSubscriptionService $service): void {
                            $productIds = array_values(array_map(static fn (int|string $id): int => (int) $id, $records->modelKeys()));

                            $service->unassignProducts($this->subscription(), $productIds, ProductSubscriptionResource::actor());
                        }),
                ]),
            ]);
    }

    private function subscription(): ProductSubscription
    {
        $subscription = $this->getOwnerRecord();

        if (! $subscription instanceof ProductSubscription) {
            throw new LogicException('Expected a product subscription relation manager owner.');
        }

        return $subscription;
    }
}
