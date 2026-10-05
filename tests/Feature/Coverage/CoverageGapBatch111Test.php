<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

uses(RefreshDatabase::class);

function coverage111Table(): Table
{
    $owner = new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return TicketsTable::configure(Table::make($owner));
}

function coverage111Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

it('covers serialized equipment label with product and serial', function (): void {
    $variant = ProductVariant::factory()->create(['name' => 'Coverage Analyzer']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'serial_number' => 'SER-111',
    ]);

    $ticket = Ticket::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'external_equipment_name' => null,
    ]);
    $ticket->setRelation('serializedInventoryUnit', $unit->load('productVariant'));

    $column = coverage111Table()->getColumn('equipment');
    $state = coverage111Property($column, 'getStateUsing');

    expect($state)->toBeInstanceOf(Closure::class)
        ->and($state($ticket))->toContain('Coverage Analyzer', 'SER-111');
});

it('covers payment settlement domain failure notification path', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->create();
    $this->actingAs($actor);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingPayment]);
    $link = TicketPaymentLink::factory()->for($ticket)->create([
        'status' => PaymentLinkStatus::Cancelled,
    ]);
    $ticket->setRelation('paymentLink', $link);

    $method = new ReflectionMethod(TicketsTable::class, 'applySettlement');
    $method->invoke(null, $ticket, 'COVERAGE-111', 999999);

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Cancelled);
});

it('covers unauthenticated table actor guard', function (): void {
    auth()->logout();

    expect(fn () => new ReflectionMethod(TicketsTable::class, 'currentActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});
