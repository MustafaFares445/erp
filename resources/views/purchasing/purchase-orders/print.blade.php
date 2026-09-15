<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('admin.purchasing.print.title') }} {{ $purchaseOrder->purchase_order_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; color: #111827; font-size: 13px; }
        h1, h2 { margin: 0 0 12px; }
        h2 { margin-top: 24px; font-size: 16px; }
        .toolbar { display: flex; justify-content: flex-end; margin-bottom: 20px; }
        .toolbar button { padding: 8px 14px; border: 1px solid #d1d5db; background: white; border-radius: 6px; cursor: pointer; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .field { border: 1px solid #e5e7eb; padding: 10px; border-radius: 6px; }
        .label { display: block; color: #6b7280; font-size: 11px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: start; vertical-align: top; }
        th { background: #f3f4f6; font-size: 11px; }
        .number { text-align: end; white-space: nowrap; }
        .total { margin-top: 12px; text-align: end; font-size: 16px; font-weight: 700; }
        .muted { color: #6b7280; }
        @media print { .toolbar { display: none; } body { margin: 12mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">{{ __('admin.purchasing.print.print') }}</button></div>
<h1>{{ __('admin.purchasing.print.title') }}</h1>
<div class="grid">
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.purchase_order_number') }}</span>{{ $purchaseOrder->purchase_order_number }}</div>
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.supplier') }}</span>{{ $purchaseOrder->supplier->name }}</div>
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.currency_code') }}</span>{{ $purchaseOrder->currency_code }}</div>
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.ordered_at') }}</span>{{ $purchaseOrder->ordered_at?->toDateString() }}</div>
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.expected_at') }}</span>{{ $purchaseOrder->expected_at?->toDateString() ?? '—' }}</div>
    <div class="field"><span class="label">{{ __('admin.purchasing.fields.status') }}</span>{{ $purchaseOrder->status->label() }}</div>
</div>

<h2>{{ __('admin.purchasing.print.supplier_details') }}</h2>
<div class="grid">
    <div class="field"><span class="label">{{ __('admin.common.email') }}</span>{{ $purchaseOrder->supplier->email ?? '—' }}</div>
    <div class="field"><span class="label">{{ __('admin.common.phone') }}</span>{{ $purchaseOrder->supplier->phone ?? '—' }}</div>
    <div class="field"><span class="label">{{ __('admin.common.address') }}</span>{{ $purchaseOrder->supplier->address ?? '—' }}</div>
</div>

<h2>{{ __('admin.purchasing.fields.lines') }}</h2>
<table>
    <thead><tr>
        <th>{{ __('admin.purchasing.fields.product') }}</th>
        <th>{{ __('admin.purchasing.fields.product_variant') }}</th>
        <th>{{ __('admin.purchasing.fields.brand') }}</th>
        <th>{{ __('admin.purchasing.fields.supplier_reference') }}</th>
        <th>{{ __('admin.purchasing.fields.unit') }}</th>
        <th>{{ __('admin.purchasing.fields.quantity') }}</th>
        <th>{{ __('admin.purchasing.fields.unit_cost') }}</th>
        <th>{{ __('admin.purchasing.fields.line_total') }}</th>
    </tr></thead>
    <tbody>
    @foreach ($purchaseOrder->lines as $line)
        @php($variant = $line->productVariant)
        @php($product = $variant->product)
        @php($reference = $line->supplierProductReference)
        <tr>
            <td>{{ $product?->name ?? '—' }}</td>
            <td>{{ $variant->name }} <span class="muted">({{ $variant->sku }})</span></td>
            <td>{{ $product?->brand?->name ?? '—' }}</td>
            <td>
                {{ $reference?->supplier_name ?? '—' }}
                @if ($reference?->supplier_item_number)
                    <div class="muted">{{ $reference->supplier_item_number }}</div>
                @endif
            </td>
            <td>{{ $line->unit->name }}</td>
            <td class="number">{{ number_format((float) $line->quantity_ordered, 3) }}</td>
            <td class="number">{{ $purchaseOrder->currency_code }} {{ number_format((float) $line->unit_cost, 2) }}</td>
            <td class="number">{{ $purchaseOrder->currency_code }} {{ number_format((float) $line->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="total">{{ __('admin.purchasing.fields.total_amount') }}: {{ $purchaseOrder->currency_code }} {{ number_format((float) $purchaseOrder->total_amount, 2) }}</div>

@if ($purchaseOrder->notes)
    <h2>{{ __('admin.purchasing.fields.notes') }}</h2>
    <p>{{ $purchaseOrder->notes }}</p>
@endif
<h2>{{ __('admin.purchasing.print.response_history') }}</h2>
<table>
    <thead><tr>
        <th>{{ __('admin.purchasing.fields.created_at') }}</th>
        <th>{{ __('admin.purchasing.fields.status') }}</th>
        <th>{{ __('admin.purchasing.fields.promised_at') }}</th>
        <th>{{ __('admin.purchasing.fields.confirmed_by') }}</th>
        <th>{{ __('admin.purchasing.fields.notes') }}</th>
    </tr></thead>
    <tbody>
    @forelse ($purchaseOrder->confirmations->sortBy('created_at') as $confirmation)
        <tr>
            <td>{{ $confirmation->created_at?->toDateTimeString() }}</td>
            <td>{{ $confirmation->confirmation_status->label() }}</td>
            <td>{{ $confirmation->promised_at?->toDateString() ?? '—' }}</td>
            <td>{{ $confirmation->confirmedBy?->name ?? '—' }}</td>
            <td>{{ $confirmation->notes ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">{{ __('admin.purchasing.print.no_responses') }}</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
