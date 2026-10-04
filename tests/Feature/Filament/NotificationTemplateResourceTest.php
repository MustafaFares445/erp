<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationTemplateCatalog;
use App\Services\Notifications\NotificationTemplateEditor;
use App\Services\Notifications\NotificationTemplateRenderer;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PurchaseOrderNotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationTemplate(NotificationEventKey $event, string $locale, NotificationChannel $channel): NotificationTemplate
{
    return NotificationTemplate::query()
        ->where('key', $event->value)
        ->where('locale', $locale)
        ->where('channel', $channel->value)
        ->sole();
}

/** The record the list and edit screens use for a notification. */
function representativeTemplate(NotificationEventKey $event): NotificationTemplate
{
    return NotificationTemplate::query()
        ->whereKey(NotificationTemplate::query()->where('key', $event->value)->min('id'))
        ->sole();
}

beforeEach(function (): void {
    (new NotificationTemplateSeeder)->run();
    (new PurchaseOrderNotificationTemplateSeeder)->run();
    $this->admin = User::factory()->admin()->create();
});

it('lists one business row per notification without channel or raw locale codes', function (): void {
    $invoiceRows = NotificationTemplate::query()->where('key', NotificationEventKey::InvoiceIssued->value)->count();
    expect($invoiceRows)->toBeGreaterThan(2);

    $component = Livewire::actingAs($this->admin)
        ->test(ListNotificationTemplates::class)
        ->set('tableRecordsPerPage', 50)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([representativeTemplate(NotificationEventKey::InvoiceIssued)])
        ->assertCountTableRecords(count(NotificationEventKey::cases()))
        ->assertSee('Invoice issued')
        ->assertSee('Sent when an invoice is issued and becomes available to the customer.')
        ->assertSee('English · العربية')
        ->assertDontSee('invoice.issued')
        ->assertTableColumnExists('notification')
        ->assertTableColumnExists('when_sent')
        ->assertTableColumnExists('languages')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('last_updated');

    $table = $component->instance()->getTable();

    expect($table->getColumn('channel'))->toBeNull()
        ->and($table->getColumn('locale'))->toBeNull()
        ->and($table->getColumn('key'))->toBeNull()
        ->and($table->getFilters())->toBe([]);
});

it('searches notifications by their business name', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ListNotificationTemplates::class)
        ->searchTable('overdue by 30')
        ->assertCanSeeTableRecords([representativeTemplate(NotificationEventKey::InvoiceOverdue30)])
        ->assertCanNotSeeTableRecords([representativeTemplate(NotificationEventKey::InvoiceIssued)]);
});

it('offers only edit and reset to system default and no creation, deletion or separate preview', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);

    $component = Livewire::actingAs($this->admin)->test(ListNotificationTemplates::class);

    foreach (['edit', 'reset_to_default'] as $action) {
        $component->assertTableActionExists($action);
    }

    $component->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('preview')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(NotificationTemplateResource::canCreate())->toBeFalse()
        ->and(NotificationTemplateResource::canDelete($record))->toBeFalse()
        ->and(NotificationTemplateResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(NotificationTemplateResource::getPages()))->toBe(['index', 'edit']);
});

it('does not list custom keys, campaign content or non-system delivery formats', function (): void {
    NotificationTemplate::query()->create([
        'key' => 'campaign.autumn', 'locale' => 'en', 'channel' => NotificationChannel::Mail,
        'subject' => 'x', 'body' => 'x', 'variables' => [], 'is_active' => true,
    ]);
    $sms = NotificationTemplate::query()->create([
        'key' => NotificationEventKey::VisitDue->value, 'locale' => 'en', 'channel' => NotificationChannel::Sms,
        'subject' => null, 'body' => 'sms body', 'variables' => [], 'is_active' => true,
    ]);

    expect(app(NotificationTemplateEditor::class)->rows(NotificationEventKey::VisitDue->value)->contains($sms))->toBeFalse()
        ->and(NotificationTemplateResource::getEloquentQuery()->where('key', 'campaign.autumn')->exists())->toBeFalse();
});

