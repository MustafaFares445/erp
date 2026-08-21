<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('previews and downloads ticket media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $ticket
        ->addMediaFromString('fake-file-bytes')
        ->usingFileName('ticket-attachment.pdf')
        ->toMediaCollection('ticket-attachments', 'local');

    $media = $ticket->fresh()->getFirstMedia('ticket-attachments');

    $this->actingAs($viewer)
        ->get(route('admin.tickets.media.preview', ['ticket' => $ticket, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.tickets.media.download', ['ticket' => $ticket, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses to serve media that does not belong to the requested ticket', function (): void {
    $viewer = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $otherTicket = Ticket::factory()->create();
    $otherTicket
        ->addMediaFromString('fake-file-bytes')
        ->usingFileName('ticket-attachment.pdf')
        ->toMediaCollection('ticket-attachments', 'local');

    $media = $otherTicket->fresh()->getFirstMedia('ticket-attachments');

    $this->actingAs($viewer)
        ->get(route('admin.tickets.media.preview', ['ticket' => $ticket, 'media' => $media]))
        ->assertNotFound();
});
