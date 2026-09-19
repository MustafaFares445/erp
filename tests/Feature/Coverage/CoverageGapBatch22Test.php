<?php

declare(strict_types=1);

use App\Filament\Resources\ReceivableWriteOffs\Actions\ReceivableWriteOffActions;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\ReceivableWriteOff;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Policies\SupplierConfirmationPolicy;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers unauthenticated receivable write-off action guards', function (): void {
    auth()->logout();

    $record = new ReceivableWriteOff;
    expect(fn (): mixed => ReceivableWriteOffActions::approve()->getActionFunction()($record))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    expect(fn (): mixed => ReceivableWriteOffActions::cancel()->getActionFunction()($record, ['reason' => 'coverage']))
        ->toThrow(LogicException::class, 'authenticated accounting user');
});

it('covers supplier confirmation create auth guard', function (): void {
    auth()->logout();

    $page = new ReflectionClass(ManageSupplierConfirmations::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ManageSupplierConfirmations::class, 'getHeaderActions');
    $create = $method->invoke($page)[0];

    expect(fn (): mixed => $create->process(null, ['data' => []]))
        ->toThrow(Halt::class);
});

it('denies supplier confirmation answering without purchase permission', function (): void {
    (new PurchasePermissionSeeder)->run();

    $user = User::factory()->employee()->create();
    $confirmation = SupplierConfirmation::factory()->create();

    expect(app(SupplierConfirmationPolicy::class)->answer($user, $confirmation))->toBeFalse();
});

it('covers inventory reservation source resolution fallbacks', function (): void {
    $missing = InventoryReservation::factory()->create([
        'source_type' => 'inventory_operation',
        'source_id' => 999999999,
    ]);

    expect($missing->resolvedSourceDocument())->toBeNull();

    $operation = InventoryOperation::factory()->delivery()->create();
    $reservation = InventoryReservation::factory()->create([
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
    ]);
    $resolved = $reservation->resolvedSourceDocument();

    expect($resolved)->toBeInstanceOf(InventoryOperation::class)
        ->and($resolved?->is($operation))->toBeTrue();

    $other = InventoryReservation::factory()->create([
        'source_type' => 'manual',
        'source_id' => 1,
    ]);

    expect($other->resolvedSourceDocument())->toBeNull();
});
