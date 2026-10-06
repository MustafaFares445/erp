<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeOperationalContextService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

final class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Employee'))
                ->columns(4)
                ->schema([
                    TextEntry::make('user.name')->label(__('Employee name')),
                    TextEntry::make('job_title')->label(__('Job title')),
                    TextEntry::make('employee_code')->label(__('Employee code'))->badge(),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                    TextEntry::make('email')->label(__('Contact email'))->placeholder(__('Not provided')),
                    TextEntry::make('phone')->placeholder(__('Not provided')),
                    TextEntry::make('user.email')->label(__('Login email')),
                    TextEntry::make('current_plan_header')
                        ->label(__('Current plan'))
                        ->state(static fn (EmployeeProfile $record): string => self::text(self::overview($record)['current_plan'] ?? null, 'No current plan')),
                    TextEntry::make('current_performance_header')
                        ->label(__('Current performance'))
                        ->state(static fn (EmployeeProfile $record): string => self::percent(self::overview($record)['current_performance'] ?? null)),
                ]),
            Tabs::make('employee_details')
                ->tabs([
                    Tab::make(__('Overview'))
                        ->schema([
                            Section::make(__('Current month summary'))
                                ->columns(4)
                                ->schema([
                                    TextEntry::make('overview_plan')
                                        ->label(__('Plan'))
                                        ->state(static fn (EmployeeProfile $record): string => self::text(self::overview($record)['current_plan'] ?? null, 'No current plan')),
                                    TextEntry::make('overview_plan_status')
                                        ->label(__('Plan status'))
                                        ->state(static fn (EmployeeProfile $record): string => self::text(self::overview($record)['current_plan_status'] ?? null, '—'))
                                        ->badge(),
                                    TextEntry::make('overview_performance')
                                        ->label(__('Performance'))
                                        ->state(static fn (EmployeeProfile $record): string => self::percent(self::overview($record)['current_performance'] ?? null)),
                                    TextEntry::make('overview_salary')
                                        ->label(__('Salary status'))
                                        ->state(static fn (EmployeeProfile $record): string => self::text(self::overview($record)['current_salary_status'] ?? null, 'Not calculated'))
                                        ->badge(),
                                    TextEntry::make('overview_tasks_open')
                                        ->label(__('Open tasks'))
                                        ->state(static fn (EmployeeProfile $record): int => (int) self::overview($record)['tasks_open']),
                                    TextEntry::make('overview_tasks_completed')
                                        ->label(__('Completed tasks'))
                                        ->state(static fn (EmployeeProfile $record): int => (int) self::overview($record)['tasks_completed']),
                                    TextEntry::make('overview_visits_completed')
                                        ->label(__('Completed visits'))
                                        ->state(static fn (EmployeeProfile $record): int => (int) self::overview($record)['visits_completed']),
                                    TextEntry::make('overview_opportunities')
                                        ->label(__('Detected opportunities'))
                                        ->state(static fn (EmployeeProfile $record): int => (int) self::overview($record)['opportunities']),
                                    TextEntry::make('overview_next_visit')
                                        ->label(__('Next visit'))
                                        ->state(static function (EmployeeProfile $record): string {
                                            $overview = self::overview($record);
                                            $date = $overview['next_visit'] ?? null;
                                            $customer = $overview['next_visit_customer'] ?? null;

                                            return is_string($date) ? $date.(is_string($customer) ? ' — '.$customer : '') : __('No scheduled visit');
                                        })
                                        ->columnSpan(2),
                                    TextEntry::make('overview_last_visit')
                                        ->label(__('Last completed visit'))
                                        ->state(static function (EmployeeProfile $record): string {
                                            $overview = self::overview($record);
                                            $date = $overview['last_visit'] ?? null;
                                            $customer = $overview['last_visit_customer'] ?? null;

                                            return is_string($date) ? $date.(is_string($customer) ? ' — '.$customer : '') : __('No completed visit');
                                        })
                                        ->columnSpan(2),
                                    TextEntry::make('overview_location_warnings')
                                        ->label(__('Location warnings'))
                                        ->state(static fn (EmployeeProfile $record): int => (int) self::overview($record)['location_warnings'])
                                        ->badge(),
                                ]),
                        ]),
                    Tab::make(__('Plan'))
                        ->schema([
                            RepeatableEntry::make('plans')
                                ->hiddenLabel()
                                ->state(static fn (EmployeeProfile $record): array => app(EmployeeOperationalContextService::class)->plans($record))
                                ->schema([
                                    TextEntry::make('name')->label(__('Plan')),
                                    TextEntry::make('month')->label(__('Month')),
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('tasks')->label(__('Tasks'))->numeric(),
                                    TextEntry::make('performance')->label(__('Performance'))->suffix('%')->placeholder(__('—')),
                                ])
                                ->columns(5)
                                ->placeholder(__('No plans found')),
                        ]),
                    Tab::make(__('Visits'))
                        ->schema([
                            RepeatableEntry::make('visits')
                                ->hiddenLabel()
                                ->state(static fn (EmployeeProfile $record): array => app(EmployeeOperationalContextService::class)->visits($record))
                                ->schema([
                                    TextEntry::make('reference')->label(__('Reference')),
                                    TextEntry::make('customer')->label(__('Customer'))->placeholder(__('—')),
                                    TextEntry::make('scheduled_at')->label(__('Scheduled'))->placeholder(__('—')),
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('outcome')->label(__('Outcome'))->placeholder(__('—')),
                                    TextEntry::make('follow_up')->label(__('Follow-up')),
                                ])
                                ->columns(6)
                                ->placeholder(__('No visits found')),
                        ]),
                    Tab::make(__('Tasks'))
                        ->schema([
                            RepeatableEntry::make('tasks')
                                ->hiddenLabel()
                                ->state(static fn (EmployeeProfile $record): array => app(EmployeeOperationalContextService::class)->tasks($record))
                                ->schema([
                                    TextEntry::make('title')->label(__('Task')),
                                    TextEntry::make('plan')->label(__('Plan')),
                                    TextEntry::make('customer')->label(__('Customer'))->placeholder(__('—')),
                                    TextEntry::make('due_at')->label(__('Due date')),
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('source')->label(__('Source')),
                                ])
                                ->columns(6)
                                ->placeholder(__('No tasks found')),
                        ]),
                    Tab::make(__('Performance'))
                        ->schema([
                            RepeatableEntry::make('performance_snapshots')
                                ->hiddenLabel()
                                ->state(static fn (EmployeeProfile $record): array => app(EmployeeOperationalContextService::class)->performance($record))
                                ->schema([
                                    TextEntry::make('plan')->label(__('Plan')),
                                    TextEntry::make('period')->label(__('Period')),
                                    TextEntry::make('total')->label(__('Total'))->suffix('%'),
                                    TextEntry::make('task')->label(__('Task'))->suffix('%'),
                                    TextEntry::make('visit')->label(__('Visit'))->suffix('%'),
                                    TextEntry::make('schedule')->label(__('Schedule'))->suffix('%'),
                                    TextEntry::make('work_time')->label(__('Work time'))->suffix('%'),
                                    TextEntry::make('opportunity')->label(__('Sales opportunities'))->suffix('%'),
                                    TextEntry::make('calculated_at')->label(__('Calculated at')),
                                ])
                                ->columns(3)
                                ->placeholder(__('No performance snapshots found')),
                        ]),
                    Tab::make(__('Salary'))
                        ->schema([
                            Section::make(__('Salary basis'))
                                ->columns(3)
                                ->schema([
                                    IconEntry::make('use_base_salary')->label(__('Uses base salary'))->boolean(),
                                    TextEntry::make('base_salary')->money()->placeholder(__('Not provided')),
                                    TextEntry::make('commission_target_amount')->label(__('Commission/target amount'))->money()->placeholder(__('Not provided')),
                                ]),
                            RepeatableEntry::make('salary_history')
                                ->hiddenLabel()
                                ->state(static fn (EmployeeProfile $record): array => app(EmployeeOperationalContextService::class)->salaries($record))
                                ->schema([
                                    TextEntry::make('plan')->label(__('Plan')),
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('base')->label(__('Payable base'))->money(),
                                    TextEntry::make('performance')->label(__('Performance'))->suffix('%'),
                                    TextEntry::make('bonus')->label(__('Bonus'))->money(),
                                    TextEntry::make('final')->label(__('Final salary'))->money(),
                                    TextEntry::make('confirmed_at')->label(__('Confirmed at'))->placeholder(__('—')),
                                ])
                                ->columns(4)
                                ->placeholder(__('No salary calculations found')),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, string|int|float|null> */
    private static function overview(EmployeeProfile $record): array
    {
        return app(EmployeeOperationalContextService::class)->overview($record);
    }

    private static function percent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2).'%' : self::text(null, 'Not calculated');
    }

    private static function text(mixed $value, string $fallbackKey): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        $fallback = __($fallbackKey);

        return is_string($fallback) ? $fallback : $fallbackKey;
    }
}
