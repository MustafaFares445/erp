<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportServiceLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Service level'))
                ->description(__('A contractual tier (for example Standard or Premium). Entitlements grant it to customers or equipment, and SLA policies can target it.'))
                ->schema([
                    TextInput::make('code')->label(__('Code'))->required()->maxLength(60)->unique(ignoreRecord: true)
                        ->alphaDash()
                        ->helperText(__('Short stable identifier used by integrations and reports.')),
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    Toggle::make('is_active')->label(__('Active'))->default(true),
                    Textarea::make('description')->label(__('Description'))->rows(3)->columnSpanFull(),
                ])->columns(2),
        ]);
    }
}
