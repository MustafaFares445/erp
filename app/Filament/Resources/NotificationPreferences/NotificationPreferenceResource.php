<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationPreferences;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\NotificationPreferences\Pages\CreateNotificationPreference;
use App\Filament\Resources\NotificationPreferences\Pages\EditNotificationPreference;
use App\Filament\Resources\NotificationPreferences\Pages\ListNotificationPreferences;
use App\Models\NotificationPreference;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class NotificationPreferenceResource extends Resource
{
    protected static ?string $model = NotificationPreference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Notification preferences';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->relationship('user', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('template_key')
                ->label('Event')
                ->options(self::eventOptions())
                ->searchable()
                ->required(),
            Select::make('channel')
                ->options(self::channelOptions())
                ->required(),
            Toggle::make('enabled')
                ->default(true)
                ->required(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('User')->searchable()->sortable(),
                TextColumn::make('template_key')->label('Event')->searchable(),
                TextColumn::make('channel')->badge(),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(self::channelOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNotificationPreferences::route('/'),
            'create' => CreateNotificationPreference::route('/create'),
            'edit' => EditNotificationPreference::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function eventOptions(): array
    {
        $options = [];

        foreach (NotificationEventKey::cases() as $case) {
            $options[$case->value] = str($case->value)->replace('.', ' ')->headline()->toString();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function channelOptions(): array
    {
        $options = [];

        foreach (NotificationChannel::cases() as $case) {
            $options[$case->value] = str($case->value)->headline()->toString();
        }

        return $options;
    }
}
