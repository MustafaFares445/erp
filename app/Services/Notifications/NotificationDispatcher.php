<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationDigestCadence;
use App\Enums\NotificationEventKey;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BusinessNotification;
use App\Services\Notifications\Data\RenderedNotification;
use Carbon\CarbonImmutable;
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

        return $this->release($delivery, true);
    }

    public function releaseDeferred(NotificationDelivery $delivery, bool $sendNow = false): NotificationDelivery
    {
        if ($delivery->status !== NotificationDeliveryStatus::Deferred) {
            throw new DomainException('Only deferred notification deliveries can be released.');
        }

        return $this->release($delivery, $sendNow);
    }

    /**
     * Sends a delivery whose subject and body were already assembled by the
     * caller (e.g. a digest combining several source deliveries), keeping the
     * Mail/Notification facades confined to this dispatcher.
     *
     * @param  list<array{path:string,name?:string,mime?:string}>  $attachments
     */
    public function deliverPrepared(
        NotificationDelivery $delivery,
        Model $notifiable,
        ?string $subject,
        string $body,
        array $attachments = [],
    ): NotificationDelivery {
        return $this->queue($delivery, $notifiable, $subject, $body, $attachments);
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
        $preference = $this->preferenceFor($notifiable, $templateKey, $channel);
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

        if ($preference?->enabled === false) {
            return $this->suppress($delivery, 'preference_disabled');
        }

        if ($this->routeSuppressed($channel, $route)) {
            return $this->suppress($delivery, 'communication_suppressed');
        }

        if ($this->rateLimitExceeded($delivery)) {
            return $this->suppress($delivery, 'rate_limited');
        }

        $deferral = $this->deferralFor($preference);
        if ($deferral !== null) {
            [$decision, $until] = $deferral;

            $delivery->forceFill([
                'status' => NotificationDeliveryStatus::Deferred,
                'decision' => $decision,
                'queued_at' => null,
                'deferred_until' => $until,
            ])->save();

            return $delivery->refresh();
        }

        return $this->queue($delivery, $notifiable, $rendered->subject, $rendered->body, $attachments, $sendNow);
    }

    private function release(NotificationDelivery $delivery, bool $sendNow): NotificationDelivery
    {
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
            'decision' => null,
            'attempt' => $delivery->status === NotificationDeliveryStatus::Failed ? $delivery->attempt + 1 : $delivery->attempt,
            'error' => null,
            'queued_at' => now(),
            'deferred_until' => null,
            'failed_at' => null,
        ])->save();

        $attachments = is_array($delivery->attachments) ? $delivery->attachments : [];

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

    private function suppress(NotificationDelivery $delivery, string $decision): NotificationDelivery
    {
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Suppressed,
            'decision' => $decision,
            'queued_at' => null,
            'deferred_until' => null,
        ])->save();

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

    private function preferenceFor(Model $notifiable, string $templateKey, NotificationChannel $channel): ?NotificationPreference
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        return NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('template_key', $templateKey)
            ->where('channel', $channel->value)
            ->first();
    }

    private function rateLimitExceeded(NotificationDelivery $delivery): bool
    {
        $limit = NotificationTemplate::query()
            ->where('key', $delivery->template_key)
            ->where('locale', $delivery->locale)
            ->where('channel', $delivery->channel->value)
            ->value('rate_limit_per_hour');

        if (! is_numeric($limit) || (int) $limit <= 0) {
            return false;
        }

        return NotificationDelivery::query()
            ->where('notifiable_type', $delivery->notifiable_type)
            ->where('notifiable_id', $delivery->notifiable_id)
            ->where('template_key', $delivery->template_key)
            ->where('channel', $delivery->channel->value)
            ->whereKeyNot($delivery->getKey())
            ->where('created_at', '>=', now()->subHour())
            ->whereIn('status', [
                NotificationDeliveryStatus::Queued->value,
                NotificationDeliveryStatus::Sent->value,
            ])
            ->count() >= (int) $limit;
    }

    /** @return array{string, CarbonImmutable}|null */
    private function deferralFor(?NotificationPreference $preference): ?array
    {
        if (! $preference instanceof NotificationPreference) {
            return null;
        }

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $cadence = $preference->digest_cadence ?? NotificationDigestCadence::Immediate;

        if ($cadence === NotificationDigestCadence::Daily) {
            return ['digest_daily', $now->addDay()->startOfDay()->addHours(8)];
        }

        if ($cadence === NotificationDigestCadence::Weekly) {
            return ['digest_weekly', $now->next('Monday')->startOfDay()->addHours(8)];
        }

        $quietEnd = $this->quietHoursEnd($preference, $now);

        return $quietEnd instanceof CarbonImmutable ? ['quiet_hours', $quietEnd] : null;
    }

    private function quietHoursEnd(NotificationPreference $preference, CarbonImmutable $now): ?CarbonImmutable
    {
        $startValue = $preference->quiet_hours_start;
        $endValue = $preference->quiet_hours_end;

        if (! is_string($startValue) || ! is_string($endValue) || $startValue === '' || $endValue === '') {
            return null;
        }

        [$startHour, $startMinute] = array_map(intval(...), array_slice(explode(':', $startValue), 0, 2));
        [$endHour, $endMinute] = array_map(intval(...), array_slice(explode(':', $endValue), 0, 2));
        $start = $now->setTime($startHour, $startMinute);
        $end = $now->setTime($endHour, $endMinute);

        if ($start->lessThan($end)) {
            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end) ? $end : null;
        }

        if ($now->greaterThanOrEqualTo($start)) {
            return $end->addDay();
        }

        return $now->lessThan($end) ? $end : null;
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
