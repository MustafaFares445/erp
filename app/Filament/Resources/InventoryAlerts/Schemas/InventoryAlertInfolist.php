<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts\Schemas;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Filament\Resources\InventoryAlerts\Tables\InventoryAlertsTable;
use App\Models\InventoryAlert;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextEntry::make('type')
                        ->formatStateUsing(fn (InventoryAlertType $state): string => __('admin.inventory.alert.types.'.$state->value))
                        ->badge(),
                    TextEntry::make('severity')
                        ->formatStateUsing(fn (InventoryAlertSeverity $state): string => __('admin.inventory.alert.severities.'.$state->value))
                        ->badge(),
                    TextEntry::make('message')->columnSpanFull(),
                    TextEntry::make('subject_reference')
                        ->label(__('admin.inventory.alert.origin'))
                        ->state(fn (InventoryAlert $record): string => InventoryAlertsTable::subjectReference($record))
                        ->url(fn (InventoryAlert $record): ?string => InventoryAlertsTable::subjectUrl($record)),
                    TextEntry::make('state')
                        ->label(__('admin.inventory.alert.state'))
                        ->state(fn (InventoryAlert $record): string => $record->isActive()
                            ? __('admin.inventory.alert.active')
                            : __('admin.inventory.alert.resolved'))
                        ->badge(),
                    TextEntry::make('recommendation')
                        ->label(__('admin.inventory.alert.recommendation'))
                        ->state(fn (InventoryAlert $record): string => __('admin.inventory.alert.recommendations.'.$record->type->value))
                        ->columnSpanFull(),
                    TextEntry::make('context')
                        ->label(__('admin.inventory.alert.context'))
                        ->state(fn (InventoryAlert $record): string => self::context($record))
                        ->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
                ]),
            ]);
    }

    private static function context(InventoryAlert $alert): string
    {
        if ($alert->context === null) {
            return '—';
        }

        return collect($alert->context)
            ->map(fn (mixed $value, string $key): string => $key.': '.self::value($value))
            ->join('; ');
    }

    private static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? 'null');
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
