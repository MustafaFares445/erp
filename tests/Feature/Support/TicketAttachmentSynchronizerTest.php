<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Services\Support\TicketAttachmentSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('moves an uploaded attachment into the ticket-attachments collection and clears the temp file', function (): void {
    $ticket = Ticket::factory()->create();
    $path = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')->store('ticket-attachments', 'local');

    app(TicketAttachmentSynchronizer::class)->sync($ticket, [$path]);

    expect($ticket->fresh()->getMedia('ticket-attachments'))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('does nothing when no attachments are given', function (): void {
    $ticket = Ticket::factory()->create();

    app(TicketAttachmentSynchronizer::class)->sync($ticket, []);

    expect($ticket->fresh()->getMedia('ticket-attachments'))->toHaveCount(0);
});

it('does nothing when the given paths already match the stored attachments', function (): void {
    $ticket = Ticket::factory()->create();
    $synchronizer = app(TicketAttachmentSynchronizer::class);
    $path = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')->store('ticket-attachments', 'local');
    $synchronizer->sync($ticket, [$path]);

    $storedPath = $ticket->fresh()->getFirstMedia('ticket-attachments')?->getPathRelativeToRoot();

    // The Filament form redisplays the stored media's own path as the field's current value, so
    // resubmitting the form unchanged passes that path straight back in — one outside the
    // ticket-attachments/ upload prefix, which would fail validation if this early return did not exist.
    $synchronizer->sync($ticket->fresh(), [$storedPath]);

    expect($ticket->fresh()->getMedia('ticket-attachments'))->toHaveCount(1);
});

it('rejects a file outside the expected upload directory', function (): void {
    $ticket = Ticket::factory()->create();
    $path = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')->store('elsewhere', 'local');

    app(TicketAttachmentSynchronizer::class)->sync($ticket, [$path]);
})->throws(ValidationException::class);

it('rejects a file larger than the maximum allowed size', function (): void {
    $ticket = Ticket::factory()->create();
    $path = 'ticket-attachments/oversized.pdf';
    // UploadedFile::fake()->create() only fakes the reported size, not the bytes actually
    // written to a faked disk, so the disk is filled directly to exceed the 10 MB limit for real.
    Storage::disk('local')->put($path, str_repeat('0', 10 * 1024 * 1024 + 1));

    app(TicketAttachmentSynchronizer::class)->sync($ticket, [$path]);
})->throws(ValidationException::class);
