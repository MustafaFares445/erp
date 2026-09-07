<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadSource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('source')
                ->options(collect(LeadSource::cases())->mapWithKeys(fn (LeadSource $source): array => [$source->value => str($source->value)->replace('_', ' ')->headline()->toString()])->all())
                ->required(),
            TextInput::make('source_detail')->maxLength(255),
            TextInput::make('first_name')->maxLength(255),
            TextInput::make('last_name')->maxLength(255),
            TextInput::make('company_name')->maxLength(255),
            TextInput::make('job_title')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(50),
            Select::make('preferred_language')
                ->options(['en' => 'English', 'ar' => 'Arabic'])
                ->default('en')
                ->required(),
            Select::make('assigned_to')
                ->relationship('assignee', 'name')
                ->searchable()
                ->preload(),
        ])->columns(2);
    }
}
