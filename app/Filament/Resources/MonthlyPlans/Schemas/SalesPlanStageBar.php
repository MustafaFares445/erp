<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Schemas;

use App\Enums\SalesPlanStatus;
use App\Filament\Resources\InventoryOperations\Schemas\OperationStageBar;
use App\Models\SalesPlan;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

/**
 * Horizontal status stepper for the {@see SalesPlan} view page, mirroring
 * the arrow-segment stage bar convention used by
 * {@see OperationStageBar}
 * but rendered as filled chevrons so the active {@see SalesPlanStatus} reads
 * at a glance. `Paused` is a lateral detour rather than a forward step, so
 * only the current stage is highlighted — earlier stages are not implied
 * "done".
 */
final class SalesPlanStageBar
{
    public static function make(): Placeholder
    {
        return Placeholder::make('sales_plan_stage_bar')
            ->label('')
            ->content(function (?SalesPlan $record): HtmlString {
                if (! $record instanceof SalesPlan) {
                    return new HtmlString('');
                }

                $stages = SalesPlanStatus::cases();
                $last = count($stages) - 1;

                $segments = array_map(
                    static fn (SalesPlanStatus $stage, int $index): string => self::segment($stage, $index, $last, $record->status),
                    $stages,
                    array_keys($stages),
                );

                return new HtmlString(self::styles().'<div style="display: flex; width: 100%; font-size: 0.875rem; font-weight: 500;">'.implode('', $segments).'</div>');
            })
            ->columnSpanFull();
    }

    /**
     * The panel ships no custom Vite/Tailwind theme, so raw utility classes
     * (e.g. `bg-primary-600`) never make it into Filament's precompiled
     * `theme.css` and render invisibly. These rules use Filament's runtime
     * color CSS variables directly instead, scoped under one class so they
     * only ever apply to this component.
     */
    private static function styles(): string
    {
        return <<<'CSS'
            <style>
                .sales-plan-stage-bar-segment {
                    background-color: var(--gray-100);
                    color: var(--gray-500);
                }
                .dark .sales-plan-stage-bar-segment {
                    background-color: rgba(255, 255, 255, 0.05);
                    color: var(--gray-400);
                }
                .sales-plan-stage-bar-segment.is-active {
                    background-color: var(--primary-600);
                    color: #fff;
                }
                .dark .sales-plan-stage-bar-segment.is-active {
                    background-color: var(--primary-500);
                    color: #fff;
                }
            </style>
            CSS;
    }

    private static function segment(SalesPlanStatus $stage, int $index, int $last, SalesPlanStatus $current): string
    {
        $isActive = $stage === $current;
        $notch = 14;

        $clipPath = match (true) {
            $index === 0 && $last === 0 => 'polygon(0 0, 100% 0, 100% 100%, 0 100%)',
            $index === 0 => sprintf('polygon(0 0, calc(100%% - %dpx) 0, 100%% 50%%, calc(100%% - %dpx) 100%%, 0 100%%)', $notch, $notch),
            $index === $last => sprintf('polygon(0 0, 100%% 0, 100%% 100%%, 0 100%%, %dpx 50%%)', $notch),
            default => sprintf('polygon(0 0, calc(100%% - %dpx) 0, 100%% 50%%, calc(100%% - %dpx) 100%%, 0 100%%, %dpx 50%%)', $notch, $notch, $notch),
        };

        $class = $isActive ? 'sales-plan-stage-bar-segment is-active' : 'sales-plan-stage-bar-segment';
        $wrapperStyle = 'flex: 1 1 0%;'.($index === 0 ? '' : sprintf(' margin-left: -%dpx;', $notch));

        return sprintf(
            '<div style="%s"><div class="%s" style="display: flex; align-items: center; justify-content: center; padding: 0.5rem 1.25rem; clip-path: %s;">%s</div></div>',
            $wrapperStyle,
            $class,
            $clipPath,
            e($stage->value),
        );
    }
}
