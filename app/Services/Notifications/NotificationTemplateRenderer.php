<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\NotificationTemplate;
use App\Services\Notifications\Data\RenderedNotification;
use DomainException;
use Illuminate\Support\Facades\Log;

final readonly class NotificationTemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(
        NotificationEventKey $key,
        string $locale,
        NotificationChannel $channel,
        array $variables,
    ): RenderedNotification {
        $template = $this->template($key, $locale, $channel);

        $declared = array_values(array_filter(
            $template->variables ?? [],
            static fn (mixed $variable): bool => is_string($variable) && $variable !== '',
        ));

        $provided = array_keys($variables);
        $missing = array_values(array_diff($declared, $provided));
        $extra = array_values(array_diff($provided, $declared));

        if ($missing !== []) {
            throw new DomainException('Missing notification template variables: '.implode(', ', $missing));
        }

        if ($extra !== []) {
            throw new DomainException('Undeclared notification template variables: '.implode(', ', $extra));
        }

        return new RenderedNotification(
            templateKey: $key->value,
            locale: (string) $template->locale,
            subject: $template->subject === null ? null : $this->replace($template->subject, $variables),
            body: $this->replace($template->body, $variables),
        );
    }

    private function template(
        NotificationEventKey $key,
        string $locale,
        NotificationChannel $channel,
    ): NotificationTemplate {
        $template = NotificationTemplate::query()
            ->where('key', $key->value)
            ->where('locale', $locale)
            ->where('channel', $channel->value)
            ->where('is_active', true)
            ->first();

        if ($template instanceof NotificationTemplate) {
            return $template;
        }

        $fallback = (string) config('app.fallback_locale', 'en');

        if ($fallback !== $locale) {
            $template = NotificationTemplate::query()
                ->where('key', $key->value)
                ->where('locale', $fallback)
                ->where('channel', $channel->value)
                ->where('is_active', true)
                ->first();

            if ($template instanceof NotificationTemplate) {
                Log::warning('Notification template locale fallback used.', [
                    'key' => $key->value,
                    'requested_locale' => $locale,
                    'fallback_locale' => $fallback,
                    'channel' => $channel->value,
                ]);

                return $template;
            }
        }

        throw new DomainException(sprintf(
            'No active notification template exists for [%s] [%s] [%s].',
            $key->value,
            $locale,
            $channel->value,
        ));
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function replace(string $value, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $variable = $matches[1];
                $resolved = $variables[$variable] ?? null;

                return $resolved === null ? '' : (string) $resolved;
            },
            $value,
        );
    }
}
