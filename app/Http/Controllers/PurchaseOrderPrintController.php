<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class PurchaseOrderPrintController
{
    public function __invoke(Request $request, PurchaseOrder $purchaseOrder): View
    {
        abort_unless($request->user()?->can('view', $purchaseOrder) ?? false, 403);

        $purchaseOrder->load([
            'supplier',
            'lines.productVariant.product.brand',
            'lines.unit',
            'lines.supplierProductReference',
            'confirmations.confirmedBy',
            'confirmations.items.productVariant.product',
        ]);

        return view('purchasing.purchase-orders.print', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }
}
