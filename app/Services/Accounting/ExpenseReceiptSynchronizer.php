<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Expense;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExpenseReceiptSynchronizer
{
    private const string Disk = 'local';

    private const string UploadDirectory = 'expense-receipts/';

    private const int MaximumFileSizeInBytes = 10 * 1024 * 1024;

    /** @var array<string> */
    private const array AcceptedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function sync(Expense $expense, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if ($expense->getFirstMedia('receipt')?->getPathRelativeToRoot() === $path) {
            return;
        }

        $this->ensurePathIsValid($path);

        $expense->addMediaFromDisk($path, self::Disk)->toMediaCollection('receipt', self::Disk);

        Storage::disk(self::Disk)->delete($path);
    }

    private function ensurePathIsValid(string $path): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, self::UploadDirectory) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'receipt' => 'The uploaded receipt could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                'receipt' => 'The receipt may not be greater than 10 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::AcceptedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'receipt' => 'The receipt must be a JPEG, PNG, WebP, or PDF file.',
            ]);
        }
    }
}
