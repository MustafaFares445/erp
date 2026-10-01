<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignChannel;
use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
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
                ->options(collect(CampaignChannel::cases())
                    ->filter(fn (CampaignChannel $channel): bool => $channel->supportsDelivery())
                    ->mapWithKeys(fn (CampaignChannel $channel): array => [$channel->value => __(str($channel->value)->headline()->toString())])
                    ->all())
                ->helperText(__('Only channels with a configured delivery provider can be sent from the CRM.'))
                ->live()
                ->afterStateUpdated(static function (callable $set): void {
                    $set('content_template_id', null);
                })
                ->required(),
            Select::make('content_template_id')
                ->label(__('Content template'))
                ->options(static function (callable $get): array {
                    $channel = $get('channel');
                    if (! is_string($channel)) {
                        return [];
                    }

                    $notificationChannel = match (CampaignChannel::tryFrom($channel)) {
                        CampaignChannel::Email => NotificationChannel::Mail,
                        CampaignChannel::Sms => NotificationChannel::Sms,
                        CampaignChannel::Whatsapp => NotificationChannel::Whatsapp,
                        default => null,
                    };

                    if (! $notificationChannel instanceof NotificationChannel) {
                        return [];
                    }

                    return NotificationTemplate::query()
                        ->where('channel', $notificationChannel->value)
                        ->where('is_active', true)
                        ->orderBy('key')
                        ->get()
                        ->mapWithKeys(static function (NotificationTemplate $record): array {
                            $key = $record->getKey();
                            if (! is_int($key) && ! is_string($key)) {
                                return [];
                            }

                            return [$key => self::templateLabel($record)];
                        })
                        ->all();
                })
                ->disabled(static fn (callable $get): bool => ! is_string($get('channel')))
                ->searchable(),
        ])->columns(2);
    }

    private static function templateLabel(NotificationTemplate $template): string
    {
        $key = $template->getAttribute('key');
        $locale = $template->getAttribute('locale');

        return sprintf(
            '%s · %s · %s',
            is_string($key) ? $key : '',
            is_string($locale) ? $locale : '',
            $template->channel->value,
        );
    }
}
