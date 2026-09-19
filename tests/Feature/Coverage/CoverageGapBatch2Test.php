<?php

declare(strict_types=1);

use App\Data\Sales\OpportunityData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\CampaignStatus;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\ProductStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Campaigns\RelationManagers\CampaignRecipientsRelationManager;
use App\Filament\Resources\InventoryReservations\Actions\InventoryReservationActions;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ThirdPartyCostsRelationManager;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\InventoryReservation;
use App\Models\MaintenanceRecord;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Sales\OpportunityService;
use App\Services\Sales\QuotationService;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

uses(RefreshDatabase::class);

function gapBatch2Table(): Table
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

    return Table::make($owner);
}

function gapBatch2Method(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

it('covers EditQuotation valid update path including line normalization', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'min_price' => 0,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );

    $page = new ReflectionClass(EditQuotation::class)->newInstanceWithoutConstructor();
    $updated = gapBatch2Method(EditQuotation::class, 'handleRecordUpdate')->invoke($page, $quotation, [
        'customer_id' => (string) $customer->getKey(),
        'employee_id' => null,
        'payment_term_id' => null,
        'issue_date' => today()->toDateString(),
        'expires_at' => null,
        'lines' => [[
            'product_variant_id' => (string) $variant->getKey(),
            'quantity' => '2',
            'unit_price' => '125',
            'tax_amount' => '',
            'description' => 'Updated from page',
        ]],
    ]);

    expect($updated)->toBeInstanceOf(Quotation::class)
        ->and((float) $updated->refresh()->subtotal)->toBe(250.0)
        ->and((float) $updated->lines()->sole()->unit_price)->toBe(125.0);
});

it('covers opportunity validation creation stage transition and quotation-close helpers', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
    $service = app(OpportunityService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    expect(fn () => $service->create(new OpportunityData(summary: 'Missing party'), $actor))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->create(new OpportunityData(
            summary: 'Negative',
            customerId: (int) $customer->getKey(),
            estimatedValueMinor: -1,
        ), $actor))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(new OpportunityData(
            summary: 'Probability',
            customerId: (int) $customer->getKey(),
            probabilityPercent: 101,
        ), $actor))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(new OpportunityData(
            summary: 'AI origin',
            customerId: (int) $customer->getKey(),
            origin: OpportunityOrigin::AiVoiceNote,
        ), $actor))->toThrow(DomainException::class, 'cannot claim AI');

    $opportunity = $service->create(new OpportunityData(
        summary: '  Coverage opportunity  ',
        customerId: (int) $customer->getKey(),
        estimatedValueMinor: 1000,
        probabilityPercent: 25,
        origin: OpportunityOrigin::Manual,
    ), $actor);

    expect($opportunity->origin)->toBe(OpportunityOrigin::ExistingCustomer)
        ->and($opportunity->summary)->toBe('Coverage opportunity');

    expect(fn () => $service->transitionStage(
        $opportunity,
        OpportunityStage::Qualification,
        null,
        $actor,
    ))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $service->transitionStage(
            $opportunity,
            OpportunityStage::ClosedLost,
            null,
            $actor,
            OpportunityCloseReason::WonAsQuoted,
        ))->toThrow(ValidationException::class)
        ->and(fn () => $service->transitionStage(
            $opportunity,
            OpportunityStage::ClosedWon,
            null,
            $actor,
            OpportunityCloseReason::Other,
        ))->toThrow(ValidationException::class);

    $moved = $service->transitionStage($opportunity, OpportunityStage::Proposal, null, $actor);
    expect($moved->stage)->toBe(OpportunityStage::Proposal);

    $noOpportunity = Quotation::factory()->create();
    expect($service->closeWonFromQuotation($noOpportunity))->toBeNull()
        ->and($service->closeLostOnQuotationRejection($noOpportunity, 'No opportunity'))->toBeNull();

    $wonOpportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => $customer->getKey(),
        'owner_id' => $actor->getKey(),
        'stage' => OpportunityStage::Negotiation,
    ]);
    $wonQuotation = Quotation::factory()->create([
        'customer_id' => $customer->getKey(),
        'sales_opportunity_id' => $wonOpportunity->getKey(),
        'decided_by' => $actor->getKey(),
        'decision_note' => 'Accepted',
    ]);
    expect($service->closeWonFromQuotation($wonQuotation)?->stage)->toBe(OpportunityStage::ClosedWon)
        ->and($service->closeWonFromQuotation($wonQuotation->refresh())?->stage)->toBe(OpportunityStage::ClosedWon);

    $lostOpportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => $customer->getKey(),
        'owner_id' => $actor->getKey(),
        'stage' => OpportunityStage::Proposal,
    ]);
    $lostQuotation = Quotation::factory()->create([
        'customer_id' => $customer->getKey(),
        'sales_opportunity_id' => $lostOpportunity->getKey(),
        'decided_by' => $actor->getKey(),
    ]);
    expect($service->closeLostOnQuotationRejection($lostQuotation, 'Price')?->stage)
        ->toBe(OpportunityStage::ClosedLost);
});

