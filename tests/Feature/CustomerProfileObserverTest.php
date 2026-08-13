<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records restoration separately from the existing customer lifecycle events', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $customerProfile = CustomerProfile::factory()->create();

    $customerProfile->delete();
    $customerProfile->restore();

    expect(AuditLog::query()->where('description', 'customer.deleted')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('description', 'customer.restored')->value('causer_id'))->toBe($actor->id);
});
