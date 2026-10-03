<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Services\Notifications\NotificationTemplateEditor;
use Filament\Forms\Components\Field;
use LogicException;

/**
 * Two-column message editor (wording on the left, live preview on the right).
 * Dynamic information is edited as inline pills; the state keeps the backend
 * placeholder text, keyed by "<locale>_<channel>" then "subject" / "body".
 *
 * @phpstan-import-type EditorConfig from NotificationTemplateEditor
 */
final class NotificationMessageEditor extends Field
{
    protected string $view = 'filament.forms.components.notification-message-editor';

    /** @var EditorConfig|null */
    private ?array $config = null;

    /** @param EditorConfig $config */
    public function config(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /** @return EditorConfig */
    public function getConfig(): array
    {
        return $this->config ?? throw new LogicException('The message editor needs its configuration.');
    }
}
