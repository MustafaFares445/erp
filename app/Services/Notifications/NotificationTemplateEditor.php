<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplateSeeder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Application layer that presents the `event + locale + channel` template rows as
 * one business notification. Only the wording and the on/off state can change:
 * the event, locale and channel of a row are system-defined and never written here.
 *
 * @phpstan-type EditorConfig array{
 *     languages: list<array{locale: string, label: string, rtl: bool}>,
 *     sections: list<array{key: string, locale: string, rtl: bool, channel: string, heading: string, subjectLabel: string, messageLabel: string}>,
 *     formats: list<array{channel: string, label: string}>,
 *     information: array<string, list<array{name: string, label: string}>>,
 *     samples: array<string, array<string, string>>,
 *     texts: array<string, string>,
 * }
 */
final readonly class NotificationTemplateEditor
{
    public function __construct(
        private NotificationTemplateCatalog $catalog,
        private NotificationTemplateSeeder $seeder,
    ) {}

    /**
     * One representative row per notification, used as the record behind the list and edit screens.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>|null  $onlyKeys  limits the notifications to a subset (for example invoice documents)
     * @return Builder<Model>
     */
    public function representativeRows(Builder $query, ?array $onlyKeys = null): Builder
    {
        $keys = $onlyKeys === null ? $this->managedKeys() : array_values(array_intersect($this->managedKeys(), $onlyKeys));

        return $query
            ->whereIn('key', $keys)
            ->whereIn('channel', $this->managedChannels())
            ->whereIn('id', NotificationTemplate::query()
                ->whereIn('key', $keys)
                ->whereIn('channel', $this->managedChannels())
                ->selectRaw('MIN(id)')
                ->groupBy('key'));
    }

    /** @return Collection<int, NotificationTemplate> English first, then Arabic; email before in-app. */
    public function rows(string $key): Collection
    {
        return NotificationTemplate::query()
            ->where('key', $key)
            ->whereIn('channel', $this->managedChannels())
            ->get()
            ->sortBy(fn (NotificationTemplate $row): string => ($row->locale === 'en' ? '0' : '1').$row->locale.(int) array_search($row->channel, NotificationTemplateCatalog::MANAGED_CHANNELS, true))
            ->values();
    }

    /**
     * Narrows raw form state to content keyed by "<locale>_<channel>".
     *
     * @return array<string, array<string, mixed>>
     */
    public function normalizeContent(mixed $content): array
    {
        $normalized = [];

        foreach (is_array($content) ? $content : [] as $key => $values) {
            if (is_string($key) && is_array($values)) {
                $normalized[$key] = array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $normalized;
    }

    public function contentKey(NotificationTemplate $row): string
    {
        return $row->locale.'_'.$row->channel->value;
    }

    /** @return array{is_active: bool, content: array<string, array{subject: ?string, body: string}>} */
    public function formState(string $key): array
    {
        $rows = $this->rows($key);
        $content = [];

        foreach ($rows as $row) {
            $content[$this->contentKey($row)] = ['subject' => $row->subject, 'body' => $row->body];
        }

        return [
            'is_active' => $rows->contains(fn (NotificationTemplate $row): bool => $row->is_active),
            'content' => $content,
        ];
    }

    /** @return list<string> language labels the notification is written in */
    public function languages(string $key): array
    {
        $labels = [];

        foreach ($this->rows($key) as $row) {
            $labels[$row->locale] = $this->catalog->languageLabel($row->locale);
        }

        return array_values($labels);
    }

    /** @return 'active'|'inactive'|'partial' */
    public function status(string $key): string
    {
        $rows = $this->rows($key);
        $active = $rows->where('is_active', true)->count();

        return match (true) {
            $active === 0 => 'inactive',
            $active === $rows->count() => 'active',
            default => 'partial',
        };
    }

    public function lastUpdated(string $key): ?DateTimeInterface
    {
        $latest = $this->rows($key)->max('updated_at');

        return $latest instanceof DateTimeInterface ? $latest : null;
    }

    /**
     * Writes only the subject, body and active state of the existing rows.
     *
     * @param  array<string, array<string, mixed>>  $content  keyed by "<locale>_<channel>"
     */
    public function save(string $key, bool $active, array $content): void
    {
        DB::transaction(function () use ($key, $active, $content): void {
            foreach ($this->rows($key) as $row) {
                $values = $content[$this->contentKey($row)] ?? [];
                $attributes = ['is_active' => $active];

                if (array_key_exists('subject', $values) && (is_string($values['subject']) || $values['subject'] === null)) {
                    $attributes['subject'] = $values['subject'];
                }

                if (is_string($values['body'] ?? null)) {
                    $attributes['body'] = $values['body'];
                }

                $row->update($attributes);
            }
        });
    }

    /** Replaces the wording of every row with the seeded system default. Returns the number of rows reset. */
    public function resetToDefault(string $key): int
    {
        $reset = 0;

        DB::transaction(function () use ($key, &$reset): void {
            foreach ($this->rows($key) as $row) {
                $default = $this->seeder->defaultFor($key, $row->locale, $row->channel);

                if ($default === null) {
                    continue;
                }

                $row->update($default);
                $reset++;
            }
        });

        return $reset;
    }

    public function hasDefault(string $key): bool
    {
        return $this->rows($key)->contains(
            fn (NotificationTemplate $row): bool => $this->seeder->defaultFor($key, $row->locale, $row->channel) !== null,
        );
    }

    /**
     * Everything the message editor screen needs, in business terms: languages, the
     * fixed message formats per language, the information that can be inserted and
     * realistic sample values for the live preview.
     *
     * @return EditorConfig
     */
    public function editorConfig(string $key): array
    {
        $languages = [];
        $sections = [];
        $formats = [];
        $information = [];
        $samples = [];

        foreach ($this->rows($key) as $row) {
            $locale = $row->locale;

            $languages[$locale] ??= [
                'locale' => $locale,
                'label' => $this->catalog->languageLabel($locale),
                'rtl' => $this->catalog->isRightToLeft($locale),
            ];
            $information[$locale] ??= $this->informationItems($key, $locale);
            $samples[$locale] ??= $this->catalog->sampleValues($key, $locale);
            $formats[$row->channel->value] ??= [
                'channel' => $row->channel->value,
                'label' => __('notification_templates.format_tabs.'.$row->channel->value),
            ];
            $sections[] = [
                'key' => $this->contentKey($row),
                'locale' => $locale,
                'rtl' => $this->catalog->isRightToLeft($locale),
                'channel' => $row->channel->value,
                'heading' => $this->catalog->formatLabel($row->channel),
                'subjectLabel' => $this->catalog->subjectLabel($row->channel),
                'messageLabel' => __('notification_templates.message'),
            ];
        }

        return [
            'languages' => array_values($languages),
            'sections' => $sections,
            'formats' => array_values($formats),
            'information' => $information,
            'samples' => $samples,
            'texts' => [
                'content' => __('notification_templates.editor.content'),
                'preview' => __('notification_templates.editor.preview'),
                'insert' => __('notification_templates.editor.insert'),
                'preview_hint' => __('notification_templates.editor.preview_hint'),
                'email_hint' => __('notification_templates.editor.email_hint'),
                'just_now' => __('notification_templates.editor.just_now'),
            ],
        ];
    }

    /** @return list<array{name: string, label: string}> */
    private function informationItems(string $key, string $locale): array
    {
        $items = [];

        foreach ($this->catalog->variables($key, $locale) as $name => $label) {
            $items[] = ['name' => $name, 'label' => $label];
        }

        return $items;
    }

    /** @return list<string> */
    private function managedKeys(): array
    {
        return array_map(static fn (NotificationEventKey $event): string => $event->value, NotificationEventKey::cases());
    }

    /** @return list<string> */
    private function managedChannels(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, NotificationTemplateCatalog::MANAGED_CHANNELS);
    }
}
