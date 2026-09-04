<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Actions;

use App\Enums\CampaignStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Crm\CampaignService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CampaignActions
{
    public static function buildRecipients(): Action
    {
        return Action::make('build_recipients')
            ->icon('heroicon-o-users')
            ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true))
            ->schema([
                Checkbox::make('include_leads')->default(true),
                Checkbox::make('include_customers')->default(true),
                Select::make('lead_statuses')->multiple()->options(collect(LeadStatus::cases())->filter(fn (LeadStatus $status): bool => ! $status->isTerminal())->mapWithKeys(fn (LeadStatus $status): array => [$status->value => str($status->value)->headline()->toString()])->all()),
                Select::make('lead_sources')->multiple()->options(collect(LeadSource::cases())->mapWithKeys(fn (LeadSource $source): array => [$source->value => str($source->value)->replace('_', ' ')->headline()->toString()])->all()),
            ])
            ->action(function (Campaign $record, array $data): void {
                try {
                    $campaign = app(CampaignService::class)->buildRecipients($record, $data, self::actor());
                    Notification::make()->success()->title('Recipient list built')->body($campaign->recipients_count.' recipient(s).')->send();
                } catch (Throwable $throwable) {
                    self::error($throwable);
                }
            });
    }

    public static function schedule(): Action
    {
        return Action::make('schedule')
            ->icon('heroicon-o-clock')
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Draft)
            ->schema([DateTimePicker::make('scheduled_at')->required()->minDate(now())])
            ->action(function (Campaign $record, array $data): void {
                try {
                    app(CampaignService::class)->schedule($record, Carbon::parse((string) $data['scheduled_at']), self::actor());
                    Notification::make()->success()->title('Campaign scheduled')->send();
                } catch (Throwable $throwable) {
                    self::error($throwable);
                }
            });
    }

    public static function send(): Action
    {
        return Action::make('send_campaign')
            ->label('Send')
            ->color('success')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true) && (auth()->user()?->can('send', $record) ?? false))
            ->requiresConfirmation()
            ->action(function (Campaign $record): void {
                try {
                    app(CampaignService::class)->queueSend($record, self::actor());
                    Notification::make()->success()->title('Campaign queued for sending')->send();
                } catch (Throwable $throwable) {
                    self::error($throwable);
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (Campaign $record): bool => ! $record->status->isTerminal())
            ->requiresConfirmation()
            ->action(function (Campaign $record): void {
                try {
                    app(CampaignService::class)->cancel($record, self::actor());
                    Notification::make()->success()->title('Campaign cancelled')->send();
                } catch (Throwable $throwable) {
                    self::error($throwable);
                }
            });
    }

    public static function downloadSendLog(): Action
    {
        return Action::make('download_send_log')
            ->label('Send log')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(fn (Campaign $record): StreamedResponse => response()->streamDownload(function () use ($record): void {
                $handle = fopen('php://output', 'wb');
                if ($handle === false) {
                    return;
                }
                fputcsv($handle, ['recipient_type', 'recipient_id', 'email', 'phone', 'status', 'error', 'sent_at', 'delivery_id'], escape: '\\');
                foreach ($record->recipients()->orderBy('id')->cursor() as $recipient) {
                    fputcsv($handle, [$recipient->recipient_type, $recipient->recipient_id, $recipient->email, $recipient->phone, $recipient->send_status->value, $recipient->send_error, $recipient->sent_at?->toDateTimeString(), $recipient->notification_delivery_id], escape: '\\');
                }
                fclose($handle);
            }, $record->campaign_number.'-send-log.csv'));
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated CRM user is required.');
        }

        return $actor;
    }

    private static function error(Throwable $throwable): void
    {
        Notification::make()->danger()->title('Campaign action failed')->body($throwable->getMessage())->send();
    }
}
