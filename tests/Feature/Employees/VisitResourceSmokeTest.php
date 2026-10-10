<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\User;
use App\Models\VisitGpsLog;
use App\Models\VoiceNoteTranscription;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\IconEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders visit pages with Arabic labels and enum statuses', function (): void {
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->assignRole('System Admin');

    $visit = CustomerVisit::factory()->completed()->create();
    VisitGpsLog::factory()->for($visit, 'customerVisit')->create();
    $visit->addMediaFromString('fake-image-bytes')->usingFileName('photo.jpg')->toMediaCollection('visit-attachments', 'local');
    $voiceNote = EmployeeVoiceNote::factory()->create([
        'customer_visit_id' => $visit->getKey(),
        'status' => 'Transcribed',
    ]);
    VoiceNoteTranscription::factory()->transcribed()->for($voiceNote, 'employeeVoiceNote')->create();

    $this->actingAs($admin)
        ->get(VisitResource::getUrl('index'))
        ->assertSee('مرجع الزيارة')
        ->assertSee('نوع الزيارة')
        ->assertSee('بداية الموعد');

    $this->actingAs($admin)
        ->get(VisitResource::getUrl('create'))
        ->assertSee('تعيين الزيارة')
        ->assertSee('نوع الزيارة');

    $this->actingAs($admin)
        ->get(VisitResource::getUrl('view', ['record' => $visit]))
        ->assertSee('المعلومات الأساسية')
        ->assertSee('مرجع الزيارة')
        ->assertSee('المدة الفعلية')
        ->assertSee('تم التفريغ')
        ->assertSee('ناجح');
});

it('localizes customer type and keeps coordinate pairs in latitude-longitude order in both locales', function (string $locale, string $customerTypeLabel): void {
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $admin->assignRole('System Admin');

    $customer = CustomerProfile::factory()->create([
        'customer_type' => 'other',
        'latitude' => '24.4667000',
        'longitude' => '54.3667000',
    ]);
    $visit = CustomerVisit::factory()->create([
        'customer_id' => $customer->getKey(),
        'check_in_latitude' => '24.4000000',
        'check_in_longitude' => '54.3000000',
    ]);

    app()->setLocale($locale);

    $this->actingAs($admin)
        ->get(VisitResource::getUrl('view', ['record' => $visit]))
        ->assertOk()
        ->assertSee($customerTypeLabel)
        ->assertSeeHtml('<bdi dir="ltr">24.4667, 54.3667</bdi>')
        ->assertSeeHtml('<bdi dir="ltr">24.4, 54.3</bdi>');
})->with([
    'English' => ['en', 'Other'],
    'Arabic' => ['ar', 'أخرى'],
]);

it('shows a missing outcome as not recorded and cleared warning flags in a neutral colour', function (): void {
    // Release QA 2026-10-10: blank Outcome and red X icons for a visit without location warning or follow-up.
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $visit = CustomerVisit::factory()->create([
        'outcome_code' => null,
        'check_in_latitude' => '24.4000000',
        'check_in_longitude' => '54.3000000',
        'location_warning' => false,
        'follow_up_required' => false,
    ]);

    $isNeutralWhenCleared = static fn (IconEntry $entry): bool => $entry->getColor(false) === 'gray' && $entry->getColor(true) === 'warning';

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSeeText('Not recorded')
        ->assertSchemaComponentExists('location_warning', 'infolist', $isNeutralWhenCleared)
        ->assertSchemaComponentExists('follow_up_required', 'infolist', $isNeutralWhenCleared);
});

it('schedules visits with the panel date-time picker instead of the browser-locale native input', function (): void {
    // Release QA 2026-10-10: the Arabic create form showed the browser's English "mm/dd/yyyy --:-- --" placeholder.
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $isPanelPicker = static fn (DateTimePicker $field): bool => ! $field->isNative();

    Livewire::actingAs($admin)
        ->test(CreateVisit::class)
        ->assertFormFieldExists('scheduled_start_at', $isPanelPicker)
        ->assertFormFieldExists('scheduled_end_at', $isPanelPicker);
});
