<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\UserType;
use App\Models\CustomerProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('user_id')
                            ->label('Customer account')
                            ->relationship(
                                name: 'user',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query): Builder => $query
                                    ->where('user_type', UserType::Customer->value),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(CustomerProfile::class, 'user_id', ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('customer_code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('company_name')
                            ->maxLength(255),
                        Textarea::make('address')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }
}
