<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationDeliveries;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Notification deliveries';
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('template_key')->label('Event')->searchable()->sortable(),
                TextColumn::make('channel')->badge()->sortable(),
                TextColumn::make('route')->searchable()->placeholder('In-app'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempt')->sortable(),
                TextColumn::make('subject_document_type')->label('Subject type')->toggleable(),
                TextColumn::make('subject_document_id')->label('Subject ID')->toggleable(),
                TextColumn::make('error')->limit(60)->tooltip(fn (NotificationDelivery $record): ?string => $record->error),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('channel')->options(self::channelOptions()),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->visible(fn (NotificationDelivery $record): bool => $record->status === NotificationDeliveryStatus::Failed && $record->attempt < 3)
                    ->action(fn (NotificationDelivery $record): NotificationDelivery => app(NotificationDispatcher::class)->retry($record)),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNotificationDeliveries::route('/'),
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (NotificationDeliveryStatus::cases() as $case) {
            $options[$case->value] = str($case->value)->headline()->toString();
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
