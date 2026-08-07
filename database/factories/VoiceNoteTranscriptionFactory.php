<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TranscriptionConfidenceSource;
use App\Enums\TranscriptionStatus;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceNoteTranscription>
 */
final class VoiceNoteTranscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_voice_note_id' => EmployeeVoiceNote::factory(),
            'transcript' => null,
            'confidence' => null,
            'confidence_source' => TranscriptionConfidenceSource::Unavailable,
            'detected_language' => null,
            'provider' => null,
            'error_message' => null,
            'status' => TranscriptionStatus::Pending,
        ];
    }

    public function transcribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'transcript' => fake()->sentence(12),
            'confidence' => 87.50,
            'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
            'detected_language' => 'en',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'error_message' => 'The provider rejected the request.',
            'status' => TranscriptionStatus::Failed,
        ]);
    }

    public function unavailableConfidence(): static
    {
        return $this->state(fn (array $attributes): array => [
            'confidence' => null,
            'confidence_source' => TranscriptionConfidenceSource::Unavailable,
        ]);
    }

    public function derivedConfidence(): static
    {
        return $this->state(fn (array $attributes): array => [
            'confidence' => 72.30,
            'confidence_source' => TranscriptionConfidenceSource::DerivedFromLogProb,
        ]);
    }
}
