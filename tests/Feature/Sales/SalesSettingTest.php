<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\SalesSetting;
use App\Services\Sales\Exceptions\PostingAccountUnavailable;
use App\Services\Sales\SalesAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->resolver = app(SalesAccountResolver::class);
});

it('resolves a configured, postable, active account', function (): void {
    $account = ChartAccount::factory()->create();
    $settings = SalesSetting::factory()->create(['receivable_account_id' => $account->getKey()]);

    expect($this->resolver->receivable($settings)->getKey())->toBe($account->getKey());
});

it('refuses to resolve when no account is configured', function (): void {
    $settings = SalesSetting::factory()->create(['revenue_account_id' => null]);

    $this->resolver->revenue($settings);
})->throws(PostingAccountUnavailable::class);

it('refuses to resolve a non-postable account', function (): void {
    $header = ChartAccount::factory()->header()->create();
    $settings = SalesSetting::factory()->create(['deferred_tax_account_id' => $header->getKey()]);

    $this->resolver->deferredTax($settings);
})->throws(PostingAccountUnavailable::class);

it('refuses to resolve an inactive account', function (): void {
    $account = ChartAccount::factory()->inactive()->create();
    $settings = SalesSetting::factory()->create(['tax_payable_account_id' => $account->getKey()]);

    $this->resolver->taxPayable($settings);
})->throws(PostingAccountUnavailable::class);

// FR-006's 0-100 range is enforced at the Filament form layer
// (`minValue`/`maxValue`), the same place PurchaseSettingResource enforces
// its own bound — not here. This model-level test only proves the storage
// round-trips the two legal boundary values; refusing 101 or -1 is asserted
// against the form itself once SalesSettingResource exists (T031).
it('accepts a tax percent at the boundaries', function (float $percent): void {
    $settings = SalesSetting::factory()->create(['default_tax_percent' => $percent]);

    expect((float) $settings->default_tax_percent)->toBe($percent);
})->with([
    'zero' => [0.0],
    'one hundred' => [100.0],
]);

it('is a singleton fetched via current()', function (): void {
    $first = SalesSetting::current();
    $second = SalesSetting::current();

    expect($second->getKey())->toBe($first->getKey())
        ->and(SalesSetting::query()->count())->toBe(1);
});
