<?php

declare(strict_types=1);

namespace App\Filament\Resources\DashboardUsers\Schemas;

use App\Enums\CrmPermission;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class DashboardUserRoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->disabled(),
            TextInput::make('email')->email()->disabled(),
            Select::make('role_name')
                ->label(__('admin.crm.fields.dashboard_role'))
                ->options(array_combine(CrmPermission::fixedRoleNames(), CrmPermission::fixedRoleNames()))
                ->required(),
        ]);
    }
}
