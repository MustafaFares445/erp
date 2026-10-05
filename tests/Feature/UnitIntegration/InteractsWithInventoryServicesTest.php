<?php

declare(strict_types=1);

use App\Filament\Concerns\InteractsWithInventoryServices;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

interface InventoryOperationRunner
{
    public function run(callable $operation, string $successMessageKey): void;
}

function makeInventoryOperationRunner(): InventoryOperationRunner
{
    return new class implements InventoryOperationRunner
    {
        use InteractsWithInventoryServices;

        public function run(callable $operation, string $successMessageKey): void
        {
            $this->runInventoryOperation($operation, $successMessageKey);
        }
    };
}

it('sends a success notification when the operation succeeds', function (): void {
    $runner = makeInventoryOperationRunner();

    $runner->run(fn (): null => null, 'admin.inventory.notifications.success');

    Notification::assertNotified(
        Notification::make()->success()->title(__('admin.inventory.notifications.success')),
    );
});

it('sends a danger notification and writes nothing when the operation throws a domain exception', function (): void {
    $runner = makeInventoryOperationRunner();

    $runner->run(function (): void {
        throw new DomainException('inactive warehouse');
    }, 'admin.inventory.notifications.success');

    Notification::assertNotified(
        Notification::make()
            ->danger()
            ->title(__('admin.inventory.notifications.error'))
            ->body('inactive warehouse'),
    );

    expect(DB::table('users')->count())->toBe(0);
});

it('sends a danger notification when the operation throws a validation exception', function (): void {
    $runner = makeInventoryOperationRunner();

    $validator = Validator::make([], ['quantity' => 'required']);

    $runner->run(function () use ($validator): void {
        throw new ValidationException($validator);
    }, 'admin.inventory.notifications.success');

    Notification::assertNotified(
        Notification::make()
            ->danger()
            ->title(__('admin.inventory.notifications.error'))
            ->body('The quantity field is required.'),
    );
});
