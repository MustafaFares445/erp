<?php

declare(strict_types=1);

use App\Enums\InteractionType;
use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TicketStatus;
use App\Enums\VisitStatus;

/**
 * The Customer 360 timeline (CR-05) renders every source's status through
 * label()/color() instead of the raw enum value — this guards that every
 * case of every timeline-facing enum has both, so a new case can't ship
 * `converted_to_delivery` back onto the screen.
 */
it('exposes a non-empty translated label and a valid Filament color for every timeline status enum case', function (): void {
    $validColors = ['gray', 'info', 'primary', 'success', 'warning', 'danger'];

    /** @var list<class-string<TicketStatus|MaintenanceStatus|VisitStatus|InteractionType|QuotationStatus|OrderStatus>> $enums */
    $enums = [
        TicketStatus::class,
        MaintenanceStatus::class,
        VisitStatus::class,
        InteractionType::class,
        QuotationStatus::class,
        OrderStatus::class,
    ];

    foreach ($enums as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())
                ->toBeString()
                ->not->toBe('')
                ->not->toStartWith('admin.');

            expect($case->color())
                ->toBeIn($validColors);
        }
    }
});
