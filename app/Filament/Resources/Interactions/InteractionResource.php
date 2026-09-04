<?php

declare(strict_types=1);

namespace App\Filament\Resources\Interactions;

use App\Filament\Resources\Interactions\Pages\ListInteractions;
use App\Models\Interaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class InteractionResource extends Resource
{
    protected static ?string $model = Interaction::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;
    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';
    protected static ?int $navigationSort = 503;

    #[\Override]
    public static function getNavigationLabel(): string { return 'Interactions'; }
    #[\Override]
    public static function canCreate(): bool { return false; }
    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('subject_type')->label('Party type')->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('subject_id')->label('Party ID')->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('direction')->badge(),
            TextColumn::make('summary')->searchable()->wrap(),
            TextColumn::make('employee.name')->label('Employee')->searchable(),
            TextColumn::make('outcome')->badge()->placeholder('—'),
        ]);
    }
    #[\Override]
    public static function getPages(): array { return ['index' => ListInteractions::route('/')]; }
}
