<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:alerts:reconcile')->daily();
// WP-4.1: daily runs are incremental (only grains touched since the last
// clean run); a full replay still runs weekly as the backstop regardless of
// how the daily incremental runs have been doing, so drift that an
// incremental scope could theoretically miss is bounded to at most a week.
Schedule::command('inventory:lots:reconcile --scheduled')->dailyAt('01:30');
Schedule::command('inventory:lots:reconcile --scheduled --full')->weeklyOn(0, '02:30');
Schedule::command('inventory:reservations:expire')->hourly();
Schedule::command('sales:quotations:expire')->daily();
Schedule::command('inventory:shipments:auto-arrive')->hourly();
Schedule::command('support:sla:reconcile')->everyFiveMinutes();
Schedule::command('crm:campaigns:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:overdue-invoices')->daily();
Schedule::command('notifications:expiring-lots')->daily();
Schedule::command('notifications:pending-approvals')->daily();
Schedule::command('notifications:visits-due')->daily();
Schedule::command('notifications:retry-failed')->hourly();
Schedule::command('maintenance:schedules:generate')->daily();
