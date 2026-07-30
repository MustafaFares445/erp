<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions;

use App\Enums\CrmPermission;
use App\Filament\Resources\ProductSubscriptions\Pages\CreateProductSubscription;
use App\Filament\Resources\ProductSubscriptions\Pages\EditProductSubscription;
use App\Filament\Resources\ProductSubscriptions\Pages\ListProductSubscriptions;
use App\Filament\Resources\ProductSubscriptions\Pages\ViewProductSubscription;
use App\Filament\Resources\ProductSubscriptions\Schemas\ProductSubscriptionForm;
use App\Filament\Resources\ProductSubscriptions\Schemas\ProductSubscriptionInfolist;
use App\Filament\Resources\ProductSubscriptions\Tables\ProductSubscriptionsTable;
use App\Models\ProductSubscription;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

final class ProductSubscriptionResource extends Resource
{
    protected static ?string $model = ProductSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ProductSubscriptionForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ProductSubscriptionInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ProductSubscriptionsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListProductSubscriptions::route('/'),
            'create' => CreateProductSubscription::route('/create'),
            'view' => ViewProductSubscription::route('/{record}'),
            'edit' => EditProductSubscription::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'products',
                'customerProfiles as active_customer_profiles_count' => fn (Builder $query): Builder => $query->where('is_active', true),
            ])
            ->latest('id');
    }

    public static function canManage(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->isAdmin() && ! $actor->hasAnyRole(CrmPermission::fixedRoleNames())) {
            return true;
        }

        return $actor->can(CrmPermission::SubscriptionManage->value);
    }

    public static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated CRM actor is required.');
        }

        return $actor;
    }
}
