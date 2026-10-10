<?php

declare(strict_types=1);

use App\Enums\PaymentMethodType;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\PaymentMethods\Pages\ViewPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows translated payment method types and consistent collection account labels', function (string $locale, string $typeLabel, string $accountLabel): void {
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $method = PaymentMethod::factory()->create(['type' => PaymentMethodType::BankTransfer]);

    app()->setLocale($locale);

    Livewire::actingAs($admin)
        ->test(ListPaymentMethods::class)
        ->assertSeeText($typeLabel)
        ->assertSeeText($accountLabel)
        ->assertDontSeeText('bank_transfer');

    Livewire::actingAs($admin)
        ->test(ViewPaymentMethod::class, ['record' => $method->getKey()])
        ->assertSeeText($typeLabel)
        ->assertSeeText($accountLabel)
        ->assertDontSeeText('bank_transfer');
})->with([
    'English' => ['en', 'Bank transfer', 'Collection account'],
    'Arabic' => ['ar', 'تحويل بنكي', 'حساب التحصيل'],
]);
