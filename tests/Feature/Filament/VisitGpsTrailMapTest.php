<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\User;
use App\Models\VisitGpsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the GPS trail as a live map with the recorded points', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    VisitGpsLog::factory()->for($visit, 'customerVisit')->create(['latitude' => 24.4539, 'longitude' => 54.3773]);
    VisitGpsLog::factory()->for($visit, 'customerVisit')->create(['latitude' => 24.4541, 'longitude' => 54.3774]);

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('GPS trail')
        ->assertSeeHtml('visitGpsTrailMap')
        ->assertSeeHtml('24.4539')
        ->assertDontSeeHtml('<table');
});

it('shows an empty-state placeholder when a visit has no GPS records', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['latitude' => null, 'longitude' => null]);
    $visit = CustomerVisit::factory()->for($customer, 'customer')->create();

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('No GPS records for this visit.');
});
