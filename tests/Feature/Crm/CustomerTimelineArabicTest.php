<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Filament\Resources\Customers\Pages\CustomerTimeline;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\CustomerTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Release QA 2026-10-10 — the customer 360 timeline rendered entirely in
 * English for an Arabic-locale user: section headings, filters, type chips,
 * event titles/details, and date-group headings were hard-coded or formatted
 * with English-only Carbon helpers.
 */
function arabicTimelineActor(): User
{
    $actor = User::factory()->admin()->create(['locale' => 'ar']);

    foreach ([
        SalesPermission::QuotationView,
        SalesPermission::OrderView,
        SalesPermission::InvoiceView,
        SalesPermission::PaymentView,
        SupportPermission::TicketView,
        SupportPermission::MaintenanceRequestView,
        CrmPermission::InteractionView,
        CrmPermission::CustomerView,
        EmployeePermission::VisitView,
    ] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    return $actor;
}

it('renders the customer timeline page in Arabic with translated headings, titles, and dates (Release QA 2026-10-10)', function (): void {
    $this->travelTo('2026-10-10 10:00:00');

    $customer = CustomerProfile::factory()->create();
    $olderInvoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => '2026-10-05',
        'status' => 'issued',
    ]);
    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => '2026-10-10',
        'status' => 'issued',
    ]);

    app()->setLocale('ar');

    Livewire::actingAs(arabicTimelineActor())
        ->test(CustomerTimeline::class, ['record' => $customer->getKey()])
        ->assertSuccessful()
        ->assertSeeText('الملخص')
        ->assertSeeText('إجمالي المفوتر')
        ->assertSeeText('عوامل التصفية')
        ->assertSeeText('مسح عوامل التصفية')
        ->assertSeeText('سجل الأحداث')
        ->assertSeeText('طلب صيانة')
        ->assertSeeText('اليوم')
        ->assertSeeText('الفاتورة '.$olderInvoice->invoice_number)
        ->assertSeeText('اثنين 5 أكتوبر 2026')
        ->assertSeeText($customer->company_name.' — الخط الزمني')
        ->assertDontSeeText('Lifetime invoiced')
        ->assertDontSeeText('Clear filters')
        ->assertDontSeeText('Maintenance Record')
        ->assertDontSeeText('Invoice '.$olderInvoice->invoice_number)
        ->assertDontSeeText('Mon 5 Oct 2026')
        ->assertDontSeeText('— Timeline');
});

it('builds Arabic event titles and details from every translated timeline source (Release QA 2026-10-10)', function (): void {
    $customer = CustomerProfile::factory()->create();
    $quotation = Quotation::factory()->create(['customer_id' => $customer->getKey()]);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'priority' => 'high']);
    CustomerVisit::factory()->create(['customer_id' => $customer->getKey()]);
    $maintenance = MaintenanceRecord::factory()->create(['customer_id' => $customer->getKey()]);

    app()->setLocale('ar');

    $events = collect(app(CustomerTimelineService::class)->timeline($customer, arabicTimelineActor(), null, null, showActivity: false)->items())
        ->keyBy('type');

    expect($events['quotation']->title)->toBe('عرض السعر '.$quotation->quotation_number)
        ->and($events['ticket']->title)->toBe('تذكرة الدعم '.$ticket->ticket_number.' — '.$ticket->title)
        ->and($events['ticket']->detail)->toStartWith('أولوية عالية')
        ->and($events['visit']->title)->toStartWith('زيارة — ')
        ->and($events['maintenance_record']->title)->toStartWith('طلب الصيانة رقم '.$maintenance->getKey().' — ')
        ->and($events['maintenance_record']->detail)->toContain('الضمان: ')
        ->and($events['maintenance_record']->detail)->not->toContain('warranty');
});
