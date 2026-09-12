<?php

declare(strict_types=1);

return [
    'retention_days' => (int) env('DOCUMENT_EXPORT_RETENTION_DAYS', 7),
    'overlap_release_seconds' => (int) env('DOCUMENT_EXPORT_OVERLAP_RELEASE_SECONDS', 30),
    'overlap_lock_seconds' => (int) env('DOCUMENT_EXPORT_OVERLAP_LOCK_SECONDS', 3600),
];
