<?php

declare(strict_types=1);

use App\Data\Crm\CampaignData;
use App\Enums\CampaignChannel;
use App\Enums\ExpenseStatus;
use App\Enums\NotificationChannel;
use App\Enums\SupplierPaymentStatus;
use App\Models\Campaign;
use App\Models\Expense;
use App\Models\NotificationTemplate;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Crm\CampaignService;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function coverage103Template(string $key, NotificationChannel $channel): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'key' => $key,
        'locale' => 'en',
        'channel' => $channel,
        'subject' => $channel === NotificationChannel::Mail ? 'Subject' : null,
        'body' => 'Coverage body',
        'variables' => [],
        'is_active' => true,
    ]);
}

it('covers every campaign delivery-channel mapping branch', function (): void {
    $service = app(CampaignService::class);
    $deliverable = new ReflectionMethod(CampaignService::class, 'assertCampaignTemplateDeliverable');
    $matches = new ReflectionMethod(CampaignService::class, 'assertTemplateMatchesChannel');

    $sms = coverage103Template('coverage103.sms', NotificationChannel::Sms);
    $whatsapp = coverage103Template('coverage103.whatsapp', NotificationChannel::Whatsapp);
    $mail = coverage103Template('coverage103.mail', NotificationChannel::Mail);

    $smsCampaign = new Campaign(['channel' => CampaignChannel::Sms]);
    $smsCampaign->setRelation('contentTemplate', $sms);
    $deliverable->invoke($service, $smsCampaign);

    $waCampaign = new Campaign(['channel' => CampaignChannel::Whatsapp]);
    $waCampaign->setRelation('contentTemplate', $whatsapp);
    $deliverable->invoke($service, $waCampaign);

    $eventCampaign = new Campaign(['channel' => CampaignChannel::Event]);
    $eventCampaign->setRelation('contentTemplate', $mail);
    expect(fn () => $deliverable->invoke($service, $eventCampaign))
        ->toThrow(DomainException::class, 'no longer matches');

    $matches->invoke($service, new CampaignData(
        name: 'SMS Coverage',
        channel: CampaignChannel::Sms,
        contentTemplateId: $sms->id,
    ));

    $matches->invoke($service, new CampaignData(
        name: 'WhatsApp Coverage',
        channel: CampaignChannel::Whatsapp,
        contentTemplateId: $whatsapp->id,
    ));

    expect(fn () => $matches->invoke($service, new CampaignData(
        name: 'Event Coverage',
        channel: CampaignChannel::Event,
        contentTemplateId: $mail->id,
    )))->toThrow(DomainException::class, 'does not match');
});

it('covers service quotation description quantity and price validation branches', function (): void {
    $service = app(QuotationService::class);
    $method = new ReflectionMethod(QuotationService::class, 'syncLines');

    $quotation = new Quotation;
    $quotation->setRelation('customer', null);

    $settings = new SalesSetting;
    $settings->forceFill(['default_tax_percent' => '5.00']);

    expect(fn () => $method->invoke($service, $quotation, [[
        'product_variant_id' => null,
        'quantity' => '0',
        'description' => 'Service work',
        'unit_price' => '10.00',
    ]], $settings))->toThrow(ValidationException::class, 'description and positive quantity');

    expect(fn () => $method->invoke($service, $quotation, [[
        'product_variant_id' => null,
        'quantity' => '1',
        'description' => 'Service work',
        'unit_price' => '-1',
    ]], $settings))->toThrow(ValidationException::class, 'non-negative unit price');
});

it('covers accounts-payable statement supplier date and zero-payment skip branches', function (): void {
    $target = Supplier::factory()->create();
    $other = Supplier::factory()->create();

    Expense::factory()->create([
        'supplier_id' => $other->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-01-15',
        'due_date' => '2026-01-20',
        'total_amount' => '30.00',
        'amount_paid' => '0.00',
    ]);

    Expense::factory()->create([
        'supplier_id' => $target->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-01-01',
        'due_date' => '2026-01-05',
        'total_amount' => '40.00',
        'amount_paid' => '0.00',
    ]);

    Expense::factory()->create([
        'supplier_id' => $target->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-01-15',
        'due_date' => '2026-01-20',
        'payment_date' => '2026-01-20',
        'total_amount' => '50.00',
        'amount_paid' => '0.00',
    ]);

    SupplierPayment::factory()->create([
        'supplier_id' => $target->id,
        'status' => SupplierPaymentStatus::Paid,
        'payment_date' => '2026-01-20',
        'amount' => '25.00',
    ]);

    $statement = app(AccountsPayableService::class)->statement(
        $target,
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-31'),
    );

    expect(collect($statement['entries'])->where('type', 'supplier_payment'))->toBeEmpty()
        ->and(collect($statement['entries'])->where('type', 'expense_payment'))->toBeEmpty()
        ->and(collect($statement['entries'])->where('type', 'expense'))->toHaveCount(1);
});
