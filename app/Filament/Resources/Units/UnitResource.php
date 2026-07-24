<?php

declare(strict_types=1);

namespace App\Filament\Resources\Units;

use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.units');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            TextInput::make('symbol')->required()->maxLength(20)->unique(ignoreRecord: true),
            Toggle::make('allows_decimal'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('name_ar')->label('Arabic name')->searchable(),
            TextColumn::make('symbol')->searchable()->sortable(),
            IconColumn::make('allows_decimal')->boolean(),
            IconColumn::make('is_active')->boolean(),
        ])->filters([TernaryFilter::make('allows_decimal'), TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageUnits::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
