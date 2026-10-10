<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts\Schemas;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Filament\Resources\InventoryAlerts\Tables\InventoryAlertsTable;
use App\Models\InventoryAlert;
use App\Services\Inventory\Diagnostics\InventoryAlertDiagnoser;
use App\Support\QuantityFormatter;
use Illuminate\Database\Eloquent\Model;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use App\Filament\Components\InventoryViewDiagnostics;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class InventoryAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InventoryViewDiagnostics::section(),
                InventoryViewDiagnostics::metrics(static function (Model $record): array {
                    assert($record instanceof InventoryAlert);

                    return InventoryAlertDiagnoser::metrics($record);
                }),
                Section::make(__('inventory_view_ux.ui.alert_details'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
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
                        ->badge()
                        ->color(fn (InventoryAlert $record): string => $record->isActive() ? 'warning' : 'success'),
                    TextEntry::make('recommendation')
                        ->label(__('admin.inventory.alert.recommendation'))
                        ->state(fn (InventoryAlert $record): string => __('admin.inventory.alert.recommendations.'.$record->type->value))
                        ->columnSpanFull(),
                    TextEntry::make('context')
                        ->label(__('admin.inventory.alert.context'))
                        ->state(fn (InventoryAlert $record): string => self::context($record))
                        ->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('resolved_at')->dateTime()->placeholder(__('—')),
                ]),
            ]);
    }

    private static function context(InventoryAlert $alert): string
    {
        if ($alert->context === null) {
            return '—';
        }

        return collect($alert->context)
            ->map(fn (mixed $value, string $key): string => self::contextLabel($key).': '.self::value($value))
            ->join('; ');
    }

    /**
     * Context keys are stored as snake_case facts; known keys have translated labels and
     * any other key falls back to a readable headline instead of the raw storage name.
     */
    private static function contextLabel(string $key): string
    {
        $translationKey = 'admin.inventory.alert.context_fields.'.$key;
        $label = __($translationKey);

        return is_string($label) && $label !== $translationKey ? $label : Str::headline($key);
    }

    private static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if ($value === null) {
            return '—';
        }

        if (is_int($value) || is_float($value)) {
            return QuantityFormatter::display($value);
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return collect($value)->map(fn (mixed $item): string => self::value($item))->join(', ');
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
