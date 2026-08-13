<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\VisitResource;
use App\Models\CustomerVisit;
use App\Models\User;
use App\Models\VisitGpsLog;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders the visit list and view pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $visit = CustomerVisit::factory()->completed()->create();
    VisitGpsLog::factory()->for($visit, 'customerVisit')->create();
    $visit->addMediaFromString('fake-image-bytes')->usingFileName('photo.jpg')->toMediaCollection('visit-attachments', 'local');

    $this->actingAs($admin)->get(VisitResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(VisitResource::getUrl('view', ['record' => $visit]))->assertOk();
});
