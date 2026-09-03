<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\BusinessNotification;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

final readonly class NotificationDispatcher
{
    public function __construct(
        private NotificationTemplateRenderer $renderer,
    ) {}

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    public function dispatch(
        Model $notifiable,
        NotificationEventKey $event,
        array $variables,
        ?Model $subject = null,
        NotificationChannel $channel = NotificationChannel::Mail,
        ?string $locale = null,
        array $attachments = [],
    ): NotificationDelivery {
        $locale ??= $this->localeFor($notifiable);
        $rendered = $this->renderer->render($event, $locale, $channel, $variables);
        $route = $this->routeFor($notifiable, $channel);

        $delivery = NotificationDelivery::query()->create([
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'template_key' => $event->value,
            'channel' => $channel,
            'locale' => $rendered->locale,
            'route' => $route,
            'subject_document_type' => $subject?->getMorphClass(),
            'subject_document_id' => $subject?->getKey(),
            'status' => NotificationDeliveryStatus::Queued,
            'attempt' => 1,
            'variables' => $variables,
            'attachments' => $attachments,
            'queued_at' => now(),
        ]);

        if ($this->preferenceDisables($notifiable, $event, $channel)
            || $this->routeSuppressed($channel, $route)) {
            $delivery->forceFill([
                'status' => NotificationDeliveryStatus::Suppressed,
                'queued_at' => null,
            ])->save();

            return $delivery->refresh();
        }

        return $this->queue(
            $delivery,
            $notifiable,
            $rendered->subject,
            $rendered->body,
            $attachments,
        );
    }

    public function retry(NotificationDelivery $delivery): NotificationDelivery
    {
        if ($delivery->status !== NotificationDeliveryStatus::Failed || $delivery->attempt >= 3) {
            throw new DomainException('Only failed notification deliveries below the retry cap can be re-queued.');
        }

        $notifiable = $delivery->notifiable;

        if (! $notifiable instanceof Model) {
            throw new DomainException('The notification recipient no longer exists.');
        }

        $event = NotificationEventKey::from((string) $delivery->template_key);
        $variables = is_array($delivery->variables) ? $delivery->variables : [];
        $rendered = $this->renderer->render(
            $event,
            (string) $delivery->locale,
            $delivery->channel,
            $variables,
        );

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Queued,
            'attempt' => $delivery->attempt + 1,
            'error' => null,
            'queued_at' => now(),
            'failed_at' => null,
        ])->save();

        $attachments = is_array($delivery->attachments) ? $delivery->attachments : [];

        return $this->queue(
            $delivery,
            $notifiable,
            $rendered->subject,
            $rendered->body,
            $attachments,
        );
    }

    /**
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    private function queue(
        NotificationDelivery $delivery,
        Model $notifiable,
        ?string $subject,
        string $body,
        array $attachments = [],
    ): NotificationDelivery {
        if (in_array($delivery->channel, [NotificationChannel::Sms, NotificationChannel::Whatsapp], true)) {
            return $this->fail($delivery, 'No provider is configured for the '.$delivery->channel->value.' notification channel.');
        }

        if ($delivery->channel === NotificationChannel::Mail && $delivery->route === null) {
            return $this->fail($delivery, 'The notification recipient has no valid email route.');
        }

        if ($delivery->channel === NotificationChannel::Database && ! $notifiable instanceof User) {
            return $this->fail($delivery, 'Database notifications require an application user recipient.');
        }

        $notification = new BusinessNotification(
            deliveryId: (int) $delivery->getKey(),
            channel: $delivery->channel,
            subject: $subject,
            body: $body,
            attachments: $attachments,
        );

        try {
            if ($delivery->channel === NotificationChannel::Mail) {
                Notification::route('mail', (string) $delivery->route)->notify($notification);
            } else {
                $notifiable->notify($notification);
            }
        } catch (Throwable $throwable) {
            return $this->fail($delivery, $throwable->getMessage());
        }

        return $delivery->refresh();
    }

    private function fail(NotificationDelivery $delivery, string $error): NotificationDelivery
    {
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'error' => mb_substr($error, 0, 500),
            'failed_at' => now(),
        ])->save();

        return $delivery->refresh();
    }

    private function preferenceDisables(
        Model $notifiable,
        NotificationEventKey $event,
        NotificationChannel $channel,
    ): bool {
        if (! $notifiable instanceof User) {
            return false;
        }

        return NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('template_key', $event->value)
            ->where('channel', $channel->value)
            ->where('enabled', false)
            ->exists();
    }

    private function routeSuppressed(NotificationChannel $channel, ?string $route): bool
    {
        if ($route === null || $channel === NotificationChannel::Database) {
            return false;
        }

        return DB::table('communication_suppressions')
            ->where('channel', $channel->value)
            ->where('address', mb_strtolower(mb_trim($route)))
            ->exists();
    }

    private function routeFor(Model $notifiable, NotificationChannel $channel): ?string
    {
        if ($channel === NotificationChannel::Database) {
            return null;
        }

        $email = $notifiable->getAttribute('email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower(mb_trim($email));
    }

    private function localeFor(Model $notifiable): string
    {
        $locale = $notifiable->getAttribute('preferred_language');

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('app.locale', 'en');
    }
}
