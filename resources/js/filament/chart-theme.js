/*
 * Dark-mode series colours for Chart.js widgets.
 *
 * Widgets pass literal colours (App\Filament\Support\IerpColors::CHART_*) because Chart.js cannot
 * resolve CSS variables, and the server cannot know the viewer's theme. This global plugin keeps the
 * light colour as the source of truth on each dataset and swaps in the dark-palette equivalent
 * while `html.dark` is set. Filament re-runs `chart.update()` on a theme switch, so the swap is
 * re-evaluated every time. The legend text colour is read from the --ierp-chart-legend token, which
 * Chart.js would otherwise render in its default #666 (unreadable on the graphite cards).
 *
 * Keep DARK_SERIES in sync with IerpColors::CHART_* and the dark semantic tokens in tokens.css.
 */
const DARK_SERIES = {
    '#2563eb': '#3b82f6', // primary
    '#16a34a': '#22c55e', // success
    '#d97706': '#f59e0b', // warning
    '#dc2626': '#ef4444', // danger
    '#0284c7': '#38bdf8', // info
    '#94a3b8': '#68717d', // neutral: previous period / not started
    '#7c3aed': '#a78bfa', // accent
}

const COLOR_KEYS = [
    'backgroundColor',
    'borderColor',
    'pointBackgroundColor',
    'pointBorderColor',
    'hoverBackgroundColor',
    'hoverBorderColor',
    'pointHoverBackgroundColor',
    'pointHoverBorderColor',
]

const ORIGINALS = Symbol('ierpOriginalColors')

const swap = (value, isDark) => {
    if (Array.isArray(value)) {
        return value.map((entry) => swap(entry, isDark))
    }

    if (!isDark || typeof value !== 'string') {
        return value
    }

    return DARK_SERIES[value.toLowerCase()] ?? value
}

const ierpChartTheme = {
    id: 'ierpChartTheme',

    beforeUpdate(chart) {
        const isDark = document.documentElement.classList.contains('dark')

        const legendColor = getComputedStyle(document.documentElement).getPropertyValue('--ierp-chart-legend').trim()

        if (legendColor) {
            const legendLabels = ((chart.options.plugins ??= {}).legend ??= {}).labels ??= {}

            legendLabels.color = legendColor
        }

        for (const dataset of chart.data.datasets ?? []) {
            const originals = (dataset[ORIGINALS] ??= {})

            for (const key of COLOR_KEYS) {
                if (dataset[key] === undefined) {
                    continue
                }

                // Remember the colour the widget (or Filament) set, unless it is one this plugin wrote.
                if (originals[key] === undefined || originals[key].written !== dataset[key]) {
                    originals[key] = { source: dataset[key], written: dataset[key] }
                }

                const swapped = swap(originals[key].source, isDark)

                dataset[key] = swapped
                originals[key].written = swapped
            }

            // Filament derives a bar's outline by darkening its fill, which would be a stray dark blue
            // outline once the fill is swapped; in dark mode the outline simply matches the fill.
            if (isDark && chart.config.type === 'bar' && originals.backgroundColor) {
                const fill = swap(originals.backgroundColor.source, true)

                if (JSON.stringify(fill) !== JSON.stringify(originals.backgroundColor.source)) {
                    dataset.borderColor = fill
                    originals.borderColor = { source: originals.borderColor?.source ?? fill, written: fill }
                }
            }
        }
    },
}

window.filamentChartJsPlugins ??= []
window.filamentChartJsPlugins.push(ierpChartTheme)
