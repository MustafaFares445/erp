<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\SupportQueue;
use App\Models\Ticket;
use App\Services\Support\SupportQueueQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies only whitelisted queue criteria', function (): void {
    $matching = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'priority' => TicketPriority::Urgent,
        'assigned_employee_id' => null,
    ]);
    $other = Ticket::factory()->create([
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Low,
    ]);

    $queue = SupportQueue::query()->create([
        'name' => 'Urgent unassigned',
        'is_active' => true,
        'criteria' => [
            'statuses' => [TicketStatus::Live->value],
            'priorities' => [TicketPriority::Urgent->value],
            'unassigned' => true,
        ],
    ]);

    $records = app(SupportQueueQueryService::class)->query($queue)->get();

    expect($records->pluck('id')->all())->toContain($matching->id)
        ->not->toContain($other->id);
});

it('rejects arbitrary queue criteria instead of turning json into query code', function (): void {
    $queue = SupportQueue::query()->create([
        'name' => 'Unsafe',
        'is_active' => true,
        'criteria' => ['raw_sql' => '1=1'],
    ]);

    expect(fn () => app(SupportQueueQueryService::class)->query($queue)->get())
        ->toThrow(DomainException::class, 'Unsupported support queue criterion');
});