it('no longer registers the notification deliveries and preferences screens', function (): void {
    expect(class_exists('App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource'))->toBeFalse()
        ->and(class_exists('App\Filament\Resources\NotificationPreferences\NotificationPreferenceResource'))->toBeFalse();

    $registered = array_map(
        class_basename(...),
        Filament::getPanel('admin')->getResources(),
    );
    expect($registered)->toContain('NotificationTemplateResource')
        ->not->toContain('NotificationDeliveryResource')
        ->not->toContain('NotificationPreferenceResource');

    $links = collect(AdminModuleRegistry::groups())->pluck('items')->flatten(1)->pluck('label')->all();
    expect($links)->toContain('admin.resources.notification_templates')
        ->not->toContain('admin.resources.notification_deliveries')
        ->not->toContain('admin.resources.notification_preferences');

    $this->actingAs($this->admin);
    $this->get('/admin/notification-deliveries')->assertNotFound();
    $this->get('/admin/notification-preferences')->assertNotFound();
    $this->get('/admin/notification-templates/create')->assertNotFound();
});

it('keeps the delivery and preference tables for the notification backend', function (): void {
    expect(Schema::hasTable('notification_deliveries'))->toBeTrue()
        ->and(Schema::hasTable('notification_preferences'))->toBeTrue();
});

it('edits english and arabic content on a page that shows no technical concepts', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);

    $component = Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->assertSuccessful()
        ->assertSee('Invoice issued')
        ->assertSee('Sent when an invoice is issued')
        ->assertSee('Message content')
        ->assertSee('Preview')
        ->assertSee('Insert information')
        ->assertSee('Invoice number')
        ->assertSee('English')
        ->assertSee('العربية')
        ->assertSeeHtml('dir="rtl"')
        ->assertSeeHtml('data-editor-key="ar_mail"')
        ->assertFormFieldExists('is_active')
        ->assertFormFieldExists('content')
        ->assertFormFieldDoesNotExist('channel')
        ->assertFormFieldDoesNotExist('locale')
        ->assertFormFieldDoesNotExist('key')
        ->assertFormFieldDoesNotExist('variables');

    $component->assertDontSee('{{')
        ->assertDontSee('Available information')
        ->assertDontSee('Variables')
        ->assertDontSee('Channel')
        ->assertDontSee('Locale')
        ->assertDontSee('invoice.issued');

    expect($component->instance()->getCachedHeaderActions())->toHaveCount(1)
        ->and($component->instance()->areFormActionsSticky())->toBeTrue()
        ->and(array_values($component->instance()->getBreadcrumbs()))->toBe(['Notification templates', 'Invoice issued']);

    $component
        ->fillForm([
            'content.en_mail.subject' => 'Your invoice {{ invoice_number }}',
            'content.en_mail.body' => 'Total due {{ total_amount }} for {{ invoice_number }}.',
            'content.ar_mail.subject' => 'فاتورتك {{ invoice_number }}',
            'content.ar_mail.body' => 'الإجمالي {{ total_amount }} للفاتورة {{ invoice_number }}.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(notificationTemplate(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail)->body)
        ->toBe('Total due {{ total_amount }} for {{ invoice_number }}.')
        ->and(notificationTemplate(NotificationEventKey::InvoiceIssued, 'ar', NotificationChannel::Mail)->subject)
        ->toBe('فاتورتك {{ invoice_number }}');
});

it('never shows raw placeholder syntax on any notification edit page', function (): void {
    foreach (NotificationEventKey::cases() as $event) {
        Livewire::actingAs($this->admin)
            ->test(EditNotificationTemplate::class, ['record' => representativeTemplate($event)->getKey()])
            ->assertSuccessful()
            ->assertDontSee('{{')
            ->assertDontSee($event->value);
    }
});

it('ignores any attempt to change channel, locale, key or variables through the request', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    $before = NotificationTemplate::query()->orderBy('id')->get(['id', 'key', 'locale', 'channel', 'variables'])->toArray();

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->set('data.channel', NotificationChannel::Sms->value)
        ->set('data.locale', 'fr')
        ->set('data.key', 'invoice.foo.bar')
        ->set('data.variables', ['injected'])
        ->set('data.content.en_mail.channel', 'sms')
        ->set('data.content.en_mail.variables', ['injected'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(NotificationTemplate::query()->orderBy('id')->get(['id', 'key', 'locale', 'channel', 'variables'])->toArray())->toBe($before);
});

it('rejects wording that uses information the notification does not provide', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    $original = notificationTemplate(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail)->body;

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['content.en_mail.body' => 'Hello {{ customer_secret }} and {{ invoice_number }}'])
        ->call('save')
        ->assertHasFormErrors(['content']);

    expect(notificationTemplate(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail)->body)->toBe($original);
});

it('requires wording for every language and message format', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['content.ar_mail.body' => ''])
        ->call('save')
        ->assertHasFormErrors(['content']);
});

