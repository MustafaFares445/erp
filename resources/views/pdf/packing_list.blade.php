<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Packing List {{ $delivery->operation_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { margin: 0 0 16px; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; }
        .meta { margin-bottom: 18px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .lines th, .lines td { border: 1px solid #d1d5db; padding: 7px; text-align: left; }
        .lines th { background: #f3f4f6; }
        .number { text-align: right; }
    </style>
</head>
<body>
<h1>Packing List {{ $delivery->operation_number }}</h1>
<table class="meta">
    <tr><td><strong>Customer</strong></td><td>{{ $delivery->customer?->company_name ?? '—' }}</td></tr>
    <tr><td><strong>Source warehouse</strong></td><td>{{ $delivery->sourceWarehouse?->name ?? '—' }}</td></tr>
    <tr><td><strong>Scheduled</strong></td><td>{{ $delivery->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
</table>
<table class="lines">
    <thead>
    <tr><th>SKU</th><th>Product</th><th>Quantity</th><th>Unit</th><th>Lot / serial</th></tr>
    </thead>
    <tbody>
    @foreach ($delivery->lines as $line)
        <tr>
            <td>{{ $line->productVariant?->sku ?? '—' }}</td>
            <td>{{ $line->productVariant?->name ?? '—' }}</td>
            <td class="number">{{ $line->quantity }}</td>
            <td>{{ $line->unit?->name ?? '—' }}</td>
            <td>{{ $line->lot?->lot_number ?? $line->serializedUnit?->serial_number ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
