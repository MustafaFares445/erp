<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payments\SystemActorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('resolves the same actor on every call', function (): void {
    $first = app(SystemActorResolver::class)->resolve();
    $second = app(SystemActorResolver::class)->resolve();

    expect($first->id)->toBe($second->id)
        ->and(User::query()->where('email', 'system-integration@ierp.internal')->count())->toBe(1);
});

it('grants only the three narrow abilities the system actor needs', function (): void {
    $actor = app(SystemActorResolver::class)->resolve();

    expect($actor->hasRole(DashboardRole::SystemIntegration->value))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('post', new Payment))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('createFromSource', JournalEntry::class))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('settlePayment', Ticket::class))->toBeTrue();
});

it('is confined by the fixed-role narrowing rule instead of inheriting the blanket admin bypass', function (): void {
    $actor = app(SystemActorResolver::class)->resolve();

    expect(Gate::forUser($actor)->denies('create', JournalEntry::class))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('viewAny', Quotation::class))->toBeTrue();
});
