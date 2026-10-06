<?php

declare(strict_types=1);

use App\Filament\Resources\PriceLists\Pages\ManagePriceLists;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the price list management surface for an administrator', function (): void {
    $admin = User::factory()->admin()->create();

    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    $priceList = PriceList::query()->create([
        'name' => 'Clinic AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(ManagePriceLists::class)
        ->assertOk()
        ->assertSee($priceList->name)
        ->assertSee('AED');
});
