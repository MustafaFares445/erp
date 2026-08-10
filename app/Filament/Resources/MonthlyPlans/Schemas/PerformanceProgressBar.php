<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Schemas;

use App\Models\SalesPlan;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

/**
 * Single-line performance readout for the {@see SalesPlan} view page,
 * replacing the old tabular EmployeePerformanceScore relation manager with a
 * filled progress bar plus the same factor scores as plain text underneath.
 * Reads via {@see SalesPlan::performanceSummary()} rather than the model
 * directly, since Filament namespaces outside Performance are banned from
 * referencing EmployeePerformanceScore (tests/Unit/ArchTest.php).
 */
final class PerformanceProgressBar
{
    public static function make(): Placeholder
    {
        return Placeholder::make('performance_progress_bar')
            ->hiddenLabel()
            ->content(function (?SalesPlan $record): HtmlString {
                if (! $record instanceof SalesPlan) {
                    return new HtmlString('');
                }

                $summary = $record->performanceSummary();

                if ($summary === null) {
                    return new HtmlString('<p style="font-size: 0.875rem; color: var(--gray-500);">No performance score calculated yet.</p>');
                }

                return new HtmlString(self::styles().self::markup($summary));
            })
            ->columnSpanFull();
    }

    /**
     * @param  array{total_score: float, task_score: float, visit_score: float, schedule_score: float, work_time_score: float, calculated_at: Carbon}  $summary
     */
    private static function markup(array $summary): string
    {
        $percent = max(0.0, min(100.0, $summary['total_score']));
        $fillClass = $percent >= 100.0 ? 'performance-progress-bar-fill performance-progress-bar-fill-complete' : 'performance-progress-bar-fill';

        return sprintf(
            <<<'HTML'
                <div class="performance-progress-bar">
                    <div class="performance-progress-bar-header">
                        <span>Performance</span>
                        <span>%s%%</span>
                    </div>
                    <div class="performance-progress-bar-track">
                        <div class="%s" style="width: %s%%;"></div>
                    </div>
                    <div class="performance-progress-bar-details">
                        <span>Task score: %s</span>
                        <span>Visit score: %s</span>
                        <span>Schedule score: %s</span>
                        <span>Work time score: %s</span>
                        <span>Calculated: %s</span>
                    </div>
                </div>
                HTML,
            number_format($percent, 2),
            $fillClass,
            number_format($percent, 2),
            number_format($summary['task_score'], 2),
            number_format($summary['visit_score'], 2),
            number_format($summary['schedule_score'], 2),
            number_format($summary['work_time_score'], 2),
            $summary['calculated_at']->format('M j, Y H:i:s'),
        );
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
                .performance-progress-bar-header {
                    display: flex;
                    justify-content: space-between;
                    font-size: 0.875rem;
                    font-weight: 600;
                    margin-bottom: 0.375rem;
                }
                .performance-progress-bar-track {
                    height: 0.5rem;
                    border-radius: 9999px;
                    overflow: hidden;
                    background-color: var(--gray-100);
                }
                .dark .performance-progress-bar-track {
                    background-color: rgba(255, 255, 255, 0.05);
                }
                .performance-progress-bar-fill {
                    height: 100%;
                    border-radius: 9999px;
                    background-color: var(--primary-600);
                }
                .dark .performance-progress-bar-fill {
                    background-color: var(--primary-500);
                }
                .performance-progress-bar-fill-complete,
                .dark .performance-progress-bar-fill-complete {
                    background-color: var(--success-600);
                }
                .performance-progress-bar-details {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 1rem;
                    margin-top: 0.5rem;
                    font-size: 0.75rem;
                    color: var(--gray-500);
                }
                .dark .performance-progress-bar-details {
                    color: var(--gray-400);
                }
            </style>
            CSS;
    }
}
