<?php

declare(strict_types=1);

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Services\Support\TicketPriorityResolver;

it('always proposes Urgent when the customer reports the service is unavailable', function (): void {
    $resolver = app(TicketPriorityResolver::class);

    expect($resolver->resolve(TicketType::SoftwareIssue, TicketCustomerImpact::ServiceUnavailable))->toBe(TicketPriority::Urgent)
        ->and($resolver->resolve(TicketType::GeneralSupport, TicketCustomerImpact::ServiceUnavailable))->toBe(TicketPriority::Urgent);
});

it('proposes High for a degraded hardware or software issue', function (): void {
    $resolver = app(TicketPriorityResolver::class);

    expect($resolver->resolve(TicketType::HardwareIssue, TicketCustomerImpact::Degraded))->toBe(TicketPriority::High)
        ->and($resolver->resolve(TicketType::SoftwareIssue, TicketCustomerImpact::Degraded))->toBe(TicketPriority::High);
});

it('proposes Normal for a degraded general support or maintenance request', function (): void {
    $resolver = app(TicketPriorityResolver::class);

    expect($resolver->resolve(TicketType::GeneralSupport, TicketCustomerImpact::Degraded))->toBe(TicketPriority::Normal)
        ->and($resolver->resolve(TicketType::MaintenanceRequest, TicketCustomerImpact::Degraded))->toBe(TicketPriority::Normal);
});

it('proposes Low for a general question about general support', function (): void {
    expect(app(TicketPriorityResolver::class)->resolve(TicketType::GeneralSupport, TicketCustomerImpact::GeneralQuestion))
        ->toBe(TicketPriority::Low);
});

it('proposes Normal for a general question about hardware, software or maintenance', function (): void {
    $resolver = app(TicketPriorityResolver::class);

    expect($resolver->resolve(TicketType::HardwareIssue, TicketCustomerImpact::GeneralQuestion))->toBe(TicketPriority::Normal)
        ->and($resolver->resolve(TicketType::SoftwareIssue, TicketCustomerImpact::GeneralQuestion))->toBe(TicketPriority::Normal)
        ->and($resolver->resolve(TicketType::MaintenanceRequest, TicketCustomerImpact::GeneralQuestion))->toBe(TicketPriority::Normal);
});

it('treats no reported impact the same as a general question', function (): void {
    $resolver = app(TicketPriorityResolver::class);

    expect($resolver->resolve(TicketType::GeneralSupport, null))->toBe(TicketPriority::Low)
        ->and($resolver->resolve(TicketType::SoftwareIssue, null))->toBe(TicketPriority::Normal);
});
