<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars\Schemas;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class SlaCalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Calendar'))
                ->description(__('SLA clocks only run during the working periods of the calendar a policy points to. Tickets keep the calendar that applied when their milestone started.'))
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    Select::make('timezone')
                        ->label(__('Timezone'))
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                        ->searchable()
                        ->required()
                        ->default('UTC'),
                    Toggle::make('is_24x7')
                        ->label(__('Runs 24x7'))
                        ->helperText(__('Round-the-clock support: weekly periods are ignored and the SLA clock never pauses for nights or weekends.'))
                        ->live(),
                    Toggle::make('is_default')
                        ->label(__('Default calendar'))
                        ->helperText(__('Used when an SLA policy has no calendar of its own. Only one calendar can be the default.')),
                    Toggle::make('is_active')->label(__('Active'))->default(true),
                ])->columns(2),
            Section::make(__('Weekly working periods'))
                ->description(__('Add one row per working window. A weekday can have several windows (for example a lunch break), but windows must not overlap.'))
                ->hidden(static fn (Get $get): bool => (bool) $get('is_24x7'))
                ->schema([
                    Repeater::make('periods')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            Select::make('weekday')
                                ->label(__('Weekday'))
                                ->options(self::weekdayOptions())
                                ->required(),
                            TimePicker::make('starts_at')->label(__('Starts at'))->seconds(false)->required(),
                            TimePicker::make('ends_at')->label(__('Ends at'))->seconds(false)->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add working period'))
                        ->rules([static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            $message = self::periodsProblem(is_array($value) ? $value : []);
                            if ($message !== null) {
                                $fail($message);
                            }
                        }]),
                ]),
            Section::make(__('Holidays and exceptions'))
                ->description(__('A non-working exception closes the calendar for that date. A working exception replaces the weekly periods with the hours given.'))
                ->schema([
                    Repeater::make('exceptions')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            DatePicker::make('date')->label(__('Date'))->required(),
                            TextInput::make('name')->label(__('Name'))->maxLength(255),
                            Toggle::make('is_working_day')->label(__('Working day'))->live()->default(false),
                            TimePicker::make('starts_at')->label(__('Starts at'))->seconds(false)
                                ->visible(static fn (Get $get): bool => (bool) $get('is_working_day'))
                                ->required(static fn (Get $get): bool => (bool) $get('is_working_day')),
                            TimePicker::make('ends_at')->label(__('Ends at'))->seconds(false)
                                ->visible(static fn (Get $get): bool => (bool) $get('is_working_day'))
                                ->required(static fn (Get $get): bool => (bool) $get('is_working_day')),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add exception'))
                        ->rules([static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            $dates = collect(is_array($value) ? $value : [])
                                ->map(static fn (mixed $row): string => is_array($row) && is_string($row['date'] ?? null) ? mb_substr($row['date'], 0, 10) : '')
                                ->filter();

                            if ($dates->count() !== $dates->unique()->count()) {
                                $fail(__('Each date can only appear once in the exceptions list.'));
                            }
                        }]),
                ])->collapsible(),
        ]);
    }

    /** @return array<int, string> */
    public static function weekdayOptions(): array
    {
        $options = [];
        foreach (range(1, 7) as $isoDay) {
            $options[$isoDay] = CarbonImmutable::now()->startOfWeek()->addDays($isoDay - 1)->settings(['locale' => app()->getLocale()])->dayName;
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $periods
     */
    public static function periodsProblem(array $periods): ?string
    {
        $byDay = [];
        foreach ($periods as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! is_string($row['starts_at'] ?? null)) {
                continue;
            }
            if (! is_string($row['ends_at'] ?? null)) {
                continue;
            }
            $start = mb_substr($row['starts_at'], 0, 5);
            $end = mb_substr($row['ends_at'], 0, 5);
            if ($end <= $start) {
                return __('Every working period must end after it starts.');
            }
            $weekday = is_numeric($row['weekday'] ?? null) ? (int) $row['weekday'] : 0;
            $byDay[$weekday][] = [$start, $end];
        }

        foreach ($byDay as $windows) {
            usort($windows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            for ($i = 1, $count = count($windows); $i < $count; $i++) {
                if ($windows[$i][0] < $windows[$i - 1][1]) {
                    return __('Working periods on the same weekday must not overlap.');
                }
            }
        }

        return null;
    }
}
