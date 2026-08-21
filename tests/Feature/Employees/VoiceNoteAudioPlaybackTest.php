<?php

declare(strict_types=1);

use App\Models\EmployeeVoiceNote;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('serves voice-note audio through a temporary signed URL to an authorized player', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $media = $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $url = URL::temporarySignedRoute(
        'admin.voice-notes.media.play',
        now()->addMinutes(15),
        ['voiceNote' => $voiceNote->id, 'media' => $media->id],
    );

    expect($url)->toContain('signature=')
        ->and($media->disk)->toBe('local');

    $this->actingAs($admin)->get($url)->assertOk();
});

it('rejects a request without a valid signature, even from an authorized user', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $media = $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $unsignedUrl = route('admin.voice-notes.media.play', ['voiceNote' => $voiceNote->id, 'media' => $media->id]);

    $this->actingAs($admin)->get($unsignedUrl)->assertForbidden();
});

it('denies playback to a role without the voice-note.play ability, even with a valid signature', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $media = $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $url = URL::temporarySignedRoute(
        'admin.voice-notes.media.play',
        now()->addMinutes(15),
        ['voiceNote' => $voiceNote->id, 'media' => $media->id],
    );

    $this->actingAs($reviewer)->get($url)->assertForbidden();
});
