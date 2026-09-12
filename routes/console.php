<?php

declare(strict_types=1);

use App\Services\Exports\DocumentExportService;
use App\Services\Notifications\NotificationDeliveryVolumeReportService;
use App\Services\Notifications\NotificationDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exports:cleanup', function (DocumentExportService $service): void {
    $count = $service->cleanupExpired();

    $this->info(sprintf('Expired document export cleanup complete: %d exports cleaned.', $count));
})->purpose('Delete expired retained export files and mark their records expired');

Artisan::command('notifications:digest {--limit=1000}', function (NotificationDigestService $service): void {
    $result = $service->processDue((int) $this->option('limit'));

    $this->info(sprintf(
        'Notification digest processing complete: %d released, %d digests queued, %d failed.',
        $result['released'],
        $result['digests'],
        $result['failed'],
    ));
})->purpose('Release due quiet-hour notifications and queue due notification digests');

Artisan::command('notifications:volume {--from=} {--to=}', function (NotificationDeliveryVolumeReportService $service): void {
    $timezone = (string) config('app.timezone', 'UTC');
    $from = $this->option('from')
        ? CarbonImmutable::parse((string) $this->option('from'), $timezone)->startOfDay()
        : CarbonImmutable::now($timezone)->subDays(30)->startOfDay();
    $to = $this->option('to')
        ? CarbonImmutable::parse((string) $this->option('to'), $timezone)->endOfDay()
        : CarbonImmutable::now($timezone)->endOfDay();

    $rows = $service->summarize($from, $to)
        ->map(fn (object $row): array => [
            (string) $row->channel,
            (string) $row->status,
            (string) ($row->decision ?? '-'),
            (int) $row->total,
        ])
        ->all();

    $this->table(['Channel', 'Status', 'Decision', 'Total'], $rows);
})->purpose('Report notification delivery volume by channel, status, and decision');

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
Schedule::command('notifications:digest')->hourly()->withoutOverlapping();
Schedule::command('exports:cleanup')->dailyAt('03:00');
Schedule::command('maintenance:schedules:generate')->daily();
