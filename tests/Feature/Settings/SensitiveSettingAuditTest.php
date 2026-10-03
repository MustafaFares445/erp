<?php

declare(strict_types=1);

use App\Models\InventorySetting;
use App\Models\PurchaseSetting;
use App\Models\SalesSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('audits a change to a sensitive column with the old and new value and the actor', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $setting = SalesSetting::current();
    $setting->update(['default_tax_percent' => 5]);

    $entry = Activity::query()->where('log_name', 'configuration')->sole();

    expect($entry->subject_id)->toBe($setting->id)
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['changes']['default_tax_percent']['new'])->toEqual('5.00');
});

it('does not audit a change to a non-sensitive column', function (): void {
    SalesSetting::current()->update(['default_quotation_validity_days' => 45]);

    expect(Activity::query()->where('log_name', 'configuration')->count())->toBe(0);
});

it('audits purchasing threshold and inventory price-floor changes', function (): void {
    PurchaseSetting::current()->update(['approval_threshold_amount' => 1000]);
    InventorySetting::current()->update(['max_price_floor_override_percent' => 10]);

    expect(Activity::query()->where('log_name', 'configuration')->count())->toBe(2);
});
