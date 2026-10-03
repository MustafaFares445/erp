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
            Section::make(__('Warranty Policy'))
                ->columns(2)
                ->schema([
                    TextEntry::make('code')->badge(),
                    TextEntry::make('name'),
                    TextEntry::make('duration')
                        ->state(static fn (WarrantyPolicy $record): string => $record->duration_value.' '.$record->duration_unit->label()),
                    TextEntry::make('start_trigger')
                        ->label(__('Begins from'))
                        ->formatStateUsing(static fn (WarrantyStartTrigger $state): string => $state->label()),
                    IconEntry::make('is_active')->boolean()->label(__('Available for new sales')),
                    TextEntry::make('product_variants_count')
                        ->label(__('Assigned product variants'))
                        ->state(static fn (WarrantyPolicy $record): int => $record->productVariants()->count()),
                ]),
            Section::make(__('Coverage'))
                ->columns(3)
                ->schema([
                    IconEntry::make('covers_parts')->boolean()->label(__('Parts')),
                    IconEntry::make('covers_labour')->boolean()->label(__('Labour')),
                    IconEntry::make('covers_travel')->boolean()->label(__('Travel')),
                    IconEntry::make('covers_consumables')->boolean()->label(__('Consumables')),
                    IconEntry::make('covers_third_party')->boolean()->label(__('Third-party services')),
                    IconEntry::make('transferable')->boolean()->label(__('Transferable')),
                ]),
            Section::make(__('Rules'))
                ->schema([
                    TextEntry::make('replacement_rule')
                        ->label(__('Replacement rule'))
                        ->formatStateUsing(static fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),
                    TextEntry::make('exclusions')->placeholder(__('No additional exclusions recorded'))->columnSpanFull(),
                ]),
        ]);
    }
}
