<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'action';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.audit_logs');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return AuditLogInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return AuditLogsTable::configure($table);
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('actor')->latest('id');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
