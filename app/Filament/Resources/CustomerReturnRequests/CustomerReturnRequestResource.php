<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests;

use App\Filament\Resources\CustomerReturnRequests\Pages\CreateCustomerReturnRequest;
use App\Filament\Resources\CustomerReturnRequests\Pages\ListCustomerReturnRequests;
use App\Filament\Resources\CustomerReturnRequests\Pages\ViewCustomerReturnRequest;
use App\Filament\Resources\CustomerReturnRequests\Schemas\CustomerReturnRequestForm;
use App\Filament\Resources\CustomerReturnRequests\Schemas\CustomerReturnRequestInfolist;
use App\Filament\Resources\CustomerReturnRequests\Tables\CustomerReturnRequestsTable;
use App\Models\CustomerReturnRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CustomerReturnRequestResource extends Resource
{
    protected static ?string $model = CustomerReturnRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';

    protected static ?int $navigationSort = 503;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.customer_return_requests');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return CustomerReturnRequestForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return CustomerReturnRequestInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return CustomerReturnRequestsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCustomerReturnRequests::route('/'),
            'create' => CreateCustomerReturnRequest::route('/create'),
            'view' => ViewCustomerReturnRequest::route('/{record}'),
        ];
    }
}
