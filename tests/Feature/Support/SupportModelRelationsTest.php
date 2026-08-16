<?php

declare(strict_types=1);

use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketMessage;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it("resolves a maintenance record's matched product variant and serialized inventory unit", function (): void {
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'product_variant_id' => $variant->id,
        'serialized_inventory_unit_id' => $unit->id,
    ]);

    expect($record->productVariant?->is($variant))->toBeTrue()
        ->and($record->serializedInventoryUnit?->is($unit))->toBeTrue();
});

it("resolves a service record part's parent service record and the reversing user", function (): void {
    $part = ServiceRecordPart::factory()->reversed()->create();

    expect($part->maintenanceTask)->not->toBeNull()
        ->and($part->reversedBy)->not->toBeNull()
        ->and($part->reversedBy?->id)->toBe($part->reversed_by);
});

it('resolves the user who last updated an SLA policy', function (): void {
    $admin = User::factory()->admin()->create();
    $policy = SlaPolicy::factory()->create();

    $policy->forceFill(['updated_by' => $admin->id])->saveQuietly();

    expect($policy->refresh()->updatedBy?->is($admin))->toBeTrue();
});

it('resolves the prior ticket a ticket continues from', function (): void {
    $original = Ticket::factory()->create();
    $continuation = Ticket::factory()->create(['continued_from_ticket_id' => $original->id]);

    expect($continuation->continuedFromTicket?->is($original))->toBeTrue();
});

it("resolves a ticket assignment's ticket, assigned employee, and the manager who made the assignment", function (): void {
    $manager = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $assignment = TicketAssignment::factory()->create(['ticket_id' => $ticket->id, 'assigned_by' => $manager->id]);

    expect($assignment->ticket?->is($ticket))->toBeTrue()
        ->and($assignment->employee)->not->toBeNull()
        ->and($assignment->assignedBy?->is($manager))->toBeTrue();
});

it("resolves a ticket message's parent ticket and sender", function (): void {
    $sender = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $message = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'sender_user_id' => $sender->id]);

    expect($message->ticket?->is($ticket))->toBeTrue()
        ->and($message->sender?->is($sender))->toBeTrue();
});

it('resolves the user who settled a ticket payment link', function (): void {
    $admin = User::factory()->admin()->create();
    $link = TicketPaymentLink::factory()->settled()->create(['settled_by' => $admin->id]);

    expect($link->settledBy?->is($admin))->toBeTrue();
});

it('rejects updating or deleting a ticket assignment, even directly on the model', function (): void {
    $assignment = TicketAssignment::factory()->create();

    expect(fn () => $assignment->update(['assigned_at' => now()]))->toThrow(DomainException::class)
        ->and(fn () => $assignment->delete())->toThrow(DomainException::class);
});

it('rejects updating or deleting a ticket message, even directly on the model', function (): void {
    $message = TicketMessage::factory()->create();

    expect(fn () => $message->update(['message' => 'edited']))->toThrow(DomainException::class)
        ->and(fn () => $message->delete())->toThrow(DomainException::class);
});
