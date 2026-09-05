<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BusinessNotification;
use App\Services\Notifications\Data\RenderedNotification;
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
        bool $sendNow = false,
    ): NotificationDelivery {
        $locale ??= $this->localeFor($notifiable);
        $route = $this->routeFor($notifiable, $channel);

        try {
            $rendered = $this->renderer->render($event, $locale, $channel, $variables);
        } catch (Throwable $throwable) {
            return $this->renderFailure($notifiable, $event->value, $channel, $locale, $route, $variables, $attachments, $subject, $throwable);
        }

        return $this->dispatchRendered(
            $notifiable,
            $event->value,
            $channel,
            $rendered,
            $variables,
            $subject,
            $attachments,
            $sendNow,
        );
    }

    /**
     * Dispatch an explicitly selected content template. CRM campaigns use this
     * path so campaign content remains data, not a hard-coded event template.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    public function dispatchTemplate(
        Model $notifiable,
        NotificationTemplate $template,
        array $variables,
        ?Model $subject = null,
        array $attachments = [],
        bool $sendNow = false,
    ): NotificationDelivery {
        $channel = $template->channel;
        $locale = (string) $template->locale;
        $route = $this->routeFor($notifiable, $channel);

        try {
            $rendered = $this->renderer->renderTemplate($template, $variables);
        } catch (Throwable $throwable) {
            return $this->renderFailure($notifiable, (string) $template->key, $channel, $locale, $route, $variables, $attachments, $subject, $throwable);
        }

        return $this->dispatchRendered(
            $notifiable,
            (string) $template->key,
            $channel,
            $rendered,
            $variables,
            $subject,
            $attachments,
            $sendNow,
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

        $variables = is_array($delivery->variables) ? $delivery->variables : [];
        $rendered = $this->renderer->renderKey(
            (string) $delivery->template_key,
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

        return $this->queue($delivery, $notifiable, $rendered->subject, $rendered->body, $attachments);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    private function dispatchRendered(
        Model $notifiable,
        string $templateKey,
        NotificationChannel $channel,
        RenderedNotification $rendered,
        array $variables,
        ?Model $subject,
        array $attachments,
        bool $sendNow,
    ): NotificationDelivery {
        $route = $this->routeFor($notifiable, $channel);
        $delivery = NotificationDelivery::query()->create([
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'template_key' => $templateKey,
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

        if ($this->preferenceDisables($notifiable, $templateKey, $channel)
            || $this->routeSuppressed($channel, $route)) {
            $delivery->forceFill([
                'status' => NotificationDeliveryStatus::Suppressed,
                'queued_at' => null,
            ])->save();

            return $delivery->refresh();
        }

        return $this->queue($delivery, $notifiable, $rendered->subject, $rendered->body, $attachments, $sendNow);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    private function renderFailure(
        Model $notifiable,
        string $templateKey,
        NotificationChannel $channel,
        string $locale,
        ?string $route,
        array $variables,
        array $attachments,
        ?Model $subject,
        Throwable $throwable,
    ): NotificationDelivery {
        return NotificationDelivery::query()->create([
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'template_key' => $templateKey,
            'channel' => $channel,
            'locale' => $locale,
            'route' => $route,
            'subject_document_type' => $subject?->getMorphClass(),
            'subject_document_id' => $subject?->getKey(),
            'status' => NotificationDeliveryStatus::Failed,
            'attempt' => 1,
            'variables' => $variables,
            'attachments' => $attachments,
            'error' => mb_substr($throwable->getMessage(), 0, 500),
            'failed_at' => now(),
        ]);
    }

    /** @param list<array{path:string,name?:string,mime?:string}> $attachments */
    private function queue(
        NotificationDelivery $delivery,
        Model $notifiable,
        ?string $subject,
        string $body,
        array $attachments = [],
        bool $sendNow = false,
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
                $mailRecipient = Notification::route('mail', (string) $delivery->route);

                if ($sendNow) {
                    Notification::sendNow($mailRecipient, $notification);
                } else {
                    $mailRecipient->notify($notification);
                }
            } elseif ($sendNow) {
                Notification::sendNow($notifiable, $notification);
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

    private function preferenceDisables(Model $notifiable, string $templateKey, NotificationChannel $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        return NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('template_key', $templateKey)
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

        $attribute = match ($channel) {
            NotificationChannel::Mail => 'email',
            NotificationChannel::Sms, NotificationChannel::Whatsapp => 'phone',
            NotificationChannel::Database => 'email',
        };
        $route = $notifiable->getAttribute($attribute);

        if (! is_string($route) || mb_trim($route) === '') {
            return null;
        }

        if ($channel === NotificationChannel::Mail && filter_var($route, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower(mb_trim($route));
    }

    private function localeFor(Model $notifiable): string
    {
        $locale = $notifiable->getAttribute('preferred_language');

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('app.locale', 'en');
    }
}
