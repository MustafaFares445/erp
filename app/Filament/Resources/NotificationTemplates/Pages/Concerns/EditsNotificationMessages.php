<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Pages\Concerns;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateCatalog;
use App\Services\Notifications\NotificationTemplateEditor;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit-page behaviour shared by every screen that manages system notification
 * messages: the wording per language and message format, plus the on/off state.
 * The record behind the page is only the entry point; the event, locale and
 * channel of every underlying row are never read from the request.
 */
trait EditsNotificationMessages
{
    #[\Override]
    public function getTitle(): string
    {
        return __('notification_templates.edit_title');
    }

    #[\Override]
    public function getHeading(): string
    {
        return app(NotificationTemplateCatalog::class)->name($this->eventKey());
    }

    #[\Override]
    public function getSubheading(): string
    {
        return app(NotificationTemplateCatalog::class)->description($this->eventKey());
    }

    /** @return array<int|string, string> */
    #[\Override]
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $label = $resource::getNavigationLabel();

        return [
            $this->getResourceUrl() => is_string($label) ? $label : '',
            $this->getHeading(),
        ];
    }

    #[\Override]
    public function areFormActionsSticky(): bool
    {
        return true;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                NotificationTemplateResource::resetAction()->after(fn () => $this->fillForm()),
            ])
                ->label(__('notification_templates.actions.more'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(NotificationTemplateEditor::class)->formState($this->eventKey());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $editor = app(NotificationTemplateEditor::class);

        $editor->save($this->eventKey(), (bool) ($data['is_active'] ?? false), $editor->normalizeContent($data['content'] ?? null));

        return $record->refresh();
    }

    private function eventKey(): string
    {
        $record = $this->getRecord();

        return $record instanceof NotificationTemplate ? (string) $record->key : '';
    }
}
