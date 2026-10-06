<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Voice-Note Transcription
    |--------------------------------------------------------------------------
    |
    | `driver` selects the VoiceNoteTranscriber implementation ("openai" or
    | "fake"). Tests force "fake" via phpunit.xml so no test reaches the
    | network. `max_bytes` rejects oversized audio before it is dispatched
    | to the queue (contracts/voice-note-ai.md).
    |
    */
    'transcription' => [
        'driver' => env('EMPLOYEES_TRANSCRIBE_DRIVER', 'openai'),
        'max_bytes' => env('EMPLOYEES_TRANSCRIBE_MAX_BYTES', 26214400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Work-Time Adherence Default
    |--------------------------------------------------------------------------
    |
    | Fallback required visit duration (minutes) used when a plan's own
    | `required_visit_minutes` is null (D5, contracts/performance-scoring.md).
    |
    */
    'default_required_visit_minutes' => env('EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Visit Location Validation
    |--------------------------------------------------------------------------
    |
    | A check-in farther than this many meters from the customer's saved
    | coordinates is retained as a location warning. It never blocks the
    | visit lifecycle; the snapshot and any explicit override stay auditable.
    |
    */
    'visit_location_warning_meters' => env('EMPLOYEES_VISIT_LOCATION_WARNING_METERS', 250),

];
