<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates;

use App\Enums\NotificationEventKey;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateEditor;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Invoice-document wording (WP-3.7, GAP-UI-07, MD-08): the invoice messages from the
 * same system-defined notification table, presented with the same business editor as
 * {@see NotificationTemplateResource} — language tabs, inline information pills, a live
 * preview and "Reset to system default" to recover a broken edit from the seeded copy.
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
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.document_templates');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return app(NotificationTemplateEditor::class)->representativeRows(
            parent::getEloquentQuery(),
            array_map(static fn (NotificationEventKey $event): string => $event->value, self::DOCUMENT_EVENTS),
        );
    }

    #[\Override]
    public static function getRecordTitle(?Model $record): string
    {
        return NotificationTemplateResource::getRecordTitle($record);
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
        return NotificationTemplateResource::form($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return NotificationTemplateResource::messageTable($table, self::class);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
