<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\TranscriptionConfidenceSource;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\Exceptions\TranscriptionTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The production driver (D6): a direct HTTP call to OpenAI's audio
 * transcription endpoint. No SDK — this is the only class that may
 * reference the OpenAI client (contracts/voice-note-ai.md, ArchTest).
 */
final class OpenAiWhisperTranscriber implements VoiceNoteTranscriber
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        $audio = Storage::disk($request->audioDisk)->get($request->audioDiskPath);

        if ($audio === null) {
            throw new TranscriptionPayloadException('The recorded audio file could not be read.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken($this->apiKey())
                ->timeout($this->timeout())
                ->attach('file', $audio, basename($request->audioDiskPath))
                ->post('audio/transcriptions', array_filter([
                    'model' => $this->model(),
                    'response_format' => 'verbose_json',
                    'language' => $request->language,
                ]));
        } catch (ConnectionException $connectionException) {
            throw new TranscriptionTransportException('Could not reach the transcription provider.', $connectionException->getCode(), previous: $connectionException);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new TranscriptionTransportException(sprintf('The transcription provider returned HTTP %d.', $response->status()));
        }

        if ($response->clientError()) {
            throw new TranscriptionPayloadException($this->errorMessage($response) ?? sprintf('The transcription provider rejected the request (HTTP %s).', $response->status()));
        }

        return $this->parseResult($response->json(), $request->language);
    }

    private function parseResult(mixed $payload, ?string $requestedLanguage): TranscriptionResult
    {
        if (! is_array($payload) || ! isset($payload['text']) || ! is_string($payload['text'])) {
            return new TranscriptionResult(
                transcript: '',
                confidence: null,
                confidenceSource: TranscriptionConfidenceSource::Unavailable,
                detectedLanguage: null,
                provider: $this->providerIdentity(),
            );
        }

        $detectedLanguage = is_string($payload['language'] ?? null) ? $payload['language'] : $requestedLanguage;
        [$confidence, $source] = $this->resolveConfidence($payload);

        return new TranscriptionResult(
            transcript: $payload['text'],
            confidence: $confidence,
            confidenceSource: $source,
            detectedLanguage: $detectedLanguage,
            provider: $this->providerIdentity(),
        );
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{0: ?float, 1: TranscriptionConfidenceSource}
     */
    private function resolveConfidence(array $payload): array
    {
        if (isset($payload['confidence']) && is_numeric($payload['confidence'])) {
            return [$this->clamp((float) $payload['confidence']), TranscriptionConfidenceSource::ProviderReported];
        }

        $segments = $payload['segments'] ?? null;

        if (! is_array($segments) || $segments === []) {
            return [null, TranscriptionConfidenceSource::Unavailable];
        }

        $totalDuration = 0.0;
        $weightedSum = 0.0;

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (! is_numeric($segment['avg_logprob'] ?? null)) {
                continue;
            }

            if (! is_numeric($segment['start'] ?? null)) {
                continue;
            }

            if (! is_numeric($segment['end'] ?? null)) {
                continue;
            }

            $duration = (float) $segment['end'] - (float) $segment['start'];

            if ($duration <= 0.0) {
                continue;
            }

            $weightedSum += $duration * exp((float) $segment['avg_logprob']);
            $totalDuration += $duration;
        }

        if ($totalDuration <= 0.0) {
            return [null, TranscriptionConfidenceSource::Unavailable];
        }

        return [$this->clamp(($weightedSum / $totalDuration) * 100), TranscriptionConfidenceSource::DerivedFromLogProb];
    }

    private function clamp(float $value): float
    {
        return round(min(100.0, max(0.0, $value)), 2);
    }

    private function errorMessage(Response $response): ?string
    {
        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['error'] ?? null)) {
            return null;
        }

        $message = $payload['error']['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    private function providerIdentity(): string
    {
        return 'openai.'.$this->model();
    }

    private function apiKey(): string
    {
        $key = config('services.openai.api_key');

        return is_string($key) ? $key : '';
    }

    private function model(): string
    {
        $model = config('services.openai.transcribe_model', 'whisper-1');

        return is_string($model) && $model !== '' ? $model : 'whisper-1';
    }

    private function timeout(): int
    {
        $timeout = config('services.openai.transcribe_timeout', 120);

        return is_numeric($timeout) ? (int) $timeout : 120;
    }

    private function baseUrl(): string
    {
        $base = config('services.openai.transcribe_base_url');

        return is_string($base) && $base !== '' ? $base : 'https://api.openai.com/v1';
    }
}
