<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\LeadInteractionsRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadStageHistoryRelationManager;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';

    protected static ?int $navigationSort = 502;

    protected static ?string $recordTitleAttribute = 'lead_number';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Leads';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            LeadInteractionsRelationManager::class,
            LeadStageHistoryRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
