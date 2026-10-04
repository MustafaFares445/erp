<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * The dashboard colour palette, taken from the IERP mobile design system
 * (design/employee-sales-app-v1.pen, design/customer-app-v1.pen).
 *
 * Every scale is the Tailwind 3 palette, so shade 600 is exactly the mobile token
 * (primary #2563EB, success #16A34A, warning #D97706, danger #DC2626, info #0284C7) and
 * shades 50/100/200/500/900 of gray are the app's surface-subtle, border, muted and text
 * tokens. Filament's own `Color::*` constants are Tailwind 4 and noticeably more saturated.
 */
final class IerpColors
{
    /*
     * Chart series colours. Chart.js needs literal colours, so these mirror the design tokens:
     * the saturated 600 shade of each semantic colour, a slate-400 neutral for "previous period" /
     * "not started" series, and a violet accent for the rare fifth category.
     * Use PRIMARY for ordinary series, and success/warning/danger only where the colour carries meaning.
     * These are the LIGHT-mode values; resources/js/filament/chart-theme.js swaps in the dark-palette
     * equivalents client-side, so a new constant needs a matching entry in its DARK_SERIES table.
     */
    public const string CHART_PRIMARY = '#2563eb';

    public const string CHART_SUCCESS = '#16a34a';

    public const string CHART_WARNING = '#d97706';

    public const string CHART_DANGER = '#dc2626';

    public const string CHART_INFO = '#0284c7';

    public const string CHART_NEUTRAL = '#94a3b8';

    public const string CHART_ACCENT = '#7c3aed';

    /**
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        return [
            'primary' => self::BLUE,
            'gray' => self::SLATE,
            'success' => self::GREEN,
            'warning' => self::AMBER,
            'danger' => self::RED,
            'info' => self::SKY,
        ];
    }

    /** @var array<int, string> */
    private const array BLUE = [
        50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6',
        600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554',
    ];

    /** @var array<int, string> */
    private const array SLATE = [
        50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1', 400 => '#94a3b8', 500 => '#64748b',
        600 => '#475569', 700 => '#334155', 800 => '#1e293b', 900 => '#0f172a', 950 => '#020617',
    ];

    /** @var array<int, string> */
    private const array GREEN = [
        50 => '#f0fdf4', 100 => '#dcfce7', 200 => '#bbf7d0', 300 => '#86efac', 400 => '#4ade80', 500 => '#22c55e',
        600 => '#16a34a', 700 => '#15803d', 800 => '#166534', 900 => '#14532d', 950 => '#052e16',
    ];

    /** @var array<int, string> */
    private const array AMBER = [
        50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#f59e0b',
        600 => '#d97706', 700 => '#b45309', 800 => '#92400e', 900 => '#78350f', 950 => '#451a03',
    ];

    /** @var array<int, string> */
    private const array RED = [
        50 => '#fef2f2', 100 => '#fee2e2', 200 => '#fecaca', 300 => '#fca5a5', 400 => '#f87171', 500 => '#ef4444',
        600 => '#dc2626', 700 => '#b91c1c', 800 => '#991b1b', 900 => '#7f1d1d', 950 => '#450a0a',
    ];

    /** @var array<int, string> */
    private const array SKY = [
        50 => '#f0f9ff', 100 => '#e0f2fe', 200 => '#bae6fd', 300 => '#7dd3fc', 400 => '#38bdf8', 500 => '#0ea5e9',
        600 => '#0284c7', 700 => '#0369a1', 800 => '#075985', 900 => '#0c4a6e', 950 => '#082f49',
    ];
}
