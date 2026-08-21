<?php

declare(strict_types=1);

namespace App\Services\Employees\Exceptions;

use App\Jobs\TranscribeVoiceNoteJob;
use RuntimeException;

/**
 * A transcription attempt failed for a reason a retry might fix: a network
 * failure, a timeout, HTTP 429, or a 5xx response (research.md R-003).
 * {@see TranscribeVoiceNoteJob} lets this propagate so Laravel's
 * queue retries it, bounded by the job's own `$tries`/backoff.
 */
final class TranscriptionTransportException extends RuntimeException {}
