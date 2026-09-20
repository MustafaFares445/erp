<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests;

use App\Filament\Resources\CustomerQuotationRequests\Pages\CreateCustomerQuotationRequest;
use App\Filament\Resources\CustomerQuotationRequests\Pages\ListCustomerQuotationRequests;
use App\Filament\Resources\CustomerQuotationRequests\Pages\ViewCustomerQuotationRequest;
use App\Filament\Resources\CustomerQuotationRequests\Schemas\CustomerQuotationRequestForm;
use App\Filament\Resources\CustomerQuotationRequests\Schemas\CustomerQuotationRequestInfolist;
use App\Filament\Resources\CustomerQuotationRequests\Tables\CustomerQuotationRequestsTable;
use App\Models\CustomerQuotationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CustomerQuotationRequestResource extends Resource
{
    protected static ?string $model = CustomerQuotationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';

    protected static ?int $navigationSort = 502;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Quote Requests';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return CustomerQuotationRequestForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return CustomerQuotationRequestInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return CustomerQuotationRequestsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCustomerQuotationRequests::route('/'),
            'create' => CreateCustomerQuotationRequest::route('/create'),
            'view' => ViewCustomerQuotationRequest::route('/{record}'),
        ];
    }
}