it('deactivating a notification stops it rendering and reactivating restores it', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    $renderer = app(NotificationTemplateRenderer::class);
    $variables = ['invoice_number' => 'INV-1', 'total_amount' => '10.00'];

    expect($renderer->render(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail, $variables)->subject)
        ->toBe('Invoice INV-1');

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save');

    expect(NotificationTemplate::query()->where('key', NotificationEventKey::InvoiceIssued->value)->where('is_active', true)->exists())->toBeFalse()
        ->and(fn () => $renderer->render(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail, $variables))
        ->toThrow(DomainException::class);

    Livewire::actingAs($this->admin)
        ->test(ListNotificationTemplates::class)
        ->set('tableRecordsPerPage', 50)
        ->assertSee('Inactive');

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['is_active' => true])
        ->call('save');

    expect($renderer->render(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail, $variables)->body)
        ->toContain('INV-1');
});

it('reports a partly active notification when legacy rows disagree', function (): void {
    notificationTemplate(NotificationEventKey::InvoiceIssued, 'ar', NotificationChannel::Mail)->update(['is_active' => false]);
    $editor = app(NotificationTemplateEditor::class);

    expect($editor->status(NotificationEventKey::InvoiceIssued->value))->toBe('partial')
        ->and($editor->formState(NotificationEventKey::InvoiceIssued->value)['is_active'])->toBeTrue()
        ->and($editor->lastUpdated(NotificationEventKey::InvoiceIssued->value))->toBeInstanceOf(DateTimeInterface::class);
});

it('resets every language and format to the seeded default and keeps the active state', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    $default = notificationTemplate(NotificationEventKey::InvoiceIssued, 'ar', NotificationChannel::Database)->only(['subject', 'body', 'variables']);

    NotificationTemplate::query()->where('key', NotificationEventKey::InvoiceIssued->value)
        ->update(['subject' => 'Broken', 'body' => 'Broken {{ nope }}', 'is_active' => false]);

    Livewire::actingAs($this->admin)
        ->test(ListNotificationTemplates::class)
        ->callAction(TestAction::make('reset_to_default')->table($record))
        ->assertNotified(__('notification_templates.actions.reset_done'));

    $restored = notificationTemplate(NotificationEventKey::InvoiceIssued, 'ar', NotificationChannel::Database);

    expect($restored->only(['subject', 'body', 'variables']))->toBe($default)
        ->and($restored->is_active)->toBeFalse()
        ->and(notificationTemplate(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail)->body)
        ->toBe('Invoice {{ invoice_number }} has been issued. Total: {{ total_amount }}.');
});

it('resets from the edit page and refreshes the form', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    notificationTemplate(NotificationEventKey::InvoiceIssued, 'en', NotificationChannel::Mail)->update(['body' => 'Custom']);

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->assertFormSet(['content.en_mail.body' => 'Custom'])
        ->callAction('reset_to_default')
        ->assertFormSet(['content.en_mail.body' => 'Invoice {{ invoice_number }} has been issued. Total: {{ total_amount }}.']);
});

it('tells the administrator when no default exists to reset to', function (): void {
    $record = representativeTemplate(NotificationEventKey::InvoiceIssued);
    $editor = app(NotificationTemplateEditor::class);

    NotificationTemplate::query()->where('key', NotificationEventKey::InvoiceIssued->value)->get()
        ->each(fn (NotificationTemplate $row): bool => $row->update(['locale' => 'z'.$row->getKey()]));

    expect($editor->hasDefault(NotificationEventKey::InvoiceIssued->value))->toBeFalse()
        ->and($editor->resetToDefault(NotificationEventKey::InvoiceIssued->value))->toBe(0);

    $component = Livewire::actingAs($this->admin)->test(ListNotificationTemplates::class);
    $action = $component->instance()->getTable()->getAction('reset_to_default');
    $action->record($record)->call();

    $component->assertNotified(__('notification_templates.actions.reset_unavailable'));
});

