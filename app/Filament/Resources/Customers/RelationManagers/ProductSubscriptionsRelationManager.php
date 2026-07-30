<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\CustomerProfile;
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
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ProductSubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productSubscriptions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('discount_value'),
                TextColumn::make('visibility')->badge(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->recordSelectOptionsQuery(static fn (Builder $query): Builder => $query)
                    ->recordSelectSearchColumns(['name'])
                    ->using(fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service
                        ->assignCustomers($record, [$this->customer()->id], ProductSubscriptionResource::actor())),
            ])
            ->recordActions([
                DetachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->using(function (ProductSubscription $record, ProductSubscriptionService $service): void {
                        $service->unassignCustomers($record, [$this->customer()->id], ProductSubscriptionResource::actor());
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->using(function (Collection $records, ProductSubscriptionService $service): void {
                            $customerId = $this->customer()->id;

                            $records->each(function (Model $record) use ($customerId, $service): void {
                                if (! $record instanceof ProductSubscription) {
                                    throw new LogicException('Expected product subscriptions in the customer relationship manager.');
                                }

                                $service->unassignCustomers($record, [$customerId], ProductSubscriptionResource::actor());
                            });
                        }),
                ]),
            ]);
    }

    private function customer(): CustomerProfile
    {
        $customer = $this->getOwnerRecord();

        if (! $customer instanceof CustomerProfile) {
            throw new LogicException('Expected a customer profile relation manager owner.');
        }

        return $customer;
    }
}
