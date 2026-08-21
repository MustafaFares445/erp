<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Schemas\TicketForm;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketAttachmentSynchronizer;
use App\Services\Support\TicketIntakeService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('checks ticket_number uniqueness including against soft-deleted rows on generation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();

    $service = app(TicketIntakeService::class);

    $first = $service->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'First ticket',
        'description' => 'Description',
    ], $actor);
    $first->delete();

    $second = $service->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Second ticket',
        'description' => 'Description',
    ], $actor);

    expect($second->ticket_number)->not->toBe($first->ticket_number)
        ->and(Ticket::withTrashed()->pluck('ticket_number')->unique())->toHaveCount(2);
});

it('rejects saving without customer, type, priority, or title', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('Support Manager');

    Livewire::actingAs($admin)
        ->test(CreateTicket::class)
        ->fillForm([
            'customer_id' => null,
            'type' => null,
            'priority' => null,
            'title' => null,
            'description' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['customer_id', 'type', 'priority', 'title']);
});

it('never produces a duplicate ticket_number under concurrent creation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();
    $service = app(TicketIntakeService::class);

    $numbers = collect(range(1, 5))->map(fn (int $i): string => $service->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Ticket '.$i,
        'description' => 'Description',
    ], $actor)->ticket_number);

    expect($numbers->unique())->toHaveCount(5);
});

it('sets pending_payment and a pending reason when chargeable, pending otherwise', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();
    $service = app(TicketIntakeService::class);

    $chargeable = $service->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Chargeable ticket',
        'description' => 'Description',
        'is_chargeable' => true,
        'amount' => 100,
        'currency' => 'USD',
    ], $actor);

    $free = $service->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Free ticket',
        'description' => 'Description',
        'is_chargeable' => false,
    ], $actor);

    expect($chargeable->status)->toBe(TicketStatus::PendingPayment)
        ->and($chargeable->pending_reason)->not->toBeNull()
        ->and($free->status)->toBe(TicketStatus::Pending)
        ->and($free->pending_reason)->toBeNull();
});

it('rejects a non-permitted attachment file type before writing any record', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();

    Storage::fake('local');
    $path = 'ticket-attachments/malicious.exe';
    Storage::disk('local')->put($path, 'not-a-real-executable');

    expect(fn () => app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Ticket with a bad attachment',
        'description' => 'Description',
        'attachments' => [$path],
    ], $actor))->toThrow(ValidationException::class);

    expect(Ticket::query()->count())->toBe(0);
});

it('supports searching by number/customer/title and filtering by status/type/priority/assignee', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Acme Corp']);
    $actor = User::factory()->admin()->create();
    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::HardwareIssue->value,
        'priority' => TicketPriority::Urgent->value,
        'title' => 'Unmistakable search phrase',
        'description' => 'Description',
    ], $actor);

    expect(Ticket::query()->where('ticket_number', $ticket->ticket_number)->exists())->toBeTrue()
        ->and(Ticket::query()->where('title', 'like', '%Unmistakable%')->exists())->toBeTrue()
        ->and(Ticket::query()->whereHas('customer', fn ($q) => $q->where('company_name', 'Acme Corp'))->exists())->toBeTrue()
        ->and(Ticket::query()->where('type', TicketType::HardwareIssue)->count())->toBe(1)
        ->and(Ticket::query()->where('priority', TicketPriority::Urgent)->count())->toBe(1);
});

it('records a ticket-continuation link through the service and shows it visible from the new ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();

    $closedTicket = Ticket::factory()->create(['status' => TicketStatus::Closed]);

    $continuation = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Continuing the prior matter',
        'description' => 'Description',
        'continued_from_ticket_id' => $closedTicket->getKey(),
    ], $actor);

    expect($continuation->continued_from_ticket_id)->toBe($closedTicket->getKey())
        ->and($continuation->continuedFromTicket?->is($closedTicket))->toBeTrue();
});

it('creates a ticket continuing a closed one through the actual Create Ticket form, with the field locked on edit', function (): void {
    $customer = CustomerProfile::factory()->create();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $closedTicket = Ticket::factory()->create(['status' => TicketStatus::Closed]);

    Livewire::actingAs($manager)
        ->test(CreateTicket::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Continuing via the form',
            'description' => 'Description',
            'continued_from_ticket_id' => $closedTicket->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ticket = Ticket::query()->where('title', 'Continuing via the form')->firstOrFail();

    expect($ticket->continued_from_ticket_id)->toBe($closedTicket->getKey());

    Livewire::actingAs($manager)
        ->test(EditTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertFormFieldIsDisabled('continued_from_ticket_id');
});

it('archives a ticket on delete rather than removing it, keeping its number reserved', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();
    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'To be archived',
        'description' => 'Description',
    ], $actor);
    $number = $ticket->ticket_number;

    $ticket->delete();

    expect(Ticket::query()->count())->toBe(0)
        ->and(Ticket::withTrashed()->where('ticket_number', $number)->exists())->toBeTrue();
});

it('loads and saves the actual Edit form for a ticket, with chargeable fields locked', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('Support Manager');

    $ticket = Ticket::factory()->create([
        'type' => TicketType::GeneralSupport,
        'priority' => TicketPriority::Normal,
        'title' => 'Original title',
    ]);

    Livewire::actingAs($admin)
        ->test(EditTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertFormFieldIsDisabled('is_chargeable')
        ->fillForm([
            'type' => TicketType::HardwareIssue->value,
            'priority' => TicketPriority::High->value,
            'title' => 'Updated title',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($ticket->refresh()->type)->toBe(TicketType::HardwareIssue)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->title)->toBe('Updated title');
});

it("authorizes the ticket attachments field to only accept paths belonging to the ticket's own media", function (): void {
    Storage::fake('local');
    $ticket = Ticket::factory()->create();
    $path = 'ticket-attachments/existing.pdf';
    Storage::disk('local')->put($path, 'contents');
    app(TicketAttachmentSynchronizer::class)->sync($ticket, [$path]);
    $media = $ticket->fresh()->getFirstMedia('ticket-attachments');

    $allowFilePathUsing = ticketFormAllowFilePathUsingClosure();

    expect($allowFilePathUsing(null, $path))->toBeFalse()
        ->and($allowFilePathUsing($ticket, 'unknown/path.pdf'))->toBeFalse()
        ->and($allowFilePathUsing($ticket, $media->getPathRelativeToRoot()))->toBeTrue();
});

function ticketFormAllowFilePathUsingClosure(): Closure
{
    $method = new ReflectionMethod(TicketForm::class, 'attachmentsUpload');
    /** @var FileUpload $component */
    $component = $method->invoke(null);

    $property = new ReflectionProperty($component, 'allowFilePathUsing');

    return $property->getValue($component);
}
