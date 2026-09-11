<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReplenishmentRequirements\Schemas;

use App\Data\Inventory\ReplenishmentTransferSuggestion;
use App\Enums\ReplenishmentRequirementStatus;
use App\Models\ReplenishmentRequirement;
use App\Services\Inventory\ReplenishmentProjectionService;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ReplenishmentRequirementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Requirement')->columns(3)->schema([
                TextEntry::make('warehouse.code')->label('Warehouse'),
                TextEntry::make('productVariant.sku')->label('SKU'),
                TextEntry::make('productVariant.name')->label('Variant'),
                TextEntry::make('policy.min_quantity')->label('Min')->numeric(decimalPlaces: 3),
                TextEntry::make('policy.max_quantity')->label('Max')->numeric(decimalPlaces: 3),
                TextEntry::make('projected_stock')
                    ->label('Projected Stock')
                    ->state(fn (ReplenishmentRequirement $record): float => app(ReplenishmentProjectionService::class)
                        ->project($record->policy)
                        ->projectedStock())
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('required_base_quantity')->label('Required')->numeric(decimalPlaces: 3),
                TextEntry::make('covered_base_quantity')->label('Covered')->numeric(decimalPlaces: 3),
                TextEntry::make('remaining_uncovered')
                    ->label('Uncovered')
                    ->state(fn (ReplenishmentRequirement $record): float => $record->remainingUncoveredQuantity())
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (ReplenishmentRequirementStatus $state): string => str($state->value)->headline()->toString()),
                TextEntry::make('triggered_at')->label('Triggered')->dateTime(),
                TextEntry::make('resolved_at')->label('Resolved')->dateTime()->placeholder('—'),
            ]),
            Section::make('Internal Transfer First')->schema([
                TextEntry::make('transfer_suggestions')
                    ->label('Suggested Sources')
                    ->state(fn (ReplenishmentRequirement $record): string => collect(
                        app(ReplenishmentTransferSuggestionService::class)->suggest($record),
                    )->map(static fn (ReplenishmentTransferSuggestion $suggestion): string => sprintf(
                        '%s — %.3f (available %.3f, protected max %.3f)',
                        $suggestion->sourceWarehouseName,
                        $suggestion->suggestedBaseQuantity,
                        $suggestion->saleableAvailable,
                        $suggestion->sourceMaximum,
                    ))->implode('; '))
                    ->placeholder('No safe internal-transfer surplus available.'),
            ]),
        ]);
    }
}
