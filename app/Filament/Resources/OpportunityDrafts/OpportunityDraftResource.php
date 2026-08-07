<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityDrafts;

use App\Filament\Resources\OpportunityDrafts\Pages\ListOpportunityDrafts;
use App\Filament\Resources\OpportunityDrafts\Pages\ViewOpportunityDraft;
use App\Filament\Resources\OpportunityDrafts\Schemas\OpportunityDraftInfolist;
use App\Filament\Resources\OpportunityDrafts\Tables\OpportunityDraftsTable;
use App\Models\SalesOpportunityDraft;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class OpportunityDraftResource extends Resource
{
    protected static ?string $model = SalesOpportunityDraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 632;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.opportunity_drafts');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return OpportunityDraftInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return OpportunityDraftsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListOpportunityDrafts::route('/'),
            'view' => ViewOpportunityDraft::route('/{record}'),
        ];
    }
}
