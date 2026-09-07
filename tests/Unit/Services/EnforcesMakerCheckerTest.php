<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Concerns\EnforcesMakerChecker;

it('allows a different checker and rejects the same actor', function (): void {
    $maker = User::factory()->make();
    $maker->forceFill(['id' => 1]);

    $checker = User::factory()->make();
    $checker->forceFill(['id' => 2]);

    $guard = new class
    {
        use EnforcesMakerChecker;

        public function assertDifferent(?int $makerId, User $checker): void
        {
            $this->assertDifferentActor($makerId, $checker, 'maker/checker conflict');
        }
    };

    expect(fn () => $guard->assertDifferent($maker->getKey(), $maker))
        ->toThrow(DomainException::class, 'maker/checker conflict');

    $guard->assertDifferent($maker->getKey(), $checker);
    $guard->assertDifferent(null, $maker);

    expect(true)->toBeTrue();
});
