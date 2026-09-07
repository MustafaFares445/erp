<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit Note {{ $creditNote->credit_note_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { margin: 0 0 16px; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; }
        .meta { margin-bottom: 18px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .lines th, .lines td { border: 1px solid #d1d5db; padding: 7px; text-align: left; }
        .lines th { background: #f3f4f6; }
        .number { text-align: right; }
        .totals { margin-top: 16px; }
        .totals td { padding: 4px 7px; }
        .totals .label { text-align: right; font-weight: 600; }
    </style>
</head>
<body>
<h1>Credit Note {{ $creditNote->credit_note_number }}</h1>
<table class="meta">
    <tr><td><strong>Customer</strong></td><td>{{ $creditNote->customer?->company_name }}</td></tr>
    <tr><td><strong>Issue date</strong></td><td>{{ $creditNote->issue_date?->format('Y-m-d') }}</td></tr>
    <tr><td><strong>Invoice</strong></td><td>{{ $creditNote->invoice?->invoice_number ?? '—' }}</td></tr>
    <tr><td><strong>Reason</strong></td><td>{{ $creditNote->reason_category?->label() }} — {{ $creditNote->reason }}</td></tr>
</table>
<table class="lines">
    <thead>
    <tr><th>Description</th><th>Quantity</th><th>Unit price</th><th>Tax</th><th>Line total</th></tr>
    </thead>
    <tbody>
    @foreach ($creditNote->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="number">{{ $line->quantity }}</td>
            <td class="number">{{ number_format((float) $line->unit_price, 2) }}</td>
            <td class="number">{{ number_format((float) $line->tax_amount, 2) }}</td>
            <td class="number">{{ number_format((float) $line->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<table class="totals">
    <tr><td class="label">Subtotal</td><td class="number">{{ number_format((float) $creditNote->subtotal, 2) }}</td></tr>
    <tr><td class="label">Tax total</td><td class="number">{{ number_format((float) $creditNote->tax_total, 2) }}</td></tr>
    <tr><td class="label">Grand total</td><td class="number">{{ number_format((float) $creditNote->grand_total, 2) }}</td></tr>
</table>
</body>
</html>
