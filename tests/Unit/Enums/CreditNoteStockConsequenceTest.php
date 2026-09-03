<?php

declare(strict_types=1);

use App\Enums\CreditNoteStockConsequence;

it('requires an inventory return link only when goods were returned', function (): void {
    expect(CreditNoteStockConsequence::GoodsReturned->requiresReturnLink())->toBeTrue()
        ->and(CreditNoteStockConsequence::CustomerRetained->requiresReturnLink())->toBeFalse()
        ->and(CreditNoteStockConsequence::NotApplicable->requiresReturnLink())->toBeFalse();
});
