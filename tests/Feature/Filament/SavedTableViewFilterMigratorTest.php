<?php

declare(strict_types=1);

use App\Models\SavedTableView;
use App\Models\User;
use App\Services\UiPreferences\SavedTableViewFilterMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $filters */
function legacyView(string $pageKey, array $filters): SavedTableView
{
    return SavedTableView::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'page_key' => $pageKey,
        'name' => 'Legacy',
        'state' => ['tableSearch' => 'abc', 'tableFilters' => $filters],
        'state_version' => 1,
    ]);
}

it('turns removed select and relationship filters into query builder rules', function (): void {
    $view = legacyView('purchasing.purchase-orders', [
        'supplier_id' => ['value' => '7'],
        'status' => ['values' => ['accepted', 'sent']],
        'currency_code' => ['value' => 'AED'],
        'overdue' => ['isActive' => true],
    ]);

    expect(resolve(SavedTableViewFilterMigrator::class)->migrate())->toBe(1);

    $state = $view->refresh()->state;

    expect($state['tableSearch'])->toBe('abc')
        ->and($state['tableFilters'])->toHaveKeys(['overdue', 'queryBuilder'])
        ->not->toHaveKeys(['supplier_id', 'status', 'currency_code'])
        ->and($state['tableFilters']['queryBuilder']['rules'])->toBe([
            'legacy-supplier_id' => ['type' => 'supplier', 'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => ['7']]]],
            'legacy-status' => ['type' => 'status', 'data' => ['operator' => 'is', 'settings' => ['values' => ['accepted', 'sent']]]],
            'legacy-currency_code' => ['type' => 'currency_code', 'data' => ['operator' => 'equals', 'settings' => ['text' => 'AED']]],
        ]);
});

it('turns date ranges into inclusive after and before rules', function (): void {
    $view = legacyView('sales.invoices', [
        'issue_date_between' => ['from' => '2026-01-01', 'until' => null],
        'due_date_between' => ['from' => 'not a date', 'until' => '2026-03-31 10:00:00'],
    ]);

    resolve(SavedTableViewFilterMigrator::class)->migrate();

    expect($view->refresh()->state['tableFilters']['queryBuilder']['rules'])->toBe([
        'legacy-issue_date_between-from' => ['type' => 'invoice_date', 'data' => ['operator' => 'isAfter', 'settings' => ['mode' => 'absolute', 'date' => '2026-01-01']]],
        'legacy-due_date_between-until' => ['type' => 'due_date', 'data' => ['operator' => 'isBefore', 'settings' => ['mode' => 'absolute', 'date' => '2026-03-31']]],
    ]);
});

it('maps ternary filters to true and inverse-true rules and ignores unset ones', function (): void {
    $active = legacyView('crm.customers', ['is_active' => ['value' => true]]);
    $inactive = legacyView('crm.customers', ['is_active' => ['value' => '0']]);
    $unset = legacyView('crm.customers', ['is_active' => ['value' => null], 'approval_status' => ['value' => '']]);
    $garbage = legacyView('crm.customers', ['is_active' => ['value' => 'maybe'], 'approval_status' => 'pending']);

    resolve(SavedTableViewFilterMigrator::class)->migrate();

    expect($active->refresh()->state['tableFilters']['queryBuilder']['rules']['legacy-is_active']['data']['operator'])->toBe('isTrue')
        ->and($inactive->refresh()->state['tableFilters']['queryBuilder']['rules']['legacy-is_active']['data']['operator'])->toBe('isTrue.inverse')
        ->and($unset->refresh()->state['tableFilters'])->toBe([])
        ->and($garbage->refresh()->state['tableFilters'])->toBe([]);
});

it('applies the operations mapping to typed operation pages and keeps existing rules', function (): void {
    $view = legacyView('inventory.operations.receipt', [
        'stage' => ['value' => 'draft'],
        'queryBuilder' => ['rules' => ['kept' => ['type' => 'operation_number', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'R']]]]],
    ]);

    resolve(SavedTableViewFilterMigrator::class)->migrate();

    expect(array_keys($view->refresh()->state['tableFilters']['queryBuilder']['rules']))->toBe(['kept', 'legacy-stage']);
});

it('leaves unrelated, already converted and filterless views alone', function (): void {
    $unmapped = legacyView('accounting.journals', ['status' => ['value' => 'posted']]);
    $converted = legacyView('support.tickets', ['response_breached' => ['isActive' => true]]);
    $noFilters = SavedTableView::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'page_key' => 'support.tickets',
        'name' => 'No filters',
        'state' => ['tableSearch' => 'x'],
        'state_version' => 1,
    ]);

    expect(resolve(SavedTableViewFilterMigrator::class)->migrate())->toBe(0)
        ->and($unmapped->refresh()->state['tableFilters'])->toBe(['status' => ['value' => 'posted']])
        ->and($converted->refresh()->state['tableFilters'])->toBe(['response_breached' => ['isActive' => true]])
        ->and($noFilters->refresh()->state)->toBe(['tableSearch' => 'x']);
});
