<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\RelationManagers;

use App\Enums\UserType;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\CustomerProfile;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class CustomersRelationManager extends RelationManager
{
    protected static string $relationship = 'customerProfiles';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('customer_code')
            ->columns([
                TextColumn::make('customer_code')->searchable(),
                TextColumn::make('company_name')->searchable(),
                TextColumn::make('user.name')->searchable(),
                TextColumn::make('user.email')->searchable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->recordSelectOptionsQuery(static fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereHas('user', static fn (Builder $userQuery): Builder => $userQuery->where('user_type', UserType::Customer)))
                    ->recordSelectSearchColumns(['customer_code', 'company_name'])
                    ->using(fn (CustomerProfile $record, ProductSubscriptionService $service): CustomerProfile => $service
                        ->assignCustomers($this->subscription(), [$record->id], ProductSubscriptionResource::actor())
                        ->customerProfiles()
                        ->findOrFail($record->id)),
            ])
            ->recordActions([
                DetachAction::make()
                    ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                    ->using(function (CustomerProfile $record, ProductSubscriptionService $service): void {
                        $service->unassignCustomers($this->subscription(), [$record->id], ProductSubscriptionResource::actor());
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->authorize(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->visible(fn (): bool => ProductSubscriptionResource::canManageLinks())
                        ->using(function (Collection $records, ProductSubscriptionService $service): void {
                            $customerProfileIds = array_values(array_map(static fn (int|string $id): int => (int) $id, $records->modelKeys()));

                            $service->unassignCustomers($this->subscription(), $customerProfileIds, ProductSubscriptionResource::actor());
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
