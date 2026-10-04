<?php

declare(strict_types=1);

use App\Models\Order;
use App\Services\Sales\OrderWorkflowProjectionStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses an order workflow projection within the request', function (): void {
    $order = Order::factory()->create();
    $store = app(OrderWorkflowProjectionStore::class);
    $first = $store->project($order);

    $queries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    $second = $store->project($order);

    expect($second)->toBe($first)
        ->and($queries)->toBe(0);
});
