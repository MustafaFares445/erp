<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Schemas;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStartTrigger;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WarrantyPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('General')
                ->description('Reusable warranty terms assigned to product variants. Terms are snapshotted per sold serial so history never changes retroactively.')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(80)
                        ->unique(ignoreRecord: true)
                        ->helperText('Stable internal code, for example STANDARD-12M.'),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('duration_value')
                        ->label('Coverage period')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    Select::make('duration_unit')
                        ->label('Period unit')
                        ->options(collect(WarrantyDurationUnit::cases())
                            ->mapWithKeys(static fn (WarrantyDurationUnit $unit): array => [$unit->value => str($unit->value)->headline()->toString()]))
                        ->required()
                        ->native(false),
                    Select::make('start_trigger')
                        ->label('Warranty begins from')
                        ->options(collect(WarrantyStartTrigger::cases())
                            ->mapWithKeys(static fn (WarrantyStartTrigger $trigger): array => [$trigger->value => $trigger->label()]))
                        ->required()
                        ->native(false)
                        ->helperText('Confirmed delivery is automatic. Installation, commissioning and manual activation remain pending until explicitly activated.'),
                    Toggle::make('is_active')
                        ->label('Available for new sales')
                        ->default(true),
                ]),
            Section::make('Coverage rules')
                ->description('These defaults drive the later claim decision UI. Being inside the warranty period does not automatically approve every repair.')
                ->columns(3)
                ->schema([
                    Toggle::make('covers_parts')->label('Replacement parts')->default(true),
                    Toggle::make('covers_labour')->label('Labour')->default(true),
                    Toggle::make('covers_travel')->label('Travel')->default(false),
                    Toggle::make('covers_consumables')->label('Consumables')->default(false),
                    Toggle::make('covers_third_party')->label('Third-party services')->default(false),
                    Toggle::make('transferable')->label('Transferable to another customer')->default(false),
                ]),
            Section::make('Rules & exclusions')
                ->columns(2)
                ->schema([
                    Select::make('replacement_rule')
                        ->label('Replacement equipment warranty')
                        ->options([
                            'remaining_original_term' => 'Keep remaining original term',
                            'restart_full_term' => 'Restart full policy term',
                            'manual_review' => 'Manual review',
                        ])
                        ->default('remaining_original_term')
                        ->required()
                        ->native(false),
                    Textarea::make('exclusions')
                        ->label('Exclusions / policy notes')
                        ->rows(5)
                        ->helperText('Describe misuse, accidental damage, consumables, unauthorized modifications or other policy exclusions.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
