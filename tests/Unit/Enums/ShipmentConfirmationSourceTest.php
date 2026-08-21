<?php

declare(strict_types=1);

use App\Enums\ShipmentConfirmationSource;

it('exposes a translated label for every confirmation source', function (ShipmentConfirmationSource $source): void {
    expect($source->label())->not->toContain('admin.shipment');
})->with(fn (): array => array_map(static fn (ShipmentConfirmationSource $source): array => [$source], ShipmentConfirmationSource::cases()));

it('backs each case with the expected string value', function (): void {
    expect(ShipmentConfirmationSource::Customer->value)->toBe('customer')
        ->and(ShipmentConfirmationSource::AdminUser->value)->toBe('admin_user')
        ->and(ShipmentConfirmationSource::System->value)->toBe('system');
});
