<?php

declare(strict_types=1);

use App\Models\CustomerVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('previews and downloads visit media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();
    $visit
        ->addMediaFromString('fake-image-bytes')
        ->usingFileName('visit-photo.jpg')
        ->toMediaCollection('visit-attachments', 'local');

    $media = $visit->fresh()->getFirstMedia('visit-attachments');

    $this->actingAs($viewer)
        ->get(route('admin.visits.media.preview', ['visit' => $visit, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.visits.media.download', ['visit' => $visit, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses to serve media that does not belong to the requested visit', function (): void {
    $viewer = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();
    $otherVisit = CustomerVisit::factory()->create();
    $otherVisit
        ->addMediaFromString('fake-image-bytes')
        ->usingFileName('visit-photo.jpg')
        ->toMediaCollection('visit-attachments', 'local');

    $media = $otherVisit->fresh()->getFirstMedia('visit-attachments');

    $this->actingAs($viewer)
        ->get(route('admin.visits.media.preview', ['visit' => $visit, 'media' => $media]))
        ->assertNotFound();
});
