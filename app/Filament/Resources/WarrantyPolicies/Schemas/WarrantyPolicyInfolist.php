<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Schemas;

use App\Enums\WarrantyStartTrigger;
use App\Models\WarrantyPolicy;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WarrantyPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warranty Policy')
                ->columns(2)
                ->schema([
                    TextEntry::make('code')->badge(),
                    TextEntry::make('name'),
                    TextEntry::make('duration')
                        ->state(static fn (WarrantyPolicy $record): string => $record->duration_value.' '.$record->duration_unit->value),
                    TextEntry::make('start_trigger')
                        ->label('Begins from')
                        ->formatStateUsing(static fn (WarrantyStartTrigger $state): string => $state->label()),
                    IconEntry::make('is_active')->boolean()->label('Available for new sales'),
                    TextEntry::make('product_variants_count')
                        ->label('Assigned product variants')
                        ->state(static fn (WarrantyPolicy $record): int => $record->productVariants()->count()),
                ]),
            Section::make('Coverage')
                ->columns(3)
                ->schema([
                    IconEntry::make('covers_parts')->boolean()->label('Parts'),
                    IconEntry::make('covers_labour')->boolean()->label('Labour'),
                    IconEntry::make('covers_travel')->boolean()->label('Travel'),
                    IconEntry::make('covers_consumables')->boolean()->label('Consumables'),
                    IconEntry::make('covers_third_party')->boolean()->label('Third-party services'),
                    IconEntry::make('transferable')->boolean()->label('Transferable'),
                ]),
            Section::make('Rules')
                ->schema([
                    TextEntry::make('replacement_rule')
                        ->label('Replacement rule')
                        ->formatStateUsing(static fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),
                    TextEntry::make('exclusions')->placeholder('No additional exclusions recorded')->columnSpanFull(),
                ]),
        ]);
    }
}
