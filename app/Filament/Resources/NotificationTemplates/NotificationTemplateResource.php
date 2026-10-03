<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Forms\Components\NotificationMessageEditor;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateCatalog;
use App\Services\Notifications\NotificationTemplateEditor;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Business view over the system-defined notification messages.
 *
 * Administrators customize the wording of messages the application already
 * sends. What is sent, to whom, in which languages and by which delivery
 * method is fixed by the system, so there is no create/delete flow and no
 * channel, language or technical-name control.
 *
 * @phpstan-import-type EditorConfig from NotificationTemplateEditor
 */
final class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('notification_templates.navigation');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return app(NotificationTemplateEditor::class)->representativeRows(parent::getEloquentQuery());
    }

    #[\Override]
    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof NotificationTemplate
            ? app(NotificationTemplateCatalog::class)->name((string) $record->key)
            : '';
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    #[\Override]
    public static function canDeleteAny(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        $record = $schema->getRecord();

        if (! $record instanceof NotificationTemplate) {
            return $schema->components([]);
        }

        $key = (string) $record->key;
        $config = app(NotificationTemplateEditor::class)->editorConfig($key);

        return $schema->components([
            Toggle::make('is_active')
                ->label(__('notification_templates.status_field.label'))
                ->helperText(__('notification_templates.status_field.help')),
            NotificationMessageEditor::make('content')
                ->config($config)
                ->hiddenLabel()
                ->rule(fn (): Closure => self::contentRule($key, $config)),
        ])->columns(1);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return self::messageTable($table, self::class);
    }

    /**
     * The business list shared by every screen that manages notification messages.
     *
     * @param  class-string<resource>  $resource  the resource whose edit page each row opens
     */
    public static function messageTable(Table $table, string $resource): Table
    {
        $catalog = app(NotificationTemplateCatalog::class);
        $editor = app(NotificationTemplateEditor::class);

        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('notification')
                    ->label(__('notification_templates.columns.notification'))
                    ->state(fn (NotificationTemplate $record): string => $catalog->name((string) $record->key))
                    ->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn('key', $catalog->keysMatching($search))),
                TextColumn::make('when_sent')
                    ->label(__('notification_templates.columns.when'))
                    ->state(fn (NotificationTemplate $record): string => $catalog->description((string) $record->key))
                    ->color('gray')
                    ->wrap(),
                TextColumn::make('languages')
                    ->label(__('notification_templates.columns.languages'))
                    ->state(fn (NotificationTemplate $record): string => implode(' · ', $editor->languages((string) $record->key))),
                TextColumn::make('status')
                    ->label(__('notification_templates.columns.status'))
                    ->state(fn (NotificationTemplate $record): string => $editor->status((string) $record->key))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('notification_templates.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'partial' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_updated')
                    ->label(__('notification_templates.columns.updated'))
                    ->state(fn (NotificationTemplate $record): mixed => $editor->lastUpdated((string) $record->key))
                    ->since(),
            ])
            ->recordActions([
                EditAction::make()->label(__('notification_templates.actions.edit')),
                self::resetAction(),
            ])
            ->recordUrl(fn (NotificationTemplate $record): string => $resource::getUrl('edit', ['record' => $record]));
    }

    public static function resetAction(): Action
    {
        return Action::make('reset_to_default')
            ->label(__('notification_templates.actions.reset'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('notification_templates.actions.reset_heading'))
            ->modalDescription(__('notification_templates.actions.reset_description'))
            ->visible(fn (NotificationTemplate $record): bool => app(NotificationTemplateEditor::class)->hasDefault((string) $record->key))
            ->action(function (NotificationTemplate $record): void {
                $reset = app(NotificationTemplateEditor::class)->resetToDefault((string) $record->key);

                FilamentNotification::make()
                    ->title(__($reset > 0 ? 'notification_templates.actions.reset_done' : 'notification_templates.actions.reset_unavailable'))
                    ->color($reset > 0 ? 'success' : 'danger')
                    ->send();
            });
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * Every message needs wording, and may only use information the notification provides.
     *
     * @param  EditorConfig  $config
     */
    private static function contentRule(string $key, array $config): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($key, $config): void {
            $catalog = app(NotificationTemplateCatalog::class);
            $content = app(NotificationTemplateEditor::class)->normalizeContent($value);
            $languages = [];

            foreach ($config['languages'] as $language) {
                $languages[$language['locale']] = $language['label'];
            }

            foreach ($config['sections'] as $section) {
                foreach (['subject' => $section['subjectLabel'], 'body' => $section['messageLabel']] as $field => $label) {
                    $stored = $content[$section['key']][$field] ?? null;
                    $text = is_string($stored) ? $stored : '';
                    $context = ['language' => $languages[$section['locale']], 'section' => $section['heading'], 'field' => mb_strtolower($label)];

                    if (mb_trim($text) === '') {
                        $fail(__('notification_templates.validation.required', $context));
                    } elseif ($catalog->unsupportedVariables($key, $text) !== []) {
                        $fail(__('notification_templates.validation.unsupported', $context));
                    }
                }
            }
        };
    }
}
