<?php

declare(strict_types=1);

use App\Enums\PeriodCloseCheck;

it('has exactly seven checks', function (): void {
    expect(PeriodCloseCheck::cases())->toHaveCount(7);
});

it('classifies the five figure-reconciliation checks as mandatory', function (): void {
    expect(PeriodCloseCheck::TrialBalanceBalances->isMandatory())->toBeTrue()
        ->and(PeriodCloseCheck::ReceivablesAgreeToControlAccount->isMandatory())->toBeTrue()
        ->and(PeriodCloseCheck::PayablesAgreeToControlAccount->isMandatory())->toBeTrue()
        ->and(PeriodCloseCheck::TaxRegisterAgreesToTaxAccounts->isMandatory())->toBeTrue()
        ->and(PeriodCloseCheck::StockLedgerReconciles->isMandatory())->toBeTrue();
});

it('classifies the two housekeeping checks as advisory', function (): void {
    expect(PeriodCloseCheck::NoDraftJournalEntriesInPeriod->isMandatory())->toBeFalse()
        ->and(PeriodCloseCheck::NoUnpostedPaymentsInPeriod->isMandatory())->toBeFalse();
});

it('reports exactly five mandatory and two advisory checks overall', function (): void {
    $mandatory = array_filter(PeriodCloseCheck::cases(), fn (PeriodCloseCheck $check): bool => $check->isMandatory());
    $advisory = array_filter(PeriodCloseCheck::cases(), fn (PeriodCloseCheck $check): bool => ! $check->isMandatory());

    expect($mandatory)->toHaveCount(5)
        ->and($advisory)->toHaveCount(2)
        ->and(PeriodCloseCheck::mandatory())->toHaveCount(5);
});

it('labels each check from the admin translation catalogue', function (): void {
    foreach (PeriodCloseCheck::cases() as $check) {
        expect($check->label())->toBeString()->not->toBe('');
    }
});
