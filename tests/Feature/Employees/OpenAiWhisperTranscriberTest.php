<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\Exceptions\TranscriptionTransportException;
use App\Services\Employees\OpenAiWhisperTranscriber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('rejects when the recorded audio file cannot be read from disk', function (): void {
    $transcriber = new OpenAiWhisperTranscriber;

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'missing/audio.mp3')))
        ->toThrow(TranscriptionPayloadException::class);
});

it('treats a connection failure as a retryable transport exception', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(function (): never {
        throw new ConnectionException('Timed out');
    });

    $transcriber = new OpenAiWhisperTranscriber;

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3')))
        ->toThrow(TranscriptionTransportException::class);
});

it('treats a 429 and a 5xx response as a retryable transport exception', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response('', 429));

    $transcriber = new OpenAiWhisperTranscriber;

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3')))
        ->toThrow(TranscriptionTransportException::class);

    Http::fake(fn () => Http::response('', 503));

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3')))
        ->toThrow(TranscriptionTransportException::class);
});

it('never retries a 4xx payload failure, surfacing the provider error message', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response(['error' => ['message' => 'Unsupported audio format.']], 400));

    $transcriber = new OpenAiWhisperTranscriber;

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3')))
        ->toThrow(TranscriptionPayloadException::class, 'Unsupported audio format.');
});

it('falls back to a generic message when a 4xx response has no parseable error', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response('not json', 422));

    $transcriber = new OpenAiWhisperTranscriber;

    expect(fn (): TranscriptionResult => $transcriber->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3')))
        ->toThrow(TranscriptionPayloadException::class);
});

it('stores a provider-reported confidence verbatim when the response includes one', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response([
        'text' => 'Customer asked about pricing.',
        'language' => 'en',
        'confidence' => 92.5,
    ], 200));

    $result = (new OpenAiWhisperTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3', 'en'));

    expect($result->transcript)->toBe('Customer asked about pricing.')
        ->and($result->confidence)->toBe(92.5)
        ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::ProviderReported)
        ->and($result->detectedLanguage)->toBe('en')
        ->and($result->provider)->toBe('openai.whisper-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'en'));
});

it('derives a confidence from segment log-probabilities when none is reported', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response([
        'text' => 'Marhaba, kefak?',
        'segments' => [
            ['start' => 0.0, 'end' => 2.0, 'avg_logprob' => -0.05],
            ['start' => 2.0, 'end' => 4.0, 'avg_logprob' => -0.10],
            ['start' => 4.0, 'end' => 4.0, 'avg_logprob' => -5.0],
            'not-an-array',
            ['start' => 0.0, 'end' => 1.0],
            ['end' => 2.0, 'avg_logprob' => -0.05],
            ['start' => 0.0, 'avg_logprob' => -0.05],
        ],
    ], 200));

    $result = (new OpenAiWhisperTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3'));

    expect($result->confidenceSource)->toBe(TranscriptionConfidenceSource::DerivedFromLogProb)
        ->and($result->confidence)->toBeGreaterThan(0.0)
        ->and($result->confidence)->toBeLessThanOrEqual(100.0);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
});

it('reports Unavailable confidence when every segment is malformed or has zero duration', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response([
        'text' => 'hello',
        'segments' => [
            ['start' => 1.0, 'end' => 1.0, 'avg_logprob' => -0.05],
            'not-an-array',
        ],
    ], 200));

    $result = (new OpenAiWhisperTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3'));

    expect($result->confidence)->toBeNull()
        ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::Unavailable);
});

it('reports Unavailable confidence when the response has neither a confidence field nor usable segments', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response(['text' => 'hello'], 200));

    $result = (new OpenAiWhisperTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3'));

    expect($result->confidence)->toBeNull()
        ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::Unavailable);
});

it('treats a malformed 200 response as Unavailable rather than a retryable failure', function (): void {
    Storage::disk('local')->put('voice-notes/note.mp3', 'audio-bytes');
    Http::fake(fn () => Http::response(['unexpected' => 'shape'], 200));

    $result = (new OpenAiWhisperTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3'));

    expect($result->transcript)->toBe('')
        ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::Unavailable)
        ->and($result->detectedLanguage)->toBeNull();
});