it('covers campaign recipient response action auth validation and service path', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();

    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-COV-001',
        'name' => 'Coverage campaign',
        'channel' => CampaignChannel::Other,
        'status' => CampaignStatus::Draft,
        'created_by' => $actor->getKey(),
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => User::class,
        'recipient_id' => $actor->getKey(),
        'email' => 'campaign@example.test',
        'phone' => null,
        'send_status' => 'sent',
    ]);

    $manager = new ReflectionClass(CampaignRecipientsRelationManager::class)->newInstanceWithoutConstructor();
    $table = $manager->table(gapBatch2Table());
    $action = $table->getRecordActions()[0];
    $actionClosure = $action->getActionFunction();
    expect($actionClosure)->toBeInstanceOf(Closure::class);

    auth()->logout();
    expect(fn () => $actionClosure($recipient, ['type' => CampaignResponseType::Opened->value]))
        ->toThrow(LogicException::class, 'authenticated CRM user');

    $this->actingAs($actor);
    expect(fn () => $actionClosure($recipient, ['type' => null]))
        ->toThrow(LogicException::class, 'response type is required');

    $actionClosure($recipient, [
        'type' => CampaignResponseType::Unsubscribed->value,
        'notes' => 'Coverage response',
    ]);

    expect($recipient->responses()->count())->toBe(1)
        ->and($recipient->responses()->sole()->type)->toBe(CampaignResponseType::Unsubscribed);
});

it('covers quotation expiry console command with actual expired rows', function (): void {
    $expired = Quotation::factory()->expired()->create();
    $fresh = Quotation::factory()->sent()->create(['expires_at' => today()->addDay()]);

    expect(Artisan::call('sales:quotations:expire'))->toBe(0)
        ->and($expired->refresh()->status->value)->toBe('expired')
        ->and($fresh->refresh()->status->value)->toBe('sent');
});

it('covers inventory reservation action guards reason helper and caught service failure', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();

    $reason = gapBatch2Method(InventoryReservationActions::class, 'reason');
    expect(fn (): mixed => $reason->invoke(null, null))
        ->toThrow(LogicException::class, 'reason is required')
        ->and(fn (): mixed => $reason->invoke(null, []))
        ->toThrow(LogicException::class, 'reason is required')
        ->and($reason->invoke(null, ['reason' => 'Coverage release reason']))->toBe('Coverage release reason');

    $actorMethod = gapBatch2Method(InventoryReservationActions::class, 'actor');
    auth()->logout();
    expect(fn (): mixed => $actorMethod->invoke(null))
        ->toThrow(LogicException::class, 'authenticated inventory reservation actor');

    $this->actingAs($actor);
    expect($actorMethod->invoke(null))->toBe($actor);

    $inactive = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Released,
        'released_at' => now(),
    ]);
    $release = InventoryReservationActions::release();
    $releaseClosure = $release->getActionFunction();
    expect($releaseClosure)->toBeInstanceOf(Closure::class);
    $releaseClosure($inactive, ['reason' => 'Coverage release reason']);

    $bulk = InventoryReservationActions::releaseSelected();
    $bulkClosure = $bulk->getActionFunction();
    expect($bulkClosure)->toBeInstanceOf(Closure::class);
    $bulkClosure(new Collection([$inactive]), ['reason' => 'Coverage release reason']);

    expect($inactive->refresh()->status)->toBe(ReservationStatus::Released);
});

