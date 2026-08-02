<?php

declare(strict_types=1);

namespace App\Filament\Resources\DashboardUsers;

use App\Enums\CrmPermission;
use App\Enums\UserType;
use App\Filament\Resources\DashboardUsers\Pages\EditDashboardUser;
use App\Filament\Resources\DashboardUsers\Pages\ListDashboardUsers;
use App\Filament\Resources\DashboardUsers\Schemas\DashboardUserRoleForm;
use App\Filament\Resources\DashboardUsers\Tables\DashboardUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DashboardUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.dashboard_users');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return DashboardUserRoleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return DashboardUsersTable::configure($table);
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && ($actor->can(CrmPermission::DashboardRoleAssign->value)
                || ($actor->isAdmin() && ! $actor->hasAnyRole(CrmPermission::fixedRoleNames())));
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function canEdit(Model $record): bool
    {
        return self::canAccess() && $record instanceof User && $record->user_type === UserType::Admin;
    }

    #[\Override]
    public static function canDeleteAny(): bool
    {
        return false;
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_type', UserType::Admin->value)
            ->with('roles')
            ->orderBy('name');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDashboardUsers::route('/'),
            'edit' => EditDashboardUser::route('/{record}/edit'),
        ];
    }
}
