<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\OpportunityStage;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\ReceivableWriteOffs\Schemas\ReceivableWriteOffForm;
use App\Filament\Resources\SalesOpportunities\Tables\SalesOpportunitiesTable;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\SalesOpportunity;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Sales\OpportunityService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers supplier payment pay cancel auth and allocation normalization callbacks', function (): void {
    $payMethod = new ReflectionMethod(SupplierPaymentResource::class, 'payAction');
    $cancelMethod = new ReflectionMethod(SupplierPaymentResource::class, 'cancelAction');
    $pay = $payMethod->invoke(null);
    $cancel = $cancelMethod->invoke(null);

    $payment = SupplierPayment::factory()->create();

    auth()->logout();
    expect(fn (): mixed => $pay->getActionFunction()($payment, ['allocations' => []]))
        ->toThrow(LogicException::class, 'authenticated accounting user');
    expect(fn (): mixed => $cancel->getActionFunction()($payment))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $service = new class
    {
        /** @var list<array{bill_id:mixed,amount:mixed}> */
        public array $allocations = [];

        public int $cancelCalls = 0;

        /** @param list<array{bill_id:mixed,amount:mixed}> $allocations */
        public function paySupplierPayment(User $actor, SupplierPayment $payment, array $allocations): SupplierPayment
        {
            $this->allocations = $allocations;

            return $payment;
        }

        public function cancelSupplierPayment(User $actor, SupplierPayment $payment): SupplierPayment
        {
            $this->cancelCalls++;

            return $payment;
        }
    };
    app()->instance(AccountingDocumentService::class, $service);

    $pay->getActionFunction()($payment, [
        'allocations' => [
            'skip-me',
            ['bill_id' => 10, 'amount' => '12.50'],
        ],
    ]);
    $cancel->getActionFunction()($payment);

    expect($service->allocations)->toBe([
        ['bill_id' => 10, 'amount' => '12.50'],
    ])->and($service->cancelCalls)->toBe(1);
});

it('covers sales opportunity stage callback required-string and actor guards', function (): void {
    $stageMethod = new ReflectionMethod(SalesOpportunitiesTable::class, 'stageAction');
    $stageAction = $stageMethod->invoke(null);
    $callback = $stageAction->getActionFunction();

    $opportunity = SalesOpportunity::factory()->manual()->create();

    $stringMethod = new ReflectionMethod(SalesOpportunitiesTable::class, 'string');
    expect(fn (): mixed => $stringMethod->invoke(null, [], 'stage'))
        ->toThrow(LogicException::class, 'Expected stage');

    $actorMethod = new ReflectionMethod(SalesOpportunitiesTable::class, 'actor');
    auth()->logout();
    expect(fn (): mixed => $actorMethod->invoke(null))
        ->toThrow(LogicException::class, 'Authenticated user required');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $service = new class
    {
        public ?OpportunityStage $stage = null;

        public ?User $actor = null;

        public function transitionStage(
            SalesOpportunity $record,
            OpportunityStage $stage,
            mixed $expectedStage,
            User $actor,
            mixed $reason,
            mixed $note,
        ): SalesOpportunity {
            $this->stage = $stage;
            $this->actor = $actor;

            return $record;
        }
    };
    app()->instance(OpportunityService::class, $service);

    $callback($opportunity, [
        'stage' => OpportunityStage::Proposal->value,
        'close_reason' => '',
        'close_note' => 123,
    ]);

    expect($service->stage)->toBe(OpportunityStage::Proposal)
        ->and($service->actor?->is($actor))->toBeTrue();
});

it('covers notification template preview variables and fallback title', function (): void {
    $actor = User::factory()->admin()->create();

    $template = NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => null,
        'body' => 'Invoice {{ invoice_number }} is ready.',
        'variables' => ['invoice_number', '', 123],
        'is_active' => true,
    ]);

    Livewire::actingAs($actor)
        ->test(ListNotificationTemplates::class)
        ->callAction(TestAction::make('preview')->table($template))
        ->assertNotified()
        ->assertHasNoActionErrors();

    $eventOptions = new ReflectionMethod(NotificationTemplateResource::class, 'eventOptions');
    $channelOptions = new ReflectionMethod(NotificationTemplateResource::class, 'channelOptions');

    expect($eventOptions->invoke(null))->toHaveKey(NotificationEventKey::InvoiceIssued->value)
        ->and($channelOptions->invoke(null))->toHaveKey(NotificationChannel::Mail->value);
});

it('covers receivable write-off amount defaults for missing and valid invoices', function (): void {
    $schema = ReceivableWriteOffForm::configure(Schema::make());
    $amount = collect($schema->getComponents())
        ->first(static fn ($component): bool => $component instanceof TextInput && $component->getName() === 'amount');

    expect($amount)->toBeInstanceOf(TextInput::class);

    request()->merge(['invoice_id' => 999999999]);
    expect($amount->getDefaultState())->toBeNull();

    $invoice = Invoice::factory()->create([
        'issued_at' => now(),
        'total_amount' => '123.45',
        'amount_paid' => '23.45',
    ]);

    request()->merge(['invoice_id' => $invoice->getKey()]);
    expect($amount->getDefaultState())->toBe('100.00');
});
