<?php

declare(strict_types=1);

use App\Enums\SalesPermission;

describe('SalesPermission', function (): void {
    it('declares the twenty-seven catalogue entries, each namespaced under sales', function (): void {
        expect(SalesPermission::values())->toHaveCount(27);

        foreach (SalesPermission::values() as $permission) {
            expect($permission)->toStartWith('sales.');
        }
    });

    it('has no duplicate values', function (): void {
        expect(array_unique(SalesPermission::values()))->toHaveCount(27);
    });

    it('keeps the six load-bearing separations as distinct permissions (FR-072)', function (): void {
        $distinct = [
            SalesPermission::QuotationManage->value,
            SalesPermission::QuotationDecide->value,
            SalesPermission::QuotationConvert->value,
            SalesPermission::InvoiceManage->value,
            SalesPermission::InvoiceIssue->value,
            SalesPermission::InvoiceSend->value,
            SalesPermission::PaymentRecord->value,
            SalesPermission::PaymentReverse->value,
            SalesPermission::CreditNoteManage->value,
            SalesPermission::CreditNoteConfirm->value,
            SalesPermission::CreditNoteReverse->value,
        ];

        expect(array_unique($distinct))->toHaveCount(11);
    });

    it('grants delivery-note view but no ability to mutate stock through it (FR-034)', function (): void {
        // The permission catalogue has no delivery "complete"/"dispatch"/"cancel"
        // ability at all — those are InventoryPermission's, unchanged, because
        // this surface must never become a second authorization path to a
        // stock mutation.
        $values = SalesPermission::values();

        expect($values)->toContain(SalesPermission::DeliveryNoteView->value);

        foreach ($values as $value) {
            expect($value)->not->toContain('delivery-note.manage')
                ->and($value)->not->toContain('delivery-note.complete')
                ->and($value)->not->toContain('delivery-note.dispatch');
        }
    });
});
