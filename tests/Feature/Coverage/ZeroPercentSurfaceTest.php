<?php

declare(strict_types=1);

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Events\SalesOrderReleased;
use App\Exceptions\Domain\PeriodCloseBlocked;
use App\Exceptions\Domain\QuarantineDispositionRejected;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('covers simple zero-percent data event and domain exception surfaces', function (): void {
    $blocker = new LogisticsInboundBlockerData('missing_allocation', 'Allocate inbound stock first.');
    expect($blocker->code)->toBe('missing_allocation')
        ->and($blocker->message)->toBe('Allocate inbound stock first.')
        ->and($blocker->severity)->toBe('warning');

    $order = new Order;
    $event = new SalesOrderReleased($order);
    expect($event->order)->toBe($order);

    expect(PeriodCloseBlocked::by(['Unposted journals', 'Open receipts'])->getMessage())
        ->toContain('Unposted journals')->toContain('Open receipts')
        ->and(QuarantineDispositionRejected::because('missing evidence')->getMessage())
        ->toContain('missing evidence');
});

it('covers zero-percent console command empty-state paths', function (): void {
    expect(Artisan::call('support:warranties:backfill', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::call('support:warranties:backfill'))->toBe(0)
        ->and(Artisan::call('crm:campaigns:dispatch-due'))->toBe(0)
        ->and(Artisan::call('maintenance:schedules:generate'))->toBe(0)
        ->and(Artisan::call('notifications:expiring-lots'))->toBe(0)
        ->and(Artisan::call('notifications:pending-approvals'))->toBe(0)
        ->and(Artisan::call('notifications:visits-due'))->toBe(0);
});
