<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class SalesOpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->maxLength(255),
            Textarea::make('summary')->required()->rows(4)->columnSpanFull(),
            Select::make('customer_id')->relationship('customer', 'company_name')->searchable()->preload(),
            Select::make('lead_id')->relationship('lead', 'lead_number')->searchable()->preload(),
            Select::make('owner_id')->relationship('owner', 'name')->searchable()->preload(),
            TextInput::make('estimated_value_minor')->label('Estimated value (minor units)')->numeric()->minValue(0),
            TextInput::make('currency')->default('AED')->maxLength(3)->required(),
            DatePicker::make('expected_close_date'),
            TextInput::make('probability_percent')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
        ])->columns(2);
    }
}
