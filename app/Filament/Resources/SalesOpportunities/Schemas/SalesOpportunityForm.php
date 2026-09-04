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
            Textarea::make('summary')->required()->rows(4)->columnSpanFull(),
            Select::make('customer_profile_id')->relationship('customerProfile', 'company_name')->searchable()->preload(),
            Select::make('lead_id')->relationship('lead', 'lead_number')->searchable()->preload(),
            Select::make('owner_id')->relationship('owner', 'name')->searchable()->preload(),
            TextInput::make('estimated_value')->numeric()->minValue(0),
            DatePicker::make('expected_close_date'),
        ])->columns(2);
    }
}
