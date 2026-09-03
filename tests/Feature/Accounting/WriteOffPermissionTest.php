<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('keeps record and approve permissions operationally separate', function (): void {
    $maker = User::factory()->create();
    $checker = User::factory()->create();
    $recordOnly = User::factory()->create();
    $approveOnly = User::factory()->create();

    $recordOnly->givePermissionTo(AccountingPermission::WriteOffRecord->value);
    $approveOnly->givePermissionTo(AccountingPermission::WriteOffApprove->value);

    $writeOff = ReceivableWriteOff::factory()->create([
        'recorded_by' => $maker->getKey(),
    ]);

    expect($recordOnly->can('create', ReceivableWriteOff::class))->toBeTrue()
        ->and($recordOnly->can('approve', $writeOff))->toBeFalse()
        ->and($approveOnly->can('create', ReceivableWriteOff::class))->toBeFalse()
        ->and($approveOnly->can('approve', $writeOff))->toBeTrue()
        ->and($checker->can('approve', $writeOff))->toBeFalse();
});