it('covers CreatePayment auth proof and invoice redirect branches', function (): void {
    Gate::before(static fn (): bool => true);
    Storage::fake('local');
    Storage::disk('local')->put('proofs/payment.txt', 'proof');

    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();
    $page = new ReflectionClass(CreatePayment::class)->newInstanceWithoutConstructor();
    $create = gapBatch2Method(CreatePayment::class, 'handleRecordCreation');

    auth()->logout();
    expect(fn (): mixed => $create->invoke($page, []))
        ->toThrow(LogicException::class, 'authenticated sales user');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $payment = $create->invoke($page, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '75.00',
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
        'payment_proof' => 'proofs/payment.txt',
    ]);

    expect($payment->getMedia('payment-proof'))->toHaveCount(1);

    $recordProperty = new ReflectionProperty($page, 'record');
    $recordProperty->setValue($page, $payment);

    request()->query->set('invoice_id', 123);

    $redirect = gapBatch2Method(CreatePayment::class, 'getRedirectUrl')->invoke($page);
    expect($redirect)->toContain('invoice_id=123');
});

it('covers third-party cost relation-manager guards helpers and valid action', function (): void {
    Gate::before(static fn (): bool => true);
    $manager = new ReflectionClass(ThirdPartyCostsRelationManager::class)->newInstanceWithoutConstructor();
    $record = MaintenanceRecord::factory()->create();
    $manager->ownerRecord = $record;

    $requiredInt = gapBatch2Method(ThirdPartyCostsRelationManager::class, 'requiredInt');
    $optionalInt = gapBatch2Method(ThirdPartyCostsRelationManager::class, 'optionalInt');
    $requiredString = gapBatch2Method(ThirdPartyCostsRelationManager::class, 'requiredString');
    $currentActor = gapBatch2Method(ThirdPartyCostsRelationManager::class, 'currentActor');
    $maintenanceRecord = gapBatch2Method(ThirdPartyCostsRelationManager::class, 'maintenanceRecord');

    expect($requiredInt->invoke(null, ['amount' => '12'], 'amount'))->toBe(12)
        ->and(fn (): mixed => $requiredInt->invoke(null, [], 'amount'))->toThrow(LogicException::class)
        ->and($optionalInt->invoke(null, ['supplier' => '5'], 'supplier'))->toBe(5)
        ->and($optionalInt->invoke(null, [], 'supplier'))->toBeNull()
        ->and($requiredString->invoke(null, ['description' => 'Coverage'], 'description'))->toBe('Coverage')
        ->and(fn (): mixed => $requiredString->invoke(null, [], 'description'))->toThrow(LogicException::class)
        ->and($maintenanceRecord->invoke($manager))->toBe($record);

    auth()->logout();
    expect(fn (): mixed => $currentActor->invoke(null))->toThrow(LogicException::class, 'authenticated User');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    expect($currentActor->invoke(null))->toBe($actor);

    $table = $manager->table(gapBatch2Table());
    $action = $table->getHeaderActions()[0];
    $actionFunction = $action->getActionFunction();

    expect($actionFunction)->not->toBeNull();

    $actionFunction([
        'supplier_id' => null,
        'description' => 'Coverage external repair',
        'amount_minor' => '2500',
        'incurred_on' => today()->toDateString(),
    ]);

    expect($record->thirdPartyCosts()->count())->toBe(1)
        ->and($record->thirdPartyCosts()->sole()->amount_minor)->toBe(2500);
});
