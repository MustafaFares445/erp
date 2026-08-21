<?php

declare(strict_types=1);

namespace App\Services\Employees\Exceptions;

use App\Jobs\TranscribeVoiceNoteJob;
use RuntimeException;

/**
 * A transcription attempt failed for a reason no retry can fix: a 4xx caused
 * by the payload itself (unsupported format, oversized file, empty
 * recording) (research.md R-003). {@see TranscribeVoiceNoteJob}
 * catches this and writes `Failed` immediately, without retrying.
 */
final class TranscriptionPayloadException extends RuntimeException {}
