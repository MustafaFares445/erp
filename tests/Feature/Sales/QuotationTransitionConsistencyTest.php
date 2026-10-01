<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationResponse;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationResponseService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function productQuotation(): Quotation
{
    $variant = ProductVariant::factory()->create(['base_price' => 100]);

    return app(QuotationService::class)->create(
        ['customer_id' => CustomerProfile::factory()->create()->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
}

function sentQuotationExpiringOn(CarbonImmutable $expiresAt): Quotation
{
    $quotation = productQuotation();
    app(QuotationService::class)->send($quotation);
    $quotation->forceFill(['expires_at' => $expiresAt->toDateString()])->saveQuietly();

    return $quotation->refresh();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// L11 — expiry boundary

it('keeps a sent quotation acceptable through the whole of its expiry day and expires it the day after', function (): void {
    $expiryDay = CarbonImmutable::parse('2026-10-15');
    $quotation = sentQuotationExpiringOn($expiryDay);

    $this->travelTo($expiryDay->setTime(23, 59, 30));
    expect($quotation->refresh()->isExpired())->toBeFalse();

    $accepted = app(QuotationService::class)->recordDecision(
        $quotation,
        QuotationDecision::Accepted,
        CarbonImmutable::now(),
        null,
        User::factory()->create(),
    );
    expect($accepted->status)->toBe(QuotationStatus::Accepted);

    $other = sentQuotationExpiringOn($expiryDay);
    $this->travelTo($expiryDay->addDay()->setTime(0, 0, 1));
    expect($other->refresh()->isExpired())->toBeTrue()
        ->and(fn () => app(QuotationService::class)->recordDecision(
            $other,
            QuotationDecision::Accepted,
            CarbonImmutable::now(),
            null,
            User::factory()->create(),
        ))->toThrow(InvalidQuotationTransition::class);
});

it('agrees with the expiry sweep about which sent quotations have lapsed', function (): void {
    $today = CarbonImmutable::parse('2026-10-15 09:00:00');
    $this->travelTo($today);

    $lastDay = sentQuotationExpiringOn($today);
    $yesterday = sentQuotationExpiringOn($today->subDay());

    $this->artisan('sales:quotations:expire')->assertSuccessful();

    expect($lastDay->refresh()->status)->toBe(QuotationStatus::Sent)
        ->and($lastDay->isExpired())->toBeFalse()
        ->and($yesterday->refresh()->status)->toBe(QuotationStatus::Expired)
        ->and($yesterday->isExpired())->toBeTrue();
});

// L14 — stale instances must not overwrite a committed transition

it('refuses to send a draft that another request already sent', function (): void {
    $quotation = productQuotation();
    $stale = Quotation::query()->findOrFail($quotation->getKey());

    app(QuotationService::class)->send($quotation);
    $sentAt = $quotation->refresh()->sent_at;

    $this->travel(1)->hours();

    expect(fn () => app(QuotationService::class)->send($stale))->toThrow(InvalidQuotationTransition::class)
        ->and($quotation->refresh()->sent_at?->equalTo($sentAt))->toBeTrue();
});

it('refuses to reject a quotation a stale instance still believes is sent', function (): void {
    $quotation = productQuotation();
    app(QuotationService::class)->send($quotation);
    $stale = Quotation::query()->findOrFail($quotation->getKey());
    $recorder = User::factory()->create();

    app(QuotationResponseService::class)->accept($quotation, CarbonImmutable::now(), null, null, $recorder);

    expect(fn () => app(QuotationResponseService::class)->reject($stale, CarbonImmutable::now(), 'Too dear', null, $recorder))
        ->toThrow(InvalidQuotationTransition::class);

    expect($quotation->refresh()->status)->toBe(QuotationStatus::Accepted)
        ->and(QuotationResponse::query()->where('quotation_id', $quotation->getKey())->count())->toBe(1);
});

it('refuses to request changes on a quotation a stale instance still believes is sent', function (): void {
    $quotation = productQuotation();
    app(QuotationService::class)->send($quotation);
    $stale = Quotation::query()->findOrFail($quotation->getKey());
    $recorder = User::factory()->create();

    app(QuotationResponseService::class)->accept($quotation, CarbonImmutable::now(), null, null, $recorder);

    expect(fn () => app(QuotationResponseService::class)->requestChanges($stale, CarbonImmutable::now(), 'Change it', null, $recorder))
        ->toThrow(InvalidQuotationTransition::class);

    expect($quotation->refresh()->status)->toBe(QuotationStatus::Accepted);
});

it('does not let the expiry sweep overwrite a quotation accepted after the sweep loaded it', function (): void {
    $quotation = sentQuotationExpiringOn(CarbonImmutable::today()->subDay());
    $stale = Quotation::query()->findOrFail($quotation->getKey());

    // The accept would be refused as lapsed, so reach Accepted the way a late-committed decision
    // would have: the row is already moved on when the sweep's stale copy reaches expire().
    $quotation->forceFill(['status' => QuotationStatus::Accepted])->saveQuietly();

    expect(fn () => app(QuotationService::class)->expire($stale))->toThrow(InvalidQuotationTransition::class)
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::Accepted);
});

it('gives the loser of two racing conversions a business error and no second order', function (): void {
    $quotation = productQuotation();
    app(QuotationService::class)->send($quotation);
    app(QuotationResponseService::class)->accept($quotation, CarbonImmutable::now(), null, null, User::factory()->create());
    $winner = Quotation::query()->findOrFail($quotation->getKey());
    $loser = Quotation::query()->findOrFail($quotation->getKey());

    $order = app(QuotationConversionService::class)->convert($winner);

    expect(fn () => app(QuotationConversionService::class)->convert($loser))
        ->toThrow(InvalidQuotationTransition::class, $order->order_number);

    expect(Order::query()->count())->toBe(1)
        ->and($winner->status)->toBe(QuotationStatus::ConvertedToDelivery);
});

it('refuses to cancel a sales order that was closed after the caller loaded it', function (): void {
    Gate::before(static fn (): bool => true);
    $order = Order::factory()->create(['status' => OrderStatus::Released->value]);
    $stale = Order::query()->findOrFail($order->getKey());

    $order->forceFill(['status' => OrderStatus::Closed->value])->save();

    expect(fn () => app(SalesOrderService::class)->cancel(User::factory()->create(), $stale, 'Changed my mind'))
        ->toThrow(DomainException::class, 'already')
        ->and($order->refresh()->status)->toBe(OrderStatus::Closed)
        ->and($order->cancelled_at)->toBeNull();
});
