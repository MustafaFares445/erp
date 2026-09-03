<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:alerts:reconcile')->daily();
Schedule::command('inventory:lots:reconcile --scheduled')->dailyAt('01:30');
Schedule::command('inventory:reservations:expire')->hourly();
Schedule::command('inventory:shipments:auto-arrive')->hourly();
Schedule::command('support:sla:reconcile')->everyFiveMinutes();
Schedule::command('notifications:overdue-invoices')->daily();
Schedule::command('notifications:expiring-lots')->daily();
Schedule::command('notifications:pending-approvals')->daily();
Schedule::command('notifications:visits-due')->daily();
Schedule::command('notifications:retry-failed')->hourly();
