<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('description')->label(__('admin.crm.fields.action')),
            TextEntry::make('subject_type')->label(__('admin.crm.fields.entity_type')),
            TextEntry::make('subject_id')->label(__('admin.crm.fields.entity_id')),
            TextEntry::make('causer.name')->label(__('admin.crm.fields.actor'))->placeholder(__('admin.crm.placeholders.system')),
            TextEntry::make('source_channel')->label(__('admin.crm.fields.channel')),
            TextEntry::make('ip_address')->label(__('admin.crm.fields.ip_address')),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('old_values')->label(__('admin.crm.fields.old_values'))->state(static fn (AuditLog $record): string => self::json(self::asArray($record->attribute_changes?->get('old'))))->columnSpanFull(),
            TextEntry::make('new_values')->label(__('admin.crm.fields.new_values'))->state(static fn (AuditLog $record): string => self::json(self::asArray($record->attribute_changes?->get('attributes'))))->columnSpanFull(),
        ]);
    }

    /** @return array<mixed>|null */
    private static function asArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @param array<mixed>|null $values */
    private static function json(?array $values): string
    {
        if ($values === null || $values === []) {
            return '—';
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '—';
    }
}
