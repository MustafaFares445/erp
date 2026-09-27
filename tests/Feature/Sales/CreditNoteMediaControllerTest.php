<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Models\CreditNote;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

it('previews and downloads credit note PDFs for users with permission', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(SalesPermission::CreditNoteView->value);

    $creditNote = CreditNote::factory()->create();
    $creditNote->addMediaFromString('%PDF-1.4')
        ->usingFileName('credit-note.pdf')
        ->toMediaCollection('credit-note-pdf', 'local');
    $media = $creditNote->fresh()->getFirstMedia('credit-note-pdf');

    if (! $media instanceof Media) {
        throw new RuntimeException('Expected the generated credit note PDF media to exist.');
    }

    $this->actingAs($viewer)
        ->get(route('admin.credit-notes.media.preview', ['creditNote' => $creditNote, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.credit-notes.media.download', ['creditNote' => $creditNote, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses to serve a PDF attached to another credit note', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(SalesPermission::CreditNoteView->value);

    $creditNote = CreditNote::factory()->create();
    $otherCreditNote = CreditNote::factory()->create();
    $otherCreditNote->addMediaFromString('%PDF-1.4')
        ->usingFileName('other-credit-note.pdf')
        ->toMediaCollection('credit-note-pdf', 'local');
    $media = $otherCreditNote->fresh()->getFirstMedia('credit-note-pdf');

    if (! $media instanceof Media) {
        throw new RuntimeException('Expected the other credit note PDF media to exist.');
    }

    $this->actingAs($viewer)
        ->get(route('admin.credit-notes.media.preview', ['creditNote' => $creditNote, 'media' => $media]))
        ->assertNotFound();
});
