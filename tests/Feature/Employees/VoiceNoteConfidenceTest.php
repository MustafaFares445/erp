<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Models\VoiceNoteTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts confidence at both boundaries', function (): void {
    $lower = VoiceNoteTranscription::factory()->create([
        'confidence' => 0.00,
        'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
    ]);
    $upper = VoiceNoteTranscription::factory()->create([
        'confidence' => 100.00,
        'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
    ]);

    expect((float) $lower->confidence)->toBe(0.0)
        ->and((float) $upper->confidence)->toBe(100.0);
});

it('refuses a confidence value below zero or above one hundred', function (): void {
    expect(fn () => VoiceNoteTranscription::factory()->create([
        'confidence' => -0.01,
        'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
    ]))->toThrow(DomainException::class);

    expect(fn () => VoiceNoteTranscription::factory()->create([
        'confidence' => 100.01,
        'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
    ]))->toThrow(DomainException::class);
});

it('requires confidence to be null exactly when the source is Unavailable', function (): void {
    expect(fn () => VoiceNoteTranscription::factory()->create([
        'confidence' => null,
        'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
    ]))->toThrow(DomainException::class);

    expect(fn () => VoiceNoteTranscription::factory()->create([
        'confidence' => 50.0,
        'confidence_source' => TranscriptionConfidenceSource::Unavailable,
    ]))->toThrow(DomainException::class);

    $valid = VoiceNoteTranscription::factory()->create([
        'confidence' => null,
        'confidence_source' => TranscriptionConfidenceSource::Unavailable,
    ]);

    expect($valid->confidence)->toBeNull();
});

it('never labels a derived confidence as provider-reported', function (): void {
    $transcription = VoiceNoteTranscription::factory()->derivedConfidence()->create();

    expect($transcription->confidence_source)->toBe(TranscriptionConfidenceSource::DerivedFromLogProb)
        ->and($transcription->confidenceLabel())->toStartWith('≈ ')
        ->and($transcription->confidenceLabel())->not->toContain('provider-reported');
});

it('renders "Not reported by provider" and never 0.00% for a null confidence', function (): void {
    $transcription = VoiceNoteTranscription::factory()->unavailableConfidence()->create();

    expect($transcription->confidenceLabel())->toBe('Not reported by provider')
        ->and($transcription->confidenceLabel())->not->toContain('0.00%');
});

it('renders a plain percentage for a provider-reported confidence', function (): void {
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create();

    expect($transcription->confidenceLabel())->toBe('87.50%');
});
