<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignChannel;
use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                ->relationship('contentTemplate', 'key', modifyQueryUsing: function (Builder $query): Builder {
                    /** @var Builder<NotificationTemplate> $query */
                    return $query->whereIn('channel', [NotificationChannel::Mail->value, NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value]);
                })
                ->getOptionLabelFromRecordUsing(fn (NotificationTemplate $record): string => self::templateLabel($record))
                ->searchable(['key', 'subject'])
                ->preload(),
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
