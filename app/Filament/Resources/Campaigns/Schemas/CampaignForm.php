<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignChannel;
use App\Enums\NotificationChannel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('channel')
                ->options(collect(CampaignChannel::cases())->mapWithKeys(fn (CampaignChannel $channel): array => [$channel->value => str($channel->value)->headline()->toString()])->all())
                ->required(),
            Select::make('content_template_id')
                ->label('Content template')
                ->relationship('contentTemplate', 'key', modifyQueryUsing: fn ($query) => $query->whereIn('channel', [NotificationChannel::Mail->value, NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value]))
                ->getOptionLabelFromRecordUsing(fn ($record): string => sprintf('%s · %s · %s', $record->key, $record->locale, $record->channel->value))
                ->searchable(['key', 'subject'])
                ->preload(),
        ])->columns(2);
    }
}
