<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductAttributes;

use App\Filament\Resources\ProductAttributes\Pages\ManageProductAttributes;
use App\Models\ProductAttribute;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ProductAttributeResource extends Resource
{
    protected static ?string $model = ProductAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            TextInput::make('code')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('data_type')->options(['select' => 'Select', 'text' => 'Text'])->default('select')->required(),
            Toggle::make('is_active')->default(true),
            Repeater::make('values')
                ->relationship()
                ->schema([
                    TextInput::make('value')->required()->maxLength(255),
                    TextInput::make('value_ar')->label('Arabic value')->maxLength(255),
                    Toggle::make('is_active')->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('data_type')->badge(),
            TextColumn::make('values_count')->counts('values'),
            IconColumn::make('is_active')->boolean(),
        ])->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageProductAttributes::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
