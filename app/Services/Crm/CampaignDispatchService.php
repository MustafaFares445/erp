<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CampaignChannel;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Events\CampaignCompleted;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CampaignDispatchService
{
    public function __construct(private NotificationDispatcher $notifications) {}

    public function dispatch(Campaign $campaign, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('send', $campaign);

        $campaign = DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->sole();

            if (! in_array($locked->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
                throw new DomainException('Only a draft or scheduled campaign can start sending.');
            }

            if ($locked->recipients()->doesntExist()) {
                throw new DomainException('A campaign requires recipients before sending.');
            }

            $locked->forceFill([
                'status' => CampaignStatus::Sending,
                'started_at' => now(),
            ])->save();

            return $locked->refresh()->load(['contentTemplate', 'recipients.recipient']);
        });

        $template = $campaign->contentTemplate;
        $notificationChannel = $this->notificationChannel($campaign->channel);

        foreach ($campaign->recipients()->with('recipient')->orderBy('id')->get() as $recipient) {
            if ($recipient->send_status !== CampaignSendStatus::Pending) {
                continue;
            }
            if (! $template instanceof NotificationTemplate) {
                $this->failRecipient($recipient, 'Campaign content template is missing.');

                continue;
            }

            if (! $notificationChannel instanceof NotificationChannel) {
                $this->failRecipient($recipient, 'This campaign channel has no delivery provider.');

                continue;
            }

            if ($template->channel !== $notificationChannel) {
                $this->failRecipient($recipient, 'Campaign template channel does not match the campaign channel.');

                continue;
            }

            $notifiable = $recipient->recipient;
            if (! $notifiable instanceof Lead && ! $notifiable instanceof CustomerProfile) {
                $this->failRecipient($recipient, 'Campaign recipient no longer exists.');

                continue;
            }

            $delivery = $this->notifications->dispatchTemplate(
                $notifiable,
                $template,
                $this->templateVariables($template, $campaign, $notifiable),
                $campaign,
                sendNow: true,
            );

            $status = match ($delivery->status) {
                NotificationDeliveryStatus::Failed, NotificationDeliveryStatus::Bounced => CampaignSendStatus::Failed,
                NotificationDeliveryStatus::Suppressed => CampaignSendStatus::Suppressed,
                NotificationDeliveryStatus::Queued, NotificationDeliveryStatus::Sent => CampaignSendStatus::Sent,
            };

            $recipient->forceFill([
                'send_status' => $status,
                'send_error' => $delivery->error,
                'sent_at' => $status === CampaignSendStatus::Sent ? now() : null,
                'notification_delivery_id' => $delivery->getKey(),
            ])->save();
        }

        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->sole();
            $sent = $locked->recipients()->where('send_status', CampaignSendStatus::Sent->value)->count();
            $failed = $locked->recipients()->where('send_status', CampaignSendStatus::Failed->value)->count();

            $locked->forceFill([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => [
                    'status' => CampaignStatus::Completed->value,
                    'sent_count' => $sent,
                    'failed_count' => $failed,
                ]])
                ->withProperties(['source_channel' => 'queue', 'ip_address' => request()->ip()])
                ->log('crm.campaign.completed');

            DB::afterCommit(static fn () => CampaignCompleted::dispatch($locked->refresh(), $sent, $failed));

            return $locked->refresh();
        });
    }

    private function notificationChannel(CampaignChannel $channel): ?NotificationChannel
    {
        return match ($channel) {
            CampaignChannel::Email => NotificationChannel::Mail,
            CampaignChannel::Sms => NotificationChannel::Sms,
            CampaignChannel::Whatsapp => NotificationChannel::Whatsapp,
            CampaignChannel::Event, CampaignChannel::Other => null,
        };
    }

    /** @return array<string, scalar|null> */
    private function templateVariables(NotificationTemplate $template, Campaign $campaign, Model $recipient): array
    {
        $firstName = $this->stringAttribute($recipient, 'first_name');
        $lastName = $this->stringAttribute($recipient, 'last_name');
        $company = $this->stringAttribute($recipient, 'company_name');
        $customerCode = $this->stringAttribute($recipient, 'customer_code');
        $person = mb_trim(implode(' ', array_values(array_filter([$firstName, $lastName], static fn (?string $value): bool => $value !== null && $value !== ''))));

        /** @var array<string, scalar|null> $available */
        $available = [
            'recipient_name' => $person !== '' ? $person : ($company ?? $customerCode ?? 'Customer'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => $company,
            'email' => $this->stringAttribute($recipient, 'email'),
            'phone' => $this->stringAttribute($recipient, 'phone'),
            'campaign_name' => $this->stringAttribute($campaign, 'name'),
            'campaign_number' => $this->stringAttribute($campaign, 'campaign_number'),
        ];
        $variables = [];

        $templateVariables = $template->variables;
        if (! is_array($templateVariables)) {
            return $variables;
        }

        foreach ($templateVariables as $name) {
            if (is_string($name) && $name !== '') {
                $variables[$name] = $available[$name] ?? null;
            }
        }

        return $variables;
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return is_string($value) ? $value : null;
    }

    private function failRecipient(CampaignRecipient $recipient, string $message): void
    {
        $recipient->forceFill([
            'send_status' => CampaignSendStatus::Failed,
            'send_error' => mb_substr($message, 0, 255),
        ])->save();
    }
}
