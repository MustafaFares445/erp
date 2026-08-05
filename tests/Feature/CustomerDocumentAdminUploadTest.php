<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Models\CustomerProfile;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('hydrates each document field from existing media when editing', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create();
    $profile->addMediaFromDisk(customerDocumentPath('license'), 'local')->toMediaCollection('license', 'local');
    $profile->refresh();

    Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->assertOk()
        ->assertFormSet(['license' => $profile->getFirstMedia('license')->getPathRelativeToRoot()]);
});

it('only authorizes document paths that belong to the record\'s own media', function (): void {
    $profile = CustomerProfile::factory()->create();
    $profile->addMediaFromDisk(customerDocumentPath('license'), 'local')->toMediaCollection('license', 'local');
    $profile->refresh();
    $media = $profile->getFirstMedia('license');

    $allowFilePathUsing = customerDocumentAllowFilePathUsingClosure('license');

    expect($allowFilePathUsing(null, $media->getPathRelativeToRoot()))->toBeFalse()
        ->and($allowFilePathUsing($profile, 'unknown/path.png'))->toBeFalse()
        ->and($allowFilePathUsing($profile, $media->getPathRelativeToRoot()))->toBeTrue();
});

function customerDocumentPath(string $collection): string
{
    return UploadedFile::fake()->image($collection.'.jpg')->store('customer-documents/'.$collection, 'local');
}

function customerDocumentAllowFilePathUsingClosure(string $collection): Closure
{
    $method = new ReflectionMethod(CustomerForm::class, 'documentUpload');
    /** @var FileUpload $component */
    $component = $method->invoke(null, $collection, ucfirst($collection));

    $property = new ReflectionProperty($component, 'allowFilePathUsing');

    return $property->getValue($component);
}
