<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(QuotationService::class);
    $this->recorder = User::factory()->create();
});

function quotationWithLine(): Quotation
{
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    return app(QuotationService::class)->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
}

it('moves draft to sent', function (): void {
    $quotation = quotationWithLine();

    $sent = $this->service->send($quotation);

    expect($sent->status)->toBe(QuotationStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull();
});

it('refuses to send a quotation that is not draft', function (): void {
    $quotation = quotationWithLine();
    $this->service->send($quotation);

    $this->service->send($quotation);
})->throws(InvalidQuotationTransition::class);

it('records an accepted decision on a sent quotation', function (): void {
    $quotation = quotationWithLine();
    $this->service->send($quotation);

    $decided = $this->service->recordDecision(
        $quotation,
        QuotationDecision::Accepted,
        CarbonImmutable::parse('2026-09-01'),
        'Customer confirmed by phone.',
        $this->recorder,
    );

    expect($decided->status)->toBe(QuotationStatus::Accepted)
        ->and($decided->decided_by)->toBe($this->recorder->getKey())
        ->and($decided->decision_note)->toBe('Customer confirmed by phone.');
});

it('records a rejected decision on a sent quotation', function (): void {
    $quotation = quotationWithLine();
    $this->service->send($quotation);

    $decided = $this->service->recordDecision(
        $quotation,
        QuotationDecision::Rejected,
        CarbonImmutable::today(),
        null,
        $this->recorder,
    );

    expect($decided->status)->toBe(QuotationStatus::Rejected);
});

it('refuses to record a decision on a draft quotation (FR-021)', function (): void {
    $quotation = quotationWithLine();

    $this->service->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $this->recorder);
})->throws(InvalidQuotationTransition::class);

it('refuses acceptance past expiry and marks the quotation expired (FR-022)', function (): void {
    $quotation = quotationWithLine();
    $this->service->send($quotation);
    $quotation->update(['expires_at' => now()->subDay()]);

    try {
        $this->service->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $this->recorder);
        $this->fail('Expected InvalidQuotationTransition to be thrown.');
    } catch (InvalidQuotationTransition) {
        expect($quotation->refresh()->status)->toBe(QuotationStatus::Expired);
    }
});

it('presents a sent quotation past its expiry as expired without any row being rewritten', function (): void {
    $quotation = quotationWithLine();
    $this->service->send($quotation);
    $quotation->update(['expires_at' => now()->subDay()]);

    expect($quotation->refresh()->isExpired())->toBeTrue()
        ->and($quotation->getRawOriginal('status'))->toBe(QuotationStatus::Sent->value);
});
