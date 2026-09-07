<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateRenderer;
use BackedEnum;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Document-layout content for invoice PDFs/emails (WP-3.7, GAP-UI-07,
 * MD-08) — a thin resource over the same {@see NotificationTemplate} table
 * WP-2.10 already built, scoped to the invoice document events, because a
 * document-configuration screen owned by a user (not a developer) does not
 * need a second table or a second rendering path. A `restore_default`
 * action recovers a broken edit from {@see NotificationTemplateSeeder}'s
 * canonical copy, since editing the only copy with no fallback is how a
 * broken edit becomes a production incident.
 */
final class DocumentTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    /** @var list<NotificationEventKey> */
    private const array DOCUMENT_EVENTS = [
        NotificationEventKey::InvoiceIssued,
        NotificationEventKey::InvoiceOverdue7,
        NotificationEventKey::InvoiceOverdue30,
        NotificationEventKey::InvoiceOverdue60,
    ];

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.document_templates');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('key', array_map(
            static fn (NotificationEventKey $event): string => $event->value,
            self::DOCUMENT_EVENTS,
        ));
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('key')
                ->label('Document')
                ->options(self::eventOptions())
                ->searchable()
                ->required(),
            Select::make('locale')
                ->options(['en' => 'English', 'ar' => 'Arabic'])
                ->required(),
            Select::make('channel')
                ->options(self::channelOptions())
                ->required(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            TextInput::make('subject')
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('body')
                ->required()
                ->rows(6)
                ->columnSpanFull(),
            TagsInput::make('variables')
                ->helperText('Declare every {{ variable }} used by the subject or body.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('Document')->badge()->searchable()->sortable(),
                TextColumn::make('locale')->badge()->sortable(),
                TextColumn::make('channel')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('locale')->options(['en' => 'English', 'ar' => 'Arabic']),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->action(function (NotificationTemplate $record): void {
                        $variables = array_fill_keys($record->variables ?? [], 'Sample');
                        $rendered = app(NotificationTemplateRenderer::class)->render(
                            NotificationEventKey::from((string) $record->key),
                            (string) $record->locale,
                            $record->channel,
                            $variables,
                        );

                        FilamentNotification::make()
                            ->title($rendered->subject ?? 'Document preview')
                            ->body($rendered->body)
                            ->info()
                            ->send();
                    }),
                Action::make('restore_default')
                    ->label('Restore default')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()
                    ->modalDescription('Replaces the subject, body, and variables with the original seeded content. This cannot be undone.')
                    ->visible(fn (NotificationTemplate $record): bool => self::defaultFor($record) !== null)
                    ->action(function (NotificationTemplate $record): void {
                        $default = self::defaultFor($record);

                        if ($default === null) {
                            FilamentNotification::make()
                                ->danger()
                                ->title('No default content is recorded for this document.')
                                ->send();

                            return;
                        }

                        $record->forceFill($default)->save();

                        FilamentNotification::make()
                            ->success()
                            ->title('Restored to the default content.')
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }

    /** @return array{subject:string,body:string,variables:list<string>}|null */
    private static function defaultFor(NotificationTemplate $record): ?array
    {
        return app(NotificationTemplateSeeder::class)->defaultFor($record->key, $record->locale, $record->channel);
    }

    /** @return array<string, string> */
    private static function eventOptions(): array
    {
        $options = [];

        foreach (self::DOCUMENT_EVENTS as $case) {
            $options[$case->value] = str($case->value)->replace('.', ' ')->headline()->toString();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function channelOptions(): array
    {
        $options = [];

        foreach (NotificationChannel::cases() as $case) {
            $options[$case->value] = str($case->value)->headline()->toString();
        }

        return $options;
    }
}
