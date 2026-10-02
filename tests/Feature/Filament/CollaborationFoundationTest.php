<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Collaboration\CollaborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates collaboration entries separately from audit history and follows the record', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $service = app(CollaborationService::class);

    $entry = $service->create($actor, $customer, [
        'type' => 'comment',
        'body' => 'Call the customer before dispatch.',
    ]);

    expect($entry->subject->is($customer))->toBeTrue()
        ->and($entry->author->is($actor))->toBeTrue()
        ->and($customer->collaborationEntries()->count())->toBe(1)
        ->and($customer->collaborationFollowers()->where('user_id', $actor->id)->exists())->toBeTrue();
});

it('completes collaboration activities without changing the subject domain state', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $service = app(CollaborationService::class);

    $entry = $service->create($actor, $customer, [
        'type' => 'activity',
        'body' => 'Review the renewed contract.',
        'assignee_id' => $actor->id,
        'due_at' => now()->addDay()->toDateTimeString(),
    ]);

    $completed = $service->complete($actor, $entry);

    expect($completed->completed_at)->not->toBeNull()
        ->and($completed->metadata['completed_by'])->toBe($actor->id)
        ->and($customer->refresh()->collaborationEntries()->count())->toBe(1);

    $service->unfollow($actor, $customer);
    expect($customer->collaborationFollowers()->where('user_id', $actor->id)->exists())->toBeFalse();
});
