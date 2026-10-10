<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Services\Inventory\InventoryViewDiagnosticsService;
use Closure;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared building blocks of the Inventory View page layout (Docs/domains/inventory/VIEW_UX_DIAGNOSTICS.md):
 * the Operational health panel and the Key metrics strip.
 */
final class InventoryViewDiagnostics
{
    public static function section(): Section
    {
        return Section::make(__('inventory_view_ux.title'))
            ->description(__('inventory_view_explanations.health_intro'))
            ->icon('heroicon-o-heart')
            ->key('inventory-operational-health')
            ->columnSpanFull()
            ->schema([
                ViewEntry::make('inventory_operational_health')
                    ->hiddenLabel()
                    ->view('filament.inventory.operational-health')
                    ->viewData(fn (Model $record): array => [
                        'diagnosis' => app(InventoryViewDiagnosticsService::class)->diagnose($record),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  Closure(Model): list<array{label: string, value: string, helper?: string|null, tone?: string|null}>  $metrics
     */
    public static function metrics(Closure $metrics): Section
    {
        return Section::make(__('inventory_view_ux.metrics_title'))
            ->key('inventory-key-metrics')
            ->columnSpanFull()
            ->schema([
                ViewEntry::make('inventory_key_metrics')
                    ->hiddenLabel()
                    ->view('filament.inventory.key-metrics')
                    ->viewData(fn (Model $record): array => [
                        'metrics' => array_map(static fn (array $metric): array => [
                            'label' => $metric['label'],
                            'value' => $metric['value'],
                            'helper' => $metric['helper'] ?? null,
                            'tone' => $metric['tone'] ?? null,
                            'help' => $metric['help'] ?? self::metricHelp($metric['label']),
                        ], $metrics($record)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function metricHelp(string $label): string
    {
        $known = [
            __('admin.inventory.outbound.fields.requested') => __('inventory_view_explanations.requested'),
            __('admin.inventory.outbound.fields.planned') => __('inventory_view_explanations.planned'),
            __('admin.inventory.outbound.fields.ready') => __('inventory_view_explanations.ready'),
            __('admin.inventory.outbound.fields.remaining') => __('inventory_view_explanations.remaining'),
            __('inventory_view_ux.ui.outbound_dispatched') => __('inventory_view_explanations.dispatched'),
            __('inventory_view_ux.ui.outbound_supply_outstanding') => __('inventory_view_explanations.supply'),
            __('inventory_view_ux.ui.metric_counted') => __('inventory_view_explanations.counted'),
            __('inventory_view_ux.ui.metric_variance_lines') => __('inventory_view_explanations.variance_lines'),
            __('inventory_view_ux.ui.metric_recount_lines') => __('inventory_view_explanations.recount_lines'),
            __('inventory_view_ux.ui.metric_variance_value') => __('inventory_view_explanations.variance_value'),
            __('inventory_view_ux.ui.alert_severity') => __('inventory_view_explanations.alert_severity'),
            __('inventory_view_ux.ui.alert_age') => __('inventory_view_explanations.alert_age'),
        ];

        return $known[$label] ?? __('inventory_view_explanations.metrics_default');
    }
}
