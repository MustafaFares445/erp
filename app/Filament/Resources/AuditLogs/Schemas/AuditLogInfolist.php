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
            TextEntry::make('action'),
            TextEntry::make('entity_type')->label(__('admin.crm.fields.entity_type')),
            TextEntry::make('entity_id')->label(__('admin.crm.fields.entity_id')),
            TextEntry::make('actor.name')->label(__('admin.crm.fields.actor'))->placeholder(__('admin.crm.placeholders.system')),
            TextEntry::make('source_channel')->label(__('admin.crm.fields.channel')),
            TextEntry::make('ip_address')->label(__('admin.crm.fields.ip_address')),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('old_values')->label(__('admin.crm.fields.old_values'))->state(static fn (AuditLog $record): string => self::json($record->old_values))->columnSpanFull(),
            TextEntry::make('new_values')->label(__('admin.crm.fields.new_values'))->state(static fn (AuditLog $record): string => self::json($record->new_values))->columnSpanFull(),
        ]);
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