it('describes the editor in business terms with realistic sample values for each language', function (): void {
    $editor = app(NotificationTemplateEditor::class);
    $config = $editor->editorConfig(NotificationEventKey::InvoiceOverdue30->value);

    expect(array_column($config['languages'], 'label'))->toBe(['English', 'العربية'])
        ->and(array_column($config['languages'], 'rtl'))->toBe([false, true])
        ->and(array_column($config['formats'], 'label'))->toBe(['Email'])
        ->and($config['samples']['en']['invoice_number'])->toBe('INV-2026-00481')
        ->and($config['samples']['en']['days_overdue'])->toBe('30')
        ->and($config['samples']['ar']['amount_due'])->toContain('4,250.00')
        ->and(array_column($config['information']['ar'], 'label'))->toContain('رقم الفاتورة')
        ->and(array_column($config['sections'], 'key'))->toBe(['en_mail', 'ar_mail'])
        ->and(json_encode($config, JSON_UNESCAPED_UNICODE))->not->toContain('{{');

    $invoiceIssued = $editor->editorConfig(NotificationEventKey::InvoiceIssued->value);

    expect(array_column($invoiceIssued['formats'], 'label'))->toBe(['Email', 'In-app']);
});

it('keeps the stored placeholder format that the renderer already understands', function (): void {
    $stored = notificationTemplate(NotificationEventKey::ApprovalPending, 'en', NotificationChannel::Mail);

    $rendered = app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::ApprovalPending,
        'en',
        NotificationChannel::Mail,
        ['document_type' => 'Invoice', 'document_number' => 'INV-1'],
    );

    expect($stored->subject)->toBe('Approval pending: {{ document_number }}')
        ->and($rendered->subject)->toBe('Approval pending: INV-1');
});

it('keeps seeded variable declarations aligned with the catalog for every notification', function (): void {
    $catalog = app(NotificationTemplateCatalog::class);

    foreach (NotificationEventKey::cases() as $event) {
        $rows = NotificationTemplate::query()->where('key', $event->value)->get();

        expect($rows)->not->toBeEmpty("{$event->value} has no seeded template")
            ->and($catalog->name($event->value))->not->toBe('')
            ->and($catalog->description($event->value))->not->toBe('');

        foreach ($rows as $row) {
            expect($catalog->variables($event->value))->toHaveKeys($row->variables)
                ->and(array_keys($catalog->variables($event->value)))->toEqualCanonicalizing($row->variables)
                ->and($catalog->unsupportedVariables($event->value, $row->subject, $row->body))->toBe([]);
        }

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (array_keys($catalog->variables($event->value)) as $variable) {
                expect(__('notification_templates.information.'.$variable))->not->toStartWith('notification_templates.')
                    ->and($catalog->sampleValues($event->value, $locale)[$variable])->not->toStartWith('notification_templates.');
            }
        }
    }
});

it('translates business names into english and arabic', function (): void {
    $catalog = app(NotificationTemplateCatalog::class);

    expect($catalog->name('invoice.overdue.7'))->toBe('Invoice overdue by 7 days')
        ->and($catalog->name('totally.unknown'))->toBe('Totally Unknown')
        ->and($catalog->languageLabel('ar'))->toBe('العربية')
        ->and($catalog->languageLabel('fr'))->toBe('FR');

    app()->setLocale('ar');

    expect($catalog->name('invoice.overdue.7'))->toBe('فاتورة متأخرة 7 أيام')
        ->and($catalog->keysMatching('متأخرة'))->toContain('invoice.overdue.30');
});

it('keeps selecting the delivery channel internally when dispatching', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $dispatcher = app(NotificationDispatcher::class);

    $mail = $dispatcher->dispatch($user, NotificationEventKey::InvoiceIssued, ['invoice_number' => 'INV-9', 'total_amount' => '5.00'], $user, sendNow: true);
    $inApp = $dispatcher->dispatch($user, NotificationEventKey::InvoiceIssued, ['invoice_number' => 'INV-9', 'total_amount' => '5.00'], $user, NotificationChannel::Database, sendNow: true);

    expect($mail->channel)->toBe(NotificationChannel::Mail)
        ->and($inApp->channel)->toBe(NotificationChannel::Database);
});

it('preserves existing template data when the screens are used', function (): void {
    $before = NotificationTemplate::query()->orderBy('id')->get()->map->only(['id', 'key', 'locale', 'channel', 'variables'])->all();
    $record = representativeTemplate(NotificationEventKey::PaymentReceived);

    Livewire::actingAs($this->admin)
        ->test(EditNotificationTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['content.en_mail.body' => 'Payment {{ payment_number }} arrived.'])
        ->call('save');

    expect(NotificationTemplate::query()->orderBy('id')->get()->map->only(['id', 'key', 'locale', 'channel', 'variables'])->all())->toEqual($before)
        ->and(notificationTemplate(NotificationEventKey::PaymentReceived, 'ar', NotificationChannel::Mail)->body)
        ->toBe('تم استلام الدفعة {{ payment_number }} بقيمة {{ amount }} {{ currency }}.');
});
